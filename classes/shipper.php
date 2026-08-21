<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Shipper orchestration for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Sweeps closed buffer files and ships them to object storage.
 *
 * Invoked by the ship_events scheduled task. Closed buffer files are coalesced
 * into batches, and each batch is gzip-compressed and PUT to S3 as ONE object;
 * the local files are deleted only on a 2xx response, so any failure simply
 * retries on the next run.
 */
class shipper {
    /**
     * Safety cap on files shipped per run.
     *
     * ship_events runs every minute (db/tasks.php), so this is also a DRAIN RATE:
     * above this many closed files per minute the backlog grows monotonically, and it
     * does not grow forever — enforce_disk_cap() begins evicting oldest-first, and
     * what it evicts is `.closed`, i.e. records that have not been shipped yet. So a
     * sustained burst over the drain rate becomes data loss rather than delay. That
     * is why the number is set from measurement and not from taste.
     *
     * Measured on a real run per file, against a seeded backlog of 5,370-byte files
     * (the size of an actual live per-worker window):
     *
     *     files      bytes   elapsed   per file   objects
     *       200    1.1 MiB     0.06s    0.30 ms         1
     *     1,000    5.4 MiB     0.18s    0.18 ms         1
     *     2,000   10.7 MiB     0.35s    0.17 ms         2
     *     5,000   26.8 MiB     0.86s    0.17 ms         4
     *
     * Two things that table shows, both worth knowing before changing this number:
     *
     *  - Cost per file is FLAT, so a run is linear in the cap with no cliff.
     *  - Object count is driven by BYTES, not by file count — MAX_BATCH_BYTES decides
     *    it. So raising this cap does not multiply round-trips, which is what made 200
     *    expensive to lift before the files-to-one-object batching landed (200 files
     *    was 200 PUTs, ~13s of the cron minute at the ~65 ms Hetzner->OCI RTT).
     *
     * Peak memory is likewise independent of this cap: batches are shipped one at a
     * time and each is bounded by MAX_BATCH_BYTES.
     *
     * 2,000 costs ~0.35s measured, ~0.5s allowing for a remote endpoint's round-trips
     * — under 1% of the cron minute — while giving ~30x the highest rate ever observed
     * (67 files/min) and ~200x the busiest live tenant's steady state (~10/min). It
     * also holds up if the file-size assumption is an order of magnitude out: at 54 KB
     * per file, 2,000 files is 107 MiB in 13 objects, still ~1.2s.
     *
     * It stays a bounded cap rather than becoming unlimited because its job is to
     * limit the blast radius of a pathological buffer — the per-run BYTE volume has no
     * cap of its own, so with large files the only remaining bound is maxbuffergb.
     */
    const MAX_FILES_PER_RUN = 2000;

    /**
     * Cap on the accumulated PLAINTEXT bytes coalesced into a single object.
     *
     * Deliberately at the low end. s3_client::put() takes the body as a string and
     * hands it to Moodle's \curl, so a batch is necessarily resident in memory here;
     * downstream, the middleware does not stream either, and its canonical envelope
     * keeps the source record verbatim, so an object costs roughly 2x its
     * decompressed size there — multiplied by the middleware's per-tenant
     * concurrency. 8 MiB keeps the worst case comfortable on both sides while being
     * orders of magnitude above what a real ship run produces (a 120s window across
     * every worker on the measured tenant is ~27 KB); it only ever binds during a
     * backfill burst.
     *
     * At ~1 KB/record this is also well inside the middleware's per-statement
     * dedupe-key chunk, so a batch never approaches Postgres' bind-parameter cap.
     */
    const MAX_BATCH_BYTES = 8388608;

    /**
     * Run one shipping cycle.
     */
    public static function run(): void {
        if (!config::enabled()) {
            mtrace('local_intellistream: disabled — nothing shipped.');
            return;
        }
        $dir = config::buffer_dir();

        // Drain every directory that may hold our records, not only the current
        // one. `bufferdir` is admin-editable and buffer_dir() also falls back to
        // the default whenever a stored value stops validating, so the directory
        // can move while un-shipped records are sitting in the old one. Shipping
        // from buffer_dir() alone meant those records were never collected by
        // anything, while this task went on reporting `ok` about the new
        // directory. config::buffer_dirs() returns the current directory first,
        // then any earlier one still on record.
        //
        // Only directories that exist are kept, and if that leaves none there is
        // nothing to do. The current one may legitimately be absent here — an
        // administrator who repoints the buffer creates no directory by doing so,
        // it appears on the next capture — and that must not stop the previous
        // one from draining, which is precisely the case that loses data.
        $dirs = [];
        foreach (array_merge([$dir], config::previous_buffer_dirs()) as $candidate) {
            if ($candidate !== '' && is_dir($candidate)) {
                $dirs[] = $candidate;
            }
        }
        if ($dirs === []) {
            // This set is the current directory AND every tracked previous one, so
            // an empty result means all of them are inaccessible — not just current.
            // Name the previous count too: on a site that has just relocated its
            // buffer (the case this whole path exists for), the records worth
            // recovering are in the previous directories, and an admin told only to
            // "check the current dir" would look in the wrong place.
            $prevcount = count(config::previous_buffer_dirs());
            mtrace('local_intellistream: buffer dir "' . $dir . '" not accessible'
                . ($prevcount > 0
                    ? ' (nor ' . $prevcount . ' previous buffer director'
                        . ($prevcount === 1 ? 'y' : 'ies') . ' this plugin still tracks)'
                    : '')
                . ' — nothing to ship. If it exists on disk, PHP cannot see it: check '
                . 'open_basedir and read permission for the web user.');
            return;
        }

        // Promote buffer files orphaned by dead workers before shipping. A
        // previous directory's files are orphaned by definition — nothing writes
        // there any more — but they still go through the same liveness rules
        // rather than being promoted on sight, because on a cluster a node that
        // has not yet re-read the changed setting can still be appending to one.
        $unreadable = [];
        $unowned = [];
        $unsafe = [];
        $foreign = 0;
        foreach ($dirs as $target) {
            $swept = self::sweep_stale($target);
            $foreign += $swept['foreign'];
            $unreadable = array_merge($unreadable, $swept['unreadable']);
            $unowned = array_merge($unowned, $swept['unowned']);
            $unsafe = array_merge($unsafe, buffer::unsafe_files($target));
        }
        self::record_foreign_hosts($foreign);
        self::record_odd_files($unreadable, $unowned, $unsafe);

        // Stop tracking a previous directory once nothing of ours is left in it, so
        // the record converges on just the directory in use instead of growing.
        // Deliberately NOT rmdir()ing it as well: unlike the uninstall purge, this
        // runs on a live site where the path was chosen by an administrator and may
        // be theirs to keep. An empty directory left behind is visible and
        // harmless; removing one that somebody is still looking at is not.
        //
        // ABOVE the object-storage and load-gate early returns on purpose. This
        // only forgets directories that are already empty — it ships nothing and
        // deletes nothing — and a PULL-ONLY site returns below, where its records
        // are drained by pull_export rather than from here. Pruning after the ship
        // loop meant such a site never converged: the directory the puller had
        // emptied stayed on the record for ever.
        //
        // Iterates previous_tracked_dirs(), NOT previous_buffer_dirs(): the latter
        // hides a directory that no longer validates, and such an entry could then
        // never be forgotten — it would sit in the tracked list for ever, holding
        // a MAX_BUFFER_DIRS slot. Using the tracked list lets an empty one be
        // dropped whether or not it still validates, while a non-empty one is kept
        // so own_files() and the uninstall purge keep reaching its records.
        foreach (config::previous_tracked_dirs() as $previous) {
            $remaining = buffer::entry_count($previous);
            if ($remaining === 0) {
                config::forget_buffer_dir($previous);
                mtrace('local_intellistream: previous buffer directory "' . $previous
                    . '" is drained — no longer tracking it.');
                continue;
            }
            // A directory that still validates drains from here or via the pull
            // integration. One that no longer validates (moodledata moved, or a
            // rule tightened) is deliberately not read back off the host, so its
            // records are reachable only for privacy erasure and the uninstall
            // purge until an admin restores a valid path — say so rather than
            // imply it will drain on its own.
            $stuck = config::buffer_dir_problem($previous) !== '';
            mtrace('local_intellistream: ' . $remaining . ' file(s) still in a previous buffer '
                . 'directory "' . $previous . '". ' . ($stuck
                    ? 'It no longer passes the buffer-directory checks (moodledata moved, or a '
                        . 'stricter rule), so it is not read back off the host; restore a valid path '
                        . 'to let these ship. They remain available to privacy erasure and uninstall.'
                    : 'This is a directory the buffer used to be pointed at; its records are '
                        . 'collected from here, or by the pull integration, until it is empty.'));
        }

        $s3 = s3_client::from_config();
        if (!$s3->is_configured()) {
            self::set_status_string('unconfigured', 'ship_detail_unconfigured');
            mtrace('local_intellistream: S3 not configured — nothing shipped.');
            return;
        }
        // NOTE on the position of enforce_disk_cap() below this return: it is
        // deliberate, do not hoist it. Returning here means the buffer is NOT
        // being drained to object storage, so the only records on disk are ones
        // nobody has collected — either a pull-only site whose puller has not
        // fetched them yet, or a site whose storage is not set up. Evicting there
        // would delete data that is still owed, which is worse than filling up.
        // The buffer is still bounded in that mode: buffer::have_capacity()
        // refuses new files at the cap and note_cap_refusal() raises the same
        // admin alert. Refusing to write is the safe bound; deleting undelivered
        // records is not.
        // Refuse to ship while payload encryption is on, because NOTHING DOWNSTREAM CAN
        // READ IT. encryption_service::encrypt() replaces the newline-delimited
        // JSONL with a single `intellistream-enc:v1:` blob, and the middleware has no
        // decryption path at all — its only crypto is a Fernet helper scoped to the Canvas
        // DAP credential cache. An encrypted object therefore gunzips to one unparseable
        // line, is skipped by the middleware's per-line guard, reports zero events, and is
        // treated as fully processed: the watermark advances past it and, with
        // ISM_DELETE_AFTER_INGEST on (the setting used on US Prod), the raw object is
        // deleted. Every record in that ship run is gone, with one WARNING line at the far
        // end and success reported at both ends.
        //
        // Holding the records here instead is strictly better than shipping them into that:
        // the same "do not drain, do not evict" position as the unpaired case below, so
        // nothing is lost while the feature is undecided. Deliberately NOT silently
        // ignoring the setting and shipping plaintext either — an admin who enabled this
        // may have a requirement, and quietly downgrading them is its own defect.
        //
        // The encrypt() call further down is unreachable while this stands. It is left in
        // place on purpose: the choice between implementing decryption and retiring the
        // feature has still to be made, and deleting it here would pre-empt that.
        if ((new \local_intellistream\services\encryption_service())->is_enabled()) {
            self::set_status_string('encryption_unsupported', 'ship_detail_encryption_unsupported');
            mtrace('local_intellistream: ALERT payload encryption is enabled, but the IntelliStream '
                . 'pipeline cannot decrypt it — shipping is STOPPED and records are being held in the '
                . 'buffer. Turn "Encrypt payloads" off to resume; nothing has been lost, '
                . 'but the buffer will grow toward its cap while this persists.');
            return;
        }
        if (config::site_id() === '') {
            self::set_status_string('unpaired', 'ship_detail_unpaired');
            mtrace('local_intellistream: site id not set (unpaired) — holding events in the buffer.');
            return;
        }
        if (!health::ship_allowed()) {
            // Record the state, do not just trace it. Every other early return here
            // sets a status; this one did not, so the admin status page kept showing
            // the last result — usually `ok` — while shipping had in fact been
            // stalled for hours. Meanwhile the buffer grows toward its cap, where
            // enforce_disk_cap() starts deleting un-shipped files. An operator needs
            // to see the stall before that point, not after.
            self::set_status_string('loadgated', 'ship_detail_loadgated');
            mtrace('local_intellistream: host load above gate — skipping this run.');
            return;
        }

        // Deliberately the current directory only. The cap governs how much this
        // plugin is allowed to accumulate where it is writing; a previous
        // directory is being drained rather than filled, so counting it toward
        // the cap would make capture refuse new files on account of records that
        // are on their way out, and evicting from it would delete the very
        // records this run exists to recover.
        self::enforce_disk_cap($dir);

        $closed = [];
        foreach ($dirs as $target) {
            foreach (buffer::safe_files($target, ['*.jsonl.closed']) as $path) {
                $closed[] = $path;
            }
        }
        usort($closed, function ($a, $b) {
            return (int)@filemtime($a) <=> (int)@filemtime($b);
        });

        $encsvc = new \local_intellistream\services\encryption_service();
        $encenabled = $encsvc->is_enabled();

        // The Site ID every shipped record must carry. The
        // L44 guard above guarantees this is non-empty here.
        $expected = config::site_id();

        $files = 0;
        $events = 0;
        $objects = 0;
        $badrecords = 0;
        $badfiles = 0;

        // One PUT per BATCH of closed files, not one per file. A buffer file is a
        // per-worker window (see buffer.php), so shipping one object per file made
        // object count track worker count and uptime rather than data volume — a
        // measured 14,400 objects/day to carry 19 MB on one tenant. The middleware
        // processes objects strictly serially per tenant and each one costs a fixed
        // S3 GET + decompress + parse + Kafka produce + bookkeeping, so object count,
        // not bytes, is the wall-clock variable downstream.
        //
        // MAX_FILES_PER_RUN remains a files-per-RUN cap, now raised to 2,000 on the
        // strength of that batching: object count follows bytes rather than file count,
        // so a bigger slice buys drain rate without buying round-trips. See the
        // constant for the measurements the number comes from.
        $slice = array_slice($closed, 0, self::MAX_FILES_PER_RUN);
        $sizes = [];
        foreach ($slice as $path) {
            $sizes[$path] = (int)@filesize($path);
        }

        foreach (self::plan_batches($sizes, self::MAX_BATCH_BYTES) as $batch) {
            $bodies = [];
            $shipped = [];
            $eventcount = 0;
            foreach ($batch['paths'] as $path) {
                $body = buffer::safe_contents($path);
                if ($body === false) {
                    // Vanished between the glob and here — pull_export commits by
                    // renaming a drained file to `.pulled`, and it races us for the
                    // same closed files — or it was replaced by something that is
                    // not a plain single-linked file, which safe_contents() refuses
                    // to read. Either way it is not ours any more.
                    continue;
                }
                // Defence-in-depth behind the capture-time guard
                // in buffer::append(): drop any buffered record whose site_id does
                // not match the current Site ID (captured before the fix, or across
                // a Site ID change) before it leaves the host. Runs on the plaintext
                // body, before encryption — and per FILE, before concatenation, so the
                // badrecords/badfiles accounting stays attributable to a single file.
                $dropped = 0;
                $body = self::filter_site_id($body, $expected, $dropped);
                $badrecords += $dropped;
                if ($body === '') {
                    // Every record in the file was a mismatch — nothing to ship.
                    @unlink($path);
                    $badfiles++;
                    continue;
                }
                // A file can end mid-line: buffer::append() drops a record whose
                // fwrite came up short, but the partial bytes are already on disk.
                // On its own that is one malformed line the middleware skips
                // per-line; concatenated without this guard the fragment would fuse
                // with the next file's first record and lose TWO.
                if (substr($body, -1) !== "\n") {
                    $body .= "\n";
                }
                // Count events on the plaintext body; encryption replaces the
                // newline-delimited JSONL with a single wrapped blob and would
                // make `substr_count($body, "\n")` collapse to 0.
                $eventcount += substr_count($body, "\n");
                $bodies[] = $body;
                $shipped[] = $path;
            }
            if (!$shipped) {
                continue;
            }
            // A one-file batch is the common case on a quiet site, and it is also
            // how an oversized file is shipped. Assigning the single element rather
            // than imploding it lets copy-on-write hand the string over without
            // duplicating it, so that case costs no more memory than the old
            // one-PUT-per-file code did.
            $payload = count($bodies) === 1 ? $bodies[0] : implode('', $bodies);
            unset($bodies);

            $headers = [];
            if ($encenabled) {
                $payload = $encsvc->encrypt($payload);
                $headers['x-amz-meta-encrypted'] = '1';
                $headers['x-amz-meta-encryption-version'] = 'v1';
            }
            $gz = gzencode($payload, 6);
            unset($payload);
            if ($gz === false) {
                continue;
            }
            // Keyed off the files that actually READ, not the files that were
            // planned, so a file lost to the pull race cannot leave the key
            // claiming content the object does not carry.
            $key = self::batch_object_key($batch['datebucket'], $shipped);
            $result = $s3->put($key, $gz, 'application/gzip', $headers);
            if (!$result['ok']) {
                self::set_status($result['category'], $result['detail']);
                mtrace('local_intellistream: ship failed (' . $result['category']
                    . ') — will retry next run.');
                return;
            }
            // All-or-nothing, and only after a 2xx: on failure every constituent
            // stays `.closed` for the next run, which rebuilds the same batch under
            // the same deterministic key, so a retry overwrites its own object
            // instead of leaving a duplicate.
            foreach ($shipped as $shippedpath) {
                @unlink($shippedpath);
            }
            $files += count($shipped);
            $events += $eventcount;
            $objects++;
        }

        $statuskey = 'ship_detail_shipped';
        $statusparams = ['files' => $files, 'objects' => $objects, 'events' => $events];
        if ($badrecords > 0 || $badfiles > 0) {
            $statuskey = 'ship_detail_shipped_mismatch';
            $statusparams['badrecords'] = $badrecords;
            $statusparams['badfiles'] = $badfiles;
            set_config('last_sitemismatch_time', time(), config::COMPONENT);
            set_config('last_sitemismatch_records', $badrecords, config::COMPONENT);
            set_config('last_sitemismatch_files', $badfiles, config::COMPONENT);
            mtrace("local_intellistream: ALERT dropped {$badrecords} record(s) / {$badfiles} file(s) "
                . 'with a site_id != current Site ID (pre-pairing capture or Site ID change).');
        }
        // Ship-proof: record the fingerprint of the access key we ACTUALLY
        // shipped with, only on a real successful PUT ($files > 0), so the control plane's
        // revoke gate confirms the NEW key WORKS. Access-key fingerprint only; never the secret.
        if ($files > 0) {
            set_config(
                'last_ship_accesskey_fp',
                substr(hash('sha256', config::access_key()), 0, 12),
                config::COMPONENT
            );
            set_config('last_ship_ok_time', time(), config::COMPONENT);
        }

        self::set_status_string('ok', $statuskey, $statusparams);
        mtrace("local_intellistream: shipped {$files} file(s) in {$objects} object(s), {$events} event(s).");
    }

    /**
     * A buffer file written by THIS host and untouched for at least this many
     * seconds is promoted to `.closed` on the next sweep, regardless of whether
     * its owning PID still appears alive.
     *
     * PHP closes a worker's open file handles at request shutdown, so a file
     * untouched for this long has no open writer — its worker is dead, or
     * alive but idle between requests — and renaming it is safe. This bounds
     * shipping latency for the near-real-time pipeline: a low-traffic worker
     * that never appends enough to self-rotate by size or age still has its
     * file picked up within ~this interval.
     *
     * The "no open writer" inference holds for a web request but NOT for a
     * long-lived CLI process, which keeps its handle across the whole run and
     * may pause here between units of work. Those writers call
     * buffer::keepalive() to keep their mtime fresh; this threshold is only
     * safe because they do.
     */
    const SHIP_IDLE_SEC = 120;

    /**
     * How long a file written by ANOTHER host must sit untouched before this
     * host may take it.
     *
     * Off-host, mtime is the only evidence available — `pid_alive()` answers
     * about the local process table and is therefore meaningless — so this is
     * the sole reclaim path for another node's files and it has to clear two
     * hurdles the local threshold does not:
     *
     *  - It must exceed the remote writer's OWN age rotation, or a foreign
     *    sweeper races the writer for a file the writer was about to close by
     *    itself. `rotateagesec` is admin-editable and defaults to exactly
     *    SHIP_IDLE_SEC, so a fixed constant would leave no margin at all.
     *  - mtime was stamped by the other node's clock and is compared against
     *    ours, so the gap also has to absorb whatever skew NTP leaves behind.
     *
     * @return int seconds
     */
    private static function remote_idle_sec(): int {
        return max(self::SHIP_IDLE_SEC, config::rotate_age_sec() + 60);
    }

    /**
     * Give a buffer file its shippable `.closed` name, re-checking it first.
     *
     * buffer::safe_files() already judged this path when the directory was
     * globbed, and this re-checks immediately before the rename. That is not
     * redundancy for its own sake: the two are separated by the rest of the loop
     * body, and the buffer directory is inside moodledata, so anything able to
     * write there as the web user could replace an approved plain file with a
     * link in between. Renaming it would then hand a link a name the shipper
     * treats as ready to send.
     *
     * The window was already narrow and the consequence already contained —
     * rename() acts on the link, never on what it points at, and every reader
     * goes through safe_open()/safe_contents(), which refuse a link — so nothing
     * was readable through it. What it produced was a `.closed` entry that
     * unsafe_files() then reports, which is a correct alert about a real event
     * but a needless one. buffer::mark_closed() has always done this check on the
     * capture path; these two sweeper renames were the only promotions in the
     * tree that skipped it.
     *
     * @param string $path Buffer file to promote.
     * @return void
     */
    private static function promote(string $path): void {
        if (!buffer::is_safe_file($path)) {
            return;
        }
        @rename($path, $path . '.closed');
    }

    /**
     * Promote stale `.jsonl` files to `.jsonl.closed` so the shipper picks
     * them up.
     *
     * A file is stale once its owning PID is dead, or once it has not been
     * written to for long enough that no live writer can be mid-burst. Which of
     * those two tests may be applied depends on WHERE the file was written:
     *
     *  - Ours: `/proc` can answer for the PID, so a dead writer's file is
     *    reclaimed immediately and the idle check is the backstop.
     *  - Another node's (moodledata is shared on a clustered Moodle, and the
     *    buffer dir is required to live inside it): a PID from another host
     *    means nothing here — it is absent from our process table whether or
     *    not it is running, so consulting it would mark every remote worker's
     *    ACTIVE file dead and hand a live writer's file straight to the shipper,
     *    which renames, ships and unlinks it. Judge those on mtime alone.
     *
     * That distinction is what keeps `.closed` meaning "has no writer", which
     * run(), enforce_disk_cap(), pull_export and buffer::delete_user_records()
     * all rely on.
     *
     * A THIRD case exists and used to have no exit at all: an `events-*.jsonl`
     * whose name parse_name() cannot read. It was skipped here, so it was never
     * promoted, never shipped and — because enforce_disk_cap() only ever evicts
     * writer-less files — never deleted, while its bytes still counted against the
     * cap. A stuck file therefore displaced genuine unshipped records permanently.
     * Such a name means unknown ownership, which is already treated as foreign
     * above, so judge it the same way: on mtime alone. It then ships like any other
     * closed file (unreadable CONTENT is skipped line-by-line by the middleware,
     * which is the existing and correct behaviour) and becomes evictable.
     *
     * A `*.jsonl` that is not `events-*` is not ours — nothing in this plugin
     * writes one (buffer::own_file_patterns()). It is counted and reported but
     * deliberately left alone: shipping or deleting a file we did not write is not
     * ours to do. enforce_disk_cap() no longer measures it, so it can no longer
     * displace real data either.
     *
     * @param string $dir
     * @return array{foreign:int,unreadable:string[],unowned:string[]} foreign =
     *         distinct host ids seen holding active files; unreadable = basenames of
     *         own-prefix files reclaimed by name alone; unowned = basenames of
     *         `*.jsonl` files this plugin did not write.
     */
    private static function sweep_stale(string $dir): array {
        $now = time();
        $localidle = self::SHIP_IDLE_SEC;
        $remoteidle = self::remote_idle_sec();
        $foreign = [];
        $unreadable = [];
        $unowned = [];

        // Stray rewrite temporaries, left by a process killed between the
        // fopen('xb') and the rename(). Cleared here because this is already the
        // janitor that runs over every tracked directory each ship run, so they
        // are gone within a cycle instead of lingering as an undeclared copy of
        // buffer records. Age-guarded: a rewrite in flight elsewhere must keep its
        // temp. Safe to delete outright — the rename never happened, so the
        // original file still holds the records.
        $residue = buffer::purge_residue($dir, buffer::RESIDUE_STRAY_SEC);
        if ($residue > 0) {
            mtrace('local_intellistream: removed ' . $residue . ' stray buffer rewrite '
                . 'temporary file(s) in "' . $dir . '".');
        }

        foreach (buffer::safe_files($dir, ['*.jsonl']) as $path) {
            $parsed = buffer::parse_name($path);
            if ($parsed === null) {
                if (strpos(basename($path), 'events-') !== 0) {
                    $unowned[] = basename($path);
                    continue;
                }
                if (($now - (int)@filemtime($path)) >= $remoteidle) {
                    $unreadable[] = basename($path);
                    self::promote($path);
                }
                continue;
            }
            $idlefor = $now - (int)@filemtime($path);
            $own = $parsed['hostid'] !== null && $parsed['hostid'] === buffer::host_id();

            if ($own) {
                $stale = !self::pid_alive($parsed['pid']) || $idlefor >= $localidle;
            } else {
                // Includes a legacy name with no host component: unknown origin
                // is treated as foreign, which is the safe direction (it may be
                // a not-yet-upgraded node's file, and the only cost of being
                // wrong is a slower reclaim).
                if ($parsed['hostid'] !== null) {
                    $foreign[$parsed['hostid']] = true;
                }
                $stale = $idlefor >= $remoteidle;
            }

            if ($stale) {
                self::promote($path);
            }
        }

        return [
            'foreign'    => count($foreign),
            'unreadable' => $unreadable,
            'unowned'    => $unowned,
        ];
    }

    /**
     * Remember how many other hosts are writing into this buffer directory.
     *
     * Whether a site runs one web node or ten is currently invisible to us, yet
     * it changes what the buffer machinery can safely assume. Recording it makes
     * a clustered install visible on the status page (and so in a support
     * bundle) instead of something inferred after the fact.
     *
     * Written only when the value changes: `set_config()` invalidates this
     * plugin's whole config cache, and this runs every minute.
     *
     * @param int $count
     */
    private static function record_foreign_hosts(int $count): void {
        if ((int)config::get('foreign_hosts', 0) === $count) {
            return;
        }
        set_config('foreign_hosts', $count, config::COMPONENT);
        set_config('foreign_hosts_time', time(), config::COMPONENT);
    }

    /**
     * Report buffer files whose names sweep_stale() could not read.
     *
     * Both classes were previously invisible: nothing logged them, nothing counted
     * them, and the only way to discover one was to list the directory by hand —
     * which is why the disk-cap interaction went unnoticed. They are reported
     * differently because their remedies differ:
     *
     *  - `unreadable` is an EVENT. The file has just been reclaimed, so it is about
     *    to ship and the condition is self-healing; the record persists (like
     *    last_drop_*) so that an admin who was not watching cron still sees that
     *    something odd happened — a crashed rotation, or a hand-edited buffer.
     *  - `unowned` is a STATE. Nothing here will ever remove that file, so it is
     *    written on change (like foreign_hosts) and stays on the status page until
     *    an operator clears it.
     *
     * Names are logged, not just counted, because the name is the only thing that
     * says where the file came from. Capped at three per class: a partial restore
     * could drop thousands in, and a flooded cron log is its own outage.
     *
     * @param string[] $unreadable Basenames reclaimed by mtime alone.
     * @param string[] $unowned    Basenames this plugin did not write.
     * @param string[] $unsafe     Basenames that are not plain single-linked files.
     */
    private static function record_odd_files(array $unreadable, array $unowned, array $unsafe = []): void {
        if ($unreadable) {
            set_config('last_unreadable_count', count($unreadable), config::COMPONENT);
            set_config('last_unreadable_time', time(), config::COMPONENT);
            mtrace('local_intellistream: reclaimed ' . count($unreadable)
                . ' buffer file(s) whose name could not be read, by idle time alone: '
                . self::name_sample($unreadable) . '. They will ship on this run. This is '
                . 'not normal — a rotation may have crashed, or something wrote into the '
                . 'buffer directory by hand.');
        }

        if ((int)config::get('unowned_files', 0) !== count($unowned)) {
            set_config('unowned_files', count($unowned), config::COMPONENT);
            set_config('unowned_files_time', time(), config::COMPONENT);
        }
        if ($unowned) {
            mtrace('local_intellistream: ' . count($unowned) . ' file(s) in the buffer '
                . 'directory were not written by this plugin and are being left alone: '
                . self::name_sample($unowned) . '. They are excluded from the buffer disk '
                . 'cap, so they cannot displace unshipped data, but nothing here will '
                . 'remove them — delete them or move them out of the buffer directory.');
        }

        // STATE, like unowned: nothing here will ever remove one of these, so the
        // count is written on change and stays visible until an operator acts.
        if ((int)config::get('unsafe_files', 0) !== count($unsafe)) {
            set_config('unsafe_files', count($unsafe), config::COMPONENT);
            set_config('unsafe_files_time', time(), config::COMPONENT);
        }
        if ($unsafe) {
            mtrace('local_intellistream: ALERT ' . count($unsafe) . ' entr(y/ies) in the '
                . 'buffer directory are not plain files and are being left strictly alone: '
                . self::name_sample($unsafe) . '. NOTHING has been read, written, shipped or '
                . 'deleted through them. A symlink, FIFO or directory here can only have been '
                . 'created by something with write access as the web server user — treat it as '
                . 'a possible attempt to make this plugin read or overwrite a file elsewhere. A '
                . 'hard link is more often innocent (a moodledata restore made with cp -al or '
                . 'rsync --link-dest); replace it with a real copy so its records can ship.');
        }
    }

    /**
     * Up to three names, with a count of any remainder.
     *
     * @param string[] $names
     * @return string
     */
    private static function name_sample(array $names): string {
        $shown = array_slice($names, 0, 3);
        $rest = count($names) - count($shown);
        return implode(', ', $shown) . ($rest > 0 ? ' (+' . $rest . ' more)' : '');
    }

    /**
     * Best-effort check of whether a PID is still running.
     *
     * On a host with neither `/proc` nor ext-posix this cannot tell a live
     * PID from a dead one, so it returns true (conservative: never rename a
     * possibly-live writer's file). `sweep_stale()` still reclaims a truly
     * orphaned file on such a host via its SHIP_IDLE_SEC idle check.
     *
     * ONLY ASK THIS ABOUT A PID FROM THIS HOST. A PID is meaningful only in the
     * process table it came from: another node's PID is absent from ours whether
     * or not it is running, so a false answer here means "not from this host",
     * not "not running". Callers must gate on buffer::is_own_host() first — the
     * buffer directory is shared across nodes on a clustered Moodle.
     *
     * Public because the privacy provider needs the same judgement for the same
     * reason: buffer::delete_user_records() may only rewrite an active file whose
     * writer is gone. Conservative-true is the right default there too —
     * it means "leave this file alone".
     *
     * @param int $pid A PID belonging to THIS host.
     * @return bool
     */
    public static function pid_alive(int $pid): bool {
        if ($pid <= 0) {
            return false;
        }
        if (is_dir('/proc')) {
            return file_exists('/proc/' . $pid);
        }
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : true;
    }

    /**
     * Drop oldest closed files if the buffer directory exceeds its cap.
     *
     * `*.jsonl.pulled` files are legacy: the pull web service used to park a
     * fully-drained file under that name instead of deleting it, and nothing
     * ever removed it again, so a pull-integration site grew its buffer without
     * bound while the cap — which globbed only `*.jsonl.closed` and `*.jsonl` —
     * stayed blind to it. pull_export now deletes on
     * commit, but a site upgrading with a backlog still has them on disk, so
     * they are counted AND evicted here like any other writer-less file. That
     * drains an existing backlog automatically instead of stranding it.
     *
     * @param string $dir
     */
    private static function enforce_disk_cap(string $dir): void {
        $cap = config::max_buffer_bytes();

        // Only writer-less files can be EVICTED — an active .jsonl has a live
        // writer appending to it, and unlinking underneath that writer would lose
        // the records still in flight and leave the handle dangling. `.closed` and
        // the legacy `.pulled` both qualify: nothing holds a handle to either.
        //
        // Scoped to our own `events-` prefix so that what is evicted is exactly what
        // is measured below. A `.closed` file we did not write is neither — run()
        // still ships and removes it, so it is not stranded.
        $closed = array_merge(
            buffer::safe_files($dir, ['events-*.jsonl.closed']),
            buffer::safe_files($dir, ['events-*.jsonl.pulled'])
        );
        $sizes = [];
        foreach ($closed as $path) {
            $sizes[$path] = (int)@filesize($path);
        }

        // ...but they must all be MEASURED. Counting only .closed meant active
        // .jsonl files sat outside the cap entirely, so the buffer could exceed the
        // configured ceiling without the cap ever noticing — and under a flood, the
        // fastest-growing files are precisely the active ones. Measuring everything
        // and evicting only what is safe to evict makes the cap honest about actual
        // disk use.
        //
        // Ask buffer::occupied_bytes() rather than globbing here, so the cap and
        // buffer::have_capacity() are the same number by construction. They were not:
        // this measured a bare `*.jsonl`, which counts files the plugin never wrote
        // (see sweep_stale()). Since only writer-less files are evictable, such a file
        // inflated $needed below without ever being a candidate to satisfy it — so its
        // bytes were paid for by deleting genuine unshipped records, permanently.
        // occupied_bytes() is ownership-scoped and skips symlinks.
        $total = buffer::occupied_bytes($dir);

        if ($total <= $cap) {
            return;
        }
        usort($closed, function ($a, $b) {
            return (int)@filemtime($a) <=> (int)@filemtime($b);
        });

        // Evict oldest-first, and only as much as is actually needed to get back
        // under the cap. Framing the loop around "bytes still to free" rather than
        // "is the running total under cap" matters now that active files are counted:
        // if the active files alone exceeded the cap, a total-driven loop would
        // delete EVERY closed file and still not be under, destroying unshipped data
        // for no benefit. This stops the moment the deficit is covered.
        $needed = $total - $cap;
        $dropped = 0;
        $droppedbytes = 0;
        foreach ($closed as $path) {
            if ($droppedbytes >= $needed) {
                break;
            }
            $droppedbytes += $sizes[$path];
            @unlink($path);
            $dropped++;
        }
        set_config('last_drop_time', time(), config::COMPONENT);
        set_config('last_drop_count', $dropped, config::COMPONENT);
        set_config('last_drop_bytes', $droppedbytes, config::COMPONENT);

        // Surface the drop. The status detail records a distinct 'dropping' state
        // (cumulative — last_drop_* persist) so the admin status page shows
        // it even after a later successful ship flips ship_state back to ok.
        //
        // The admin-facing copy is translated; the mtrace/debugging text below stays
        // English on purpose — cron output and developer-debug channels are log
        // streams read by operators, not UI, and translating them would make them
        // harder to search and to paste into a support ticket.
        self::set_status_string('dropping', 'ship_detail_dropping', [
            'files' => $dropped,
            'bytes' => $droppedbytes,
        ]);
        $msg = 'Buffer over cap — dropped ' . $dropped
            . ' oldest unshipped file(s), ' . $droppedbytes . ' byte(s).';

        // Standard Moodle alert channel, in addition to cron mtrace, so the
        // drop is not lost when no operator is watching cron output.
        if (function_exists('debugging')) {
            debugging('local_intellistream: ' . $msg, DEBUG_NORMAL);
        }
        mtrace('local_intellistream: ALERT ' . $msg);

        if ($droppedbytes < $needed) {
            // Eviction could not close the gap: what remains over the cap is live
            // active files, which must not be unlinked from under their writers.
            // Say so distinctly — the operator's lever here is the buffer cap, the
            // ship cadence or the dwell quota, not more eviction.
            $stuck = 'Buffer still over cap after eviction: '
                . ($needed - $droppedbytes) . ' byte(s) of it is in ACTIVE buffer '
                . 'files, which are never deleted while being written. Raise '
                . 'maxbuffergb, shorten the ship interval, or review dwellmaxperminute.';
            if (function_exists('debugging')) {
                debugging('local_intellistream: ' . $stuck, DEBUG_NORMAL);
            }
            mtrace('local_intellistream: ALERT ' . $stuck);
        }
    }

    /**
     * Drop records whose `site_id` does not match the current Site ID.
     *
     * Defence-in-depth behind the capture-time guard in
     * buffer::append(). Operates on the plaintext JSONL body (one record per
     * line) BEFORE encryption/compression. A line is dropped only if it parses
     * as JSON, carries a `site_id`, and that value differs from $expected;
     * lines that do not parse or omit `site_id` are kept — this guard validates
     * ownership, it is not a JSON linter, so anything else is left for the
     * middleware. Returns the body unchanged (byte-for-byte) when nothing is
     * dropped, so the normal path pays no recomposition cost.
     *
     * @param string $body     raw JSONL buffer body
     * @param string $expected current config::site_id() (non-empty)
     * @param int    $dropped  out-param: number of records dropped
     * @return string filtered JSONL body ('' when every record was dropped)
     */
    private static function filter_site_id(string $body, string $expected, int &$dropped): string {
        $dropped = 0;
        $kept = [];
        foreach (explode("\n", $body) as $line) {
            if ($line === '') {
                continue;
            }
            $rec = json_decode($line, true);
            if (
                is_array($rec) && array_key_exists('site_id', $rec)
                    && (string)$rec['site_id'] !== $expected
            ) {
                $dropped++;
                continue;
            }
            $kept[] = $line;
        }
        if ($dropped === 0) {
            return $body;
        }
        return $kept ? implode("\n", $kept) . "\n" : '';
    }

    /**
     * Creation microseconds embedded in a buffer filename, or null.
     *
     * Delegates to buffer::parse_name(), which owns the filename grammar and knows
     * all of its generations — including the current host-stamped form. Keeping a
     * private pattern here instead would have failed open as soon as a segment was
     * added: it would simply stop matching, and every file would silently fall back
     * to being partitioned by SHIP date instead of creation date.
     *
     * Anything unparseable returns null and the caller substitutes the current time —
     * the same graceful degradation the single-file key had.
     *
     * @param string $path
     * @return int|null
     */
    private static function created_us_from_name(string $path): ?int {
        $parsed = buffer::parse_name(basename($path, '.closed'));
        return $parsed !== null ? $parsed['created_us'] : null;
    }

    /**
     * Group closed buffer files into batches, one object per batch.
     *
     * Pure by design — it takes a `path => bytes` map and returns a plan, touching
     * neither the filesystem nor S3 — because it is the part of batching that can go
     * subtly wrong (a mis-bucketed day silently mis-partitions data) and the only
     * part that can be exercised without a live S3. Covered by tests/shipper_test.php.
     *
     * Grouping is by UTC date bucket FIRST. The caller hands files in mtime (close
     * time) order while the partition comes from the filename's embedded creation
     * time, so the two can interleave around midnight; bucketing rather than cutting
     * the ordered list handles that without a boundary special case, and guarantees no
     * object ever spans two date partitions.
     *
     * A file is never split: one whose own size exceeds $maxbytes becomes a batch of
     * one. Files are already bounded by config::rotate_size_bytes(), and splitting
     * would have to cut mid-line and corrupt a record.
     *
     * @param array $sizes   Ordered map of file path => plaintext byte size.
     * @param int   $maxbytes Accumulated-plaintext cap per batch.
     * @return array List of ['datebucket' => 'Y/m/d', 'paths' => string[]].
     */
    public static function plan_batches(array $sizes, int $maxbytes): array {
        $maxbytes = max(1, $maxbytes);
        $buckets = [];
        foreach ($sizes as $path => $bytes) {
            $us = self::created_us_from_name((string)$path);
            $secs = $us === null ? time() : intdiv($us, 1000000);
            $buckets[gmdate('Y/m/d', $secs)][(string)$path] = (int)$bytes;
        }

        $batches = [];
        foreach ($buckets as $datebucket => $bucketsizes) {
            $paths = [];
            $accumulated = 0;
            foreach ($bucketsizes as $path => $bytes) {
                // Flush before adding, so a batch never exceeds the cap — except a
                // single oversized file, which lands alone in the batch we just opened.
                if ($paths && ($accumulated + $bytes) > $maxbytes) {
                    $batches[] = ['datebucket' => $datebucket, 'paths' => $paths];
                    $paths = [];
                    $accumulated = 0;
                }
                $paths[] = (string)$path;
                $accumulated += $bytes;
            }
            if ($paths) {
                $batches[] = ['datebucket' => $datebucket, 'paths' => $paths];
            }
        }
        return $batches;
    }

    /**
     * Object key for a batch:
     * `<prefix>/<site_id>/YYYY/MM/DD/batch-<earliest_us>-<count>-<hash8>.jsonl.gz`.
     *
     * Three properties the middleware requires, all preserved: the first two segments
     * are `<prefix>/<site_id>/` (that is how it attributes an object to a tenant), the
     * key ends `.jsonl.gz` (its list filter is exactly that suffix — a key without it
     * is skipped silently, with no error and no quarantine), and the basename is
     * unique per tenant-hour (it is reused verbatim as the canonical archive
     * basename). Nothing downstream parses any of it, so the date segments are for
     * human/lifecycle use only.
     *
     * The hash makes the key a deterministic function of the batch's content: a run
     * that failed at the PUT rebuilds the identical batch next time and overwrites its
     * own object rather than leaving a duplicate, while a genuinely different set of
     * files — a file lost to the pull-export race, or an ad-hoc flush()+run()
     * overlapping cron — necessarily gets a different key and so can never clobber
     * another run's object.
     *
     * Public for the same reason as plan_batches(): the key's shape and its
     * determinism are the two properties worth pinning, and neither can be observed
     * from outside without calling this.
     *
     * @param string $datebucket 'Y/m/d' from plan_batches().
     * @param array  $paths      Constituent file paths, as actually read.
     * @return string
     */
    public static function batch_object_key(string $datebucket, array $paths): string {
        $earliest = null;
        $names = [];
        foreach ($paths as $path) {
            $names[] = basename($path, '.closed');
            $us = self::created_us_from_name($path);
            if ($us !== null && ($earliest === null || $us < $earliest)) {
                $earliest = $us;
            }
        }
        if ($earliest === null) {
            $earliest = time() * 1000000;
        }
        // SORT_STRING explicitly: the default comparison would switch to numeric
        // ordering for anything that looks like a number, and the hash is only
        // order-independent if the sort is total and stable across inputs.
        sort($names, SORT_STRING);
        $hash = substr(hash('sha256', implode("\n", $names)), 0, 8);

        return config::prefix() . '/' . config::site_id() . '/' . $datebucket
            . '/batch-' . $earliest . '-' . count($paths) . '-' . $hash . '.jsonl.gz';
    }

    /**
     * Record shipper status for the admin status page, with a RAW detail string.
     *
     * Kept for details that are not ours to translate — chiefly the transport
     * category/detail pair returned by s3_client, which is a diagnostic message
     * from the API layer rather than UI copy the plugin authors. Anything the
     * plugin words itself should use {@see set_status_string} instead.
     *
     * @param string $state
     * @param string $detail
     */
    private static function set_status(string $state, string $detail): void {
        set_config('ship_state', $state, config::COMPONENT);
        set_config('ship_detail', $detail, config::COMPONENT);
        set_config('ship_time', time(), config::COMPONENT);
        if ($state === 'ok') {
            set_config('last_ship_ok', time(), config::COMPONENT);
        }
    }

    /**
     * Record shipper status with a TRANSLATABLE detail.
     *
     * The status detail is persisted here (in cron) and rendered later (in status.php,
     * by whoever loads the page), so storing a rendered English sentence pinned the
     * message to the language of the process that shipped, which is how these
     * strings came to be hardcoded English in the admin UI.
     *
     * Storing the lang key plus its parameters instead defers rendering to display
     * time, so the string comes out in the *viewer's* language. status.php falls back
     * to printing the stored value verbatim when it is not this shape, which keeps
     * both the raw set_status() path above and any value already stored on a
     * deployed site rendering exactly as before.
     *
     * @param string $state
     * @param string $key    Lang string identifier in this component.
     * @param array  $params Parameters for the string ($a).
     */
    private static function set_status_string(string $state, string $key, array $params = []): void {
        $encoded = json_encode(['key' => $key, 'params' => $params], JSON_UNESCAPED_SLASHES);
        // A json_encode() failure here is only possible on invalid UTF-8 in a parameter; fall back
        // to the key alone rather than storing `false`.
        self::set_status($state, $encoded !== false ? $encoded : $key);
    }

    /**
     * Render a stored `ship_detail` value for display.
     *
     * @param string $stored Raw config value.
     * @return string Plain text, ready for s()/format_string by the caller.
     */
    /**
     * Classify a stored shipper status for display.
     *
     * The status page needs this twice — once to pick a banner severity, once to pick
     * the summary sentence — and those were two independent expressions of the same
     * idea. They drifted: the severity used a hardcoded list of "bad" states which
     * named three that nothing produces any more (`error`, `transient`, `permanent`,
     * left over from an earlier s3_client) while missing every one that is. So
     * `unpaired`, and every real shipping failure — invalid credentials, unreachable
     * endpoint, TLS failure, quota exceeded, 5xx, 4xx — rendered a GREEN success
     * banner above the words "Attention needed".
     *
     * Deriving both from this one function is what makes the colour and the text
     * unable to disagree, and it is closed rather than open: anything that is not
     * `ok` is a problem, so a new failure category added to s3_client is covered
     * without touching the status page.
     *
     * Owned by the shipper because the shipper is what writes these values.
     *
     * @param string $state      Stored `ship_state` ('' or 'unknown' when never run).
     * @param int    $dropcount  Stored `last_drop_count`.
     * @return string 'problem' | 'norun' | 'healthy'
     */
    public static function status_class(string $state, int $dropcount): string {
        // A drop is unshipped data deleted off the disk. It outranks everything,
        // including "never run", because the events are already gone.
        if ($dropcount > 0) {
            return 'problem';
        }
        if ($state === '' || $state === 'unknown') {
            return 'norun';
        }
        return $state === 'ok' ? 'healthy' : 'problem';
    }

    /**
     * Render a stored status detail for display.
     *
     * A detail is persisted as a JSON envelope naming a lang string and its
     * placeholders, so the admin page renders it in the reader's language rather
     * than in whatever language the cron run happened to use. A value that is
     * not that envelope is returned unchanged, which is what keeps details
     * already stored on a deployed site rendering after an upgrade.
     *
     * @param string $stored
     * @return string
     */
    public static function format_status_detail(string $stored): string {
        $stored = trim($stored);
        if ($stored === '' || $stored[0] !== '{') {
            return $stored;
        }
        $decoded = json_decode($stored, true);
        if (!is_array($decoded) || empty($decoded['key']) || !is_string($decoded['key'])) {
            return $stored;
        }
        $key = $decoded['key'];
        $params = isset($decoded['params']) && is_array($decoded['params']) ? $decoded['params'] : [];
        if (!get_string_manager()->string_exists($key, config::COMPONENT)) {
            // Key from a newer build than this lang pack: show something, not nothing.
            return $stored;
        }
        // A value persisted by an OLDER build can be missing a parameter this build's
        // string now expects — the reverse of the mismatch guarded above, and it happens
        // on every upgrade that adds one (batched shipping added `objects`). Moodle leaves the
        // raw `{$a->objects}` in the output, which on the status page reads as a bug
        // rather than as stale data. Substitute a placeholder instead. Generic on
        // purpose, so the next added parameter needs no change here; a successfully
        // rendered string never contains this pattern, so nothing legitimate is touched.
        // Self-corrects on the next shipper run either way.
        return preg_replace('/\{\$a->\w+\}/', '?', get_string($key, config::COMPONENT, $params));
    }
}
