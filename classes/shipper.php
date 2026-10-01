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
     * does not grow forever — at the cap buffer::have_capacity() refuses new buffer
     * files, so new capture is refused (nothing already captured is deleted). So a
     * sustained burst over the drain rate becomes refused capture rather than delay.
     * That is why the number is set from measurement and not from taste.
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
     *
     * @param bool $scheduled True only from the ship_events scheduled task. Such a run
     *        may ship past a closed host load gate while the backlog is large (see
     *        backlog_over_gate()); every other caller (the backfill and the re-fetch
     *        ship inline, the CLI) keeps the gate as it is, since those have their own
     *        throttle and would otherwise add load exactly when the host has none.
     */
    public static function run(bool $scheduled = false): void {
        if (!config::enabled()) {
            mtrace('local_intellistream: disabled — nothing shipped.');
            return;
        }
        // One ship run at a time. The scheduled task's own lock keeps two ship_events
        // runs apart, but the backfill, its backpressure and the targeted re-fetch
        // also ship inline, and two runs would each read-modify-write the published
        // measurement and the rejection state. A second caller skips: the drain is
        // already happening.
        try {
            $factory = \core\lock\lock_config::get_lock_factory('local_intellistream');
            $lock = $factory->get_lock('ship_run', 0, self::SHIP_LOCK_SECONDS);
        } catch (\Throwable $e) {
            mtrace('local_intellistream: the ship lock could not be taken (' . $e->getMessage()
                . ') — nothing shipped this run.');
            self::set_status_string('lock_unavailable', 'ship_detail_lock_unavailable');
            return;
        }
        if (!$lock) {
            mtrace('local_intellistream: another ship run is active — skipped.');
            return;
        }
        try {
            self::run_locked($scheduled);
        } finally {
            $lock->release();
        }
    }

    /**
     * The body of run(), under its lock.
     *
     * @param bool $scheduled As run().
     * @return void
     */
    private static function run_locked(bool $scheduled): void {
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
            // Record the state, do not just trace it — same reason as the load gate
            // below. Every buffer directory being unreadable means capture cannot
            // be collected at all, and leaving the status untouched left the page
            // showing the previous result, which is usually `ok`.
            self::set_status_string('bufferdir_unreadable', 'ship_detail_bufferdir_unreadable', [
                'dir' => $dir,
            ]);
            return;
        }

        // ONE pass over every directory, before any of the early returns below. It
        // does what used to take a dozen globs and a stat per file each: it reclaims
        // dead temporaries and dead workers' pointers, promotes buffer files
        // orphaned by dead workers (see scan_entry()), removes delivered `.pulled`
        // leftovers, finds what is unsafe or not ours, counts what is left in each
        // previous directory, measures the current one, and keeps the oldest
        // MAX_FILES_PER_RUN closed files to ship, with their sizes and mtimes. A
        // previous directory's files are orphaned by definition — nothing writes
        // there any more — but they still go through the same liveness rules
        // rather than being promoted on sight, because on a cluster a node that
        // has not yet re-read the changed setting can still be appending to one.
        $runstart = time();
        $inv = self::inventory($dirs, $dir);
        // That pass saw every byte this process had appended (a backfill flushes
        // before it ships inline), so its own-output counter starts again here.
        buffer::note_measured($runstart);
        self::record_foreign_hosts(count($inv['foreign']));
        self::record_odd_files($inv);
        if ($inv['residue'] > 0) {
            mtrace('local_intellistream: removed ' . $inv['residue'] . ' stray buffer rewrite or '
                . 'bookkeeping temporary file(s).');
        }
        if ($inv['pulledreaped'] > 0) {
            mtrace('local_intellistream: removed ' . $inv['pulledreaped'] . ' legacy .pulled buffer '
                . 'file(s); their records were already delivered to the pull integration.');
        }

        // Stop tracking a previous directory once nothing of ours is left in it, so
        // the record converges on just the directory in use instead of growing.
        // Deliberately NOT rmdir()ing it as well: unlike the uninstall purge, this
        // runs on a live site where the path was chosen by an administrator and may
        // be theirs to keep. An empty directory left behind is visible and
        // harmless; removing one that somebody is still looking at is not. Only the
        // bookkeeping dot-files this plugin left there go with it, so what is left
        // is genuinely empty and an administrator can remove it.
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
        // so own_files() and the uninstall purge keep reaching its records. One
        // that no longer validates was not part of the pass above (it is not read
        // back off the host), so it is counted on its own.
        $previouscounts = [];
        foreach (config::previous_tracked_dirs() as $previous) {
            $remaining = $inv['entries'][$previous] ?? buffer::entry_count($previous);
            if ($remaining < 0) {
                mtrace('local_intellistream: previous buffer directory "' . $previous
                    . '" could not be read this run; still tracking it.');
                continue;
            }
            if ($remaining === 0) {
                buffer::remove_bookkeeping($previous);
                config::forget_buffer_dir($previous);
                mtrace('local_intellistream: previous buffer directory "' . $previous
                    . '" is drained — no longer tracking it.');
                continue;
            }
            // Parked files are counted under "parked" wherever they are; this count is
            // what is left to ship, so a directory holding only parked files shows
            // none. It stays tracked (it still holds records for privacy and
            // uninstall) until they are dealt with.
            $parkedhere = min($remaining, (int)($inv['parkeddir'][$previous] ?? 0));
            $previouscounts[$previous] = $remaining - $parkedhere;
            if ($remaining === $parkedhere) {
                mtrace('local_intellistream: ' . $parkedhere . ' parked file(s) in a previous buffer directory "'
                    . $previous . '" are held for an administrator (see the status page); nothing else is left '
                    . 'there to ship.');
                continue;
            }
            $remaining -= $parkedhere;
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

        // Publish the measurement for the capture path, here, before any of the
        // early returns below. Capture reads this instead of sizing the directory
        // itself, so it has to be refreshed on every run, including runs that ship
        // nothing (unconfigured, unpaired, load-gated). The status page and
        // get_status read their counts from it too.
        $inv['prev'] = $previouscounts;
        // A run that has not yet been cut short: only the success path sets it again,
        // so no early return, failure or stopped cron can leave a previous run's flag.
        $inv['current']['truncated'] = 0;
        self::publish($dir, $inv, $runstart, buffer::PUBLISH_RUN_START);
        // Make sure the measurement lock exists (the pass above saw whether it does),
        // so web requests rarely have to create it. Not as root: a lock the web server
        // user cannot open for writing would leave requests measuring without it (one
        // at a time through a claim file, see buffer::measure_exclusive()).
        if (!$inv['lock'] && is_dir($dir) && !(function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            buffer::create_lock($dir . '/' . buffer::CAPACITY_LOCK);
        }

        $s3 = s3_client::from_config();
        if (!$s3->is_configured()) {
            self::set_status_string('unconfigured', 'ship_detail_unconfigured');
            mtrace('local_intellistream: S3 not configured — nothing shipped.');
            return;
        }
        // Nothing below this point deletes a record that has not been delivered,
        // with one deliberate exception: a record carrying another site's Site ID
        // is dropped rather than shipped (filter_site_id()). When the buffer is not draining — storage unconfigured (a
        // pull-only site, or one not set up yet), encryption on, unpaired, the load
        // gate closed, or the endpoint failing — it fills up, and at the cap
        // buffer::have_capacity() refuses new files and note_cap_refusal() raises
        // the admin alert. Refusing to write is the bound; deleting undelivered
        // records, which this task used to do at the cap, is not.
        //
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
        // the same "do not drain, do not delete" position as the unpaired case below, so
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
        // Past every return that holds the records on purpose (not configured,
        // encryption, unpaired), and from this run's own inventory, so the decision
        // costs nothing extra: is the closed backlog large enough that waiting for
        // the host load to drop would take capture to its cap?
        $overgate = $scheduled
            ? self::backlog_over_gate($inv['current']['closedbytes'], $dir)
            : null;
        if ($overgate !== null) {
            $inv['current']['bypass'] = $overgate ? 1 : 0;
        }
        if (!health::ship_allowed()) {
            if (!$overgate) {
                // Record the state, do not just trace it. Every other early return
                // here sets a status; this one did not, so the admin status page kept
                // showing the last result — usually `ok` — while shipping had in fact
                // been stalled for hours. Meanwhile the buffer grows toward its cap,
                // where capture starts being refused. An operator needs to see the
                // stall before that point, not after.
                if ($overgate !== null) {
                    self::publish($dir, $inv, $runstart, buffer::PUBLISH_PLAIN);
                }
                self::set_status_string('loadgated', 'ship_detail_loadgated');
                mtrace('local_intellistream: host load above gate — skipping this run.');
                return;
            }
            mtrace('local_intellistream: host load is above the gate, but the closed backlog is over '
                . 'half the buffer cap — shipping anyway, so capture is not refused while the host '
                . 'stays busy.');
        }

        // A previous run DELIVERED files it could not remove, and they are still
        // here. Do not ship: everything this task could pick up now begins with
        // those same files (they are the oldest, so they sort first and are always
        // inside the slice), and re-shipping them is exactly the loop this gate
        // exists to break — the same object key, re-PUT every cron minute, for
        // ever, without ever reaching a newer record.
        //
        // Reap, do not merely wait. These files are ALREADY in object storage, so
        // what they need is removing, not re-sending — and retrying the removal
        // here is the only thing that can lift the gate. Waiting for them to
        // disappear on their own deadlocked: after an administrator fixed the
        // permission the files were still present, so a presence-only gate held for
        // ever and shipping never resumed.
        $reaped = [];
        $stuck = self::reap_undeletable($dirs, $reaped);
        if ($reaped !== []) {
            // What the reap removed was measured at the start of the run: take it
            // off now, so capture does not keep refusing on files that are gone.
            self::forget_removed($inv, $dir, $reaped);
            self::publish($dir, $inv, $runstart, buffer::PUBLISH_PLAIN);
        }
        if ($stuck !== []) {
            // Re-record so the stored count and the stored names describe the SAME
            // set. The count was written by the run that first hit the fault, and a
            // PARTIAL recovery left it frozen at that original total while the names
            // shrank underneath it — so an administrator freeing files one at a time
            // watched a number that never moved and read it as "nothing I do helps".
            // Caveat, stated because it is a real trade: only the tracked window is
            // re-checked here, so with more than MAX_UNDELETABLE_TRACKED stuck this
            // now UNDERSTATES until the gate lifts and the next real ship re-measures
            // the whole set. A number that moves as the fault is fixed, and that is
            // self-correcting, beats one that is frozen and wrong until full recovery.
            self::retain_undeletable($stuck);
            self::set_status_string('undeletable', 'ship_detail_undeletable', [
                'files' => count($stuck),
                'names' => self::name_sample($stuck),
            ]);
            mtrace('local_intellistream: HELD — ' . count($stuck) . ' delivered buffer file(s) '
                . 'still cannot be removed: ' . self::name_sample($stuck) . '. Nothing shipped '
                . 'this run, and nothing has been lost. See the previous alert for what to '
                . 'check; shipping resumes automatically on the first run after they are gone.');
            return;
        }

        // Deliberately the current directory only. The cap governs how much this
        // plugin is allowed to accumulate where it is writing; a previous
        // directory is being drained rather than filled, so counting it toward
        // the cap would make capture refuse new files on account of records that
        // are on their way out.
        self::note_over_cap($inv['current']['bytes']);

        // Oldest first, by the modification times the pass above already read.
        // Sorting with filemtime() inside the comparator re-read two files per
        // comparison, about 2·N·log N stats per run, so shipping slowed as the
        // backlog grew.
        $sizes = [];
        foreach ($inv['closed'] as $entry) {
            $sizes[$entry[1]] = $entry[2];
        }
        $inv['closed'] = [];

        $encsvc = new \local_intellistream\services\encryption_service();
        // What the ship loop shares with ship_batch() and isolate(): the transport,
        // the Site ID every shipped record must carry (the unpaired guard above
        // guarantees it is non-empty here), and the run's running totals.
        $run = [
            's3' => $s3,
            'encsvc' => $encsvc,
            'encenabled' => $encsvc->is_enabled(),
            'expected' => config::site_id(),
            'dir' => $dir,
            'files' => 0,
            'events' => 0,
            'objects' => 0,
            'badrecords' => 0,
            'badfiles' => 0,
            // Basenames of files this run DELIVERED but could not remove. Collected
            // rather than counted, because the next run has to be able to ask "is
            // that specific file still there?" to know whether the fault has cleared.
            'undeletable' => [],
        ];

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
        //
        // Files object storage refused on their own in an earlier run (see
        // isolate()) are held out of the batches and tried one at a time after
        // them, so they cannot hold the rest back while it is decided what they are.
        $suspects = self::suspects();
        $suspectpaths = [];
        $allsizes = $sizes;
        foreach (array_keys($sizes) as $path) {
            if (isset($suspects[basename($path)])) {
                $suspectpaths[] = $path;
                unset($sizes[$path]);
            }
        }
        $newsuspects = [];
        $failure = null;
        // The clock starts here, after the inventory, so a slow inventory never leaves
        // a run with no time to ship; and at least one batch is always tried. Every
        // later step (isolate(), retry_suspects()) stops at the same deadline.
        $run['deadline'] = time() + self::RUN_MAX_SECONDS;
        $run['truncated'] = false;
        $tried = 0;
        $lastpublish = time();
        foreach (self::plan_batches($sizes, self::MAX_BATCH_BYTES) as $batch) {
            if ($tried > 0 && time() >= $run['deadline']) {
                $run['truncated'] = true;
                break;
            }
            $tried++;
            if (time() - $lastpublish >= self::RUN_REPUBLISH_SEC) {
                // A long run keeps the measurement fresh, so web requests go on deciding
                // on it instead of sizing the directory themselves mid-run.
                self::publish($dir, $inv, time(), buffer::PUBLISH_PLAIN);
                $lastpublish = time();
            }
            $shipped = self::ship_batch($run, $inv, $batch['datebucket'], $batch['paths'], $sizes);
            if ($shipped === null) {
                continue;
            }
            $failure = $shipped['ok'] ? null : $shipped['result'];
            if (
                $failure !== null && self::parkable($failure)
                && (self::too_large_batch($failure, $shipped['paths']) || self::rejected_again($shipped['paths'][0]))
            ) {
                // The same batch has now been refused on its content often enough
                // that retrying it whole is how shipping stays wedged, or it was
                // refused as too large, which halving answers at once: find the file.
                $failure = self::isolate(
                    $run,
                    $inv,
                    $batch['datebucket'],
                    $shipped['paths'],
                    $sizes,
                    $newsuspects,
                    $failure
                );
            }
            if ($failure !== null || $run['undeletable'] !== []) {
                // Stop the run here rather than working through the rest of the
                // slice. After a failure the next batch would meet the same fault.
                // After an undeletable file: whatever prevents a removal is a
                // property of the DIRECTORY (its permissions or mount), not of one
                // file, so every remaining batch would be delivered and then also
                // fail to be removed — paying full upload cost for objects that
                // cannot advance the drain. It also bounds how many
                // delivered-but-present files one fault can leave behind, and
                // therefore how much can be delivered twice once the host is fixed:
                // one batch, not the whole slice.
                break;
            }
        }
        if ($failure === null && $run['undeletable'] === [] && $suspectpaths !== []) {
            $failure = self::retry_suspects($run, $inv, $suspectpaths, $allsizes, $suspects, $newsuspects);
        } else {
            foreach ($suspectpaths as $path) {
                $newsuspects[basename($path)] = $suspects[basename($path)];
            }
        }
        self::remember_suspects($newsuspects);
        if ($failure !== null) {
            self::set_status($failure['category'], $failure['detail']);
            mtrace('local_intellistream: ship failed (' . $failure['category']
                . ') — will retry next run.');
            self::publish($dir, $inv, $runstart, buffer::PUBLISH_RUN_END);
            return;
        }
        if (empty($run['truncated'])) {
            // Only a run that got through everything forgets a rejected batch: one cut
            // short by the time limit keeps the search's resume point.
            self::clear_rejected();
        }

        // Republish what is left after shipping, so capture does not keep refusing on
        // the size measured before this run drained the backlog.
        $inv['current']['truncated'] = empty($run['truncated']) ? 0 : 1;
        self::publish($dir, $inv, $runstart, buffer::PUBLISH_RUN_END);

        $files = $run['files'];
        $events = $run['events'];
        $objects = $run['objects'];
        $badrecords = $run['badrecords'];
        $badfiles = $run['badfiles'];
        $undeletable = $run['undeletable'];

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
        // A delivered-but-undeletable file outranks everything else this run did.
        // Recorded BEFORE the ship-proof block on purpose: record_undeletable()
        // is what makes the next run refuse to ship, and $files is now honest, so
        // a run that freed nothing no longer refreshes the ship proof either.
        if ($undeletable !== []) {
            self::record_undeletable($undeletable);
            self::set_status_string('undeletable', 'ship_detail_undeletable', [
                'files' => count($undeletable),
                'names' => self::name_sample($undeletable),
            ]);
            mtrace('local_intellistream: ALERT ' . count($undeletable) . ' buffer file(s) were '
                . 'DELIVERED to object storage but could NOT be removed from the buffer '
                . 'directory: ' . self::name_sample($undeletable) . '. Shipping is STOPPED until '
                . 'they are gone — re-sending them is the only thing this task could otherwise '
                . 'do, and it would re-send the same batch every run for ever without ever '
                . 'reaching newer records. Nothing has been lost. Fix the removal permission: '
                . 'check ownership and the sticky bit on the buffer directory, the uid Moodle '
                . 'cron runs as versus the web server user, an immutable flag on the files '
                . '(lsattr), and whether the filesystem has been remounted read-only. The '
                . 'buffer will grow toward maxbuffergb while this persists.');
            return;
        }

        // Cleared: nothing survived this run, so forget any earlier fault. Written
        // through the same helper so the cumulative keys and the gate agree.
        self::clear_undeletable();

        // Ship-proof: record the fingerprint of the access key we ACTUALLY
        // shipped with, only on a real successful PUT ($files > 0), so the control plane's
        // revoke gate confirms the NEW key WORKS. Access-key fingerprint only; never the secret.
        // Written when it changes, so the revoke gate sees a new key on its first
        // successful run; the time only as a heartbeat (see set_status()).
        if ($files > 0) {
            $fp = substr(hash('sha256', config::access_key()), 0, 12);
            $changed = $fp !== (string)config::get('last_ship_accesskey_fp', '');
            if ($changed) {
                set_config('last_ship_accesskey_fp', $fp, config::COMPONENT);
            }
            if ($changed || time() - (int)config::get('last_ship_ok_time', 0) >= self::STATUS_HEARTBEAT_SEC) {
                set_config('last_ship_ok_time', time(), config::COMPONENT);
            }
        }

        if (!empty($run['truncated'])) {
            // A healthy run that met more backlog than it can send: still ok (it
            // shipped), with the same detail sentence. That the run was cut short is
            // published with the measurement (buffer 'truncated') for the status page
            // and get_status, so it costs no config write and cannot flip the state.
            mtrace('local_intellistream: this run reached its ' . self::RUN_MAX_SECONDS . ' s limit; the rest '
                . 'of the backlog is left for the next run.');
        }
        self::set_status_string('ok', $statuskey, $statusparams);
        mtrace("local_intellistream: shipped {$files} file(s) in {$objects} object(s), {$events} event(s).");
    }

    /**
     * Whether the closed backlog is large enough for the scheduled task to ship
     * through a closed host load gate.
     *
     * The load gate exists so shipping does not add work to a busy host. But a host
     * that stays busy for long enough holds shipping closed until the buffer reaches
     * its cap, and from then on new capture is refused — a loss the gate was never
     * meant to trade for a little CPU. So once the closed backlog passes half the
     * cap, the scheduled task ships anyway. Half, the same fraction at which the
     * historical backfill stops adding to the buffer
     * (exporter::backfill_apply_backpressure(), which compares the whole buffer, a
     * number at least as large as the closed backlog compared here). It keeps doing
     * so until the backlog is back under a quarter of the cap:
     * half the entry level, so a run that drains a little does not close the gate
     * again at the next one, and shipping does not flap on and off around one
     * threshold. The flag carries between runs in the published measurement.
     *
     * @param int $closedbytes The current directory's closed backlog, from the run's inventory.
     * @param string $dir Current buffer directory.
     * @return bool
     */
    private static function backlog_over_gate(int $closedbytes, string $dir): bool {
        $cap = config::max_buffer_bytes();
        $state = buffer::read_capacity($dir);
        $was = $state !== null && $state['bypass'] === 1;
        return $closedbytes > intdiv($cap, 2) || ($was && $closedbytes > intdiv($cap, 4));
    }

    /**
     * s3_client category for a rejection that may be about the request itself: a
     * 4xx that is not an authentication failure (403, `s3_credential_invalid`) or a
     * rate limit (429, `s3_quota_exceeded`). Only some of these are about the bytes
     * (see CONTENT_CODES); those are why a batch holding one such file used to wedge
     * shipping for good.
     */
    const REJECTED_CATEGORY = 's3_other_4xx';

    /**
     * Runs in which the same batch has to be rejected before it is taken apart.
     *
     * Three, because one rejection can be a proxy or endpoint blip and two in a row
     * still can; three consecutive refusals of the same first file, at one run a
     * minute, is a property of the content. Taking a batch apart costs about two
     * uploads per halving, bounded per run (ISOLATE_MAX_PUTS), so doing it a little
     * early is cheap, and never doing it is the wedge. A 413 on more than one file
     * does not wait for this: halving is the answer to it at once.
     */
    const REJECTED_RUNS = 3;

    /**
     * Read, compress and PUT one batch, and remove its files on a 2xx.
     *
     * @param array $run The run's shared state and totals, updated in place.
     * @param array $inv The run's inventory, updated in place as files go.
     * @param string $datebucket From plan_batches().
     * @param string[] $paths The batch.
     * @param array $sizes Path => size from the inventory.
     * @return array|null Null when nothing in the batch could be read; otherwise
     *         ['ok' => bool, 'result' => the s3_client result, 'paths' => the files
     *         actually sent].
     */
    private static function ship_batch(array &$run, array &$inv, string $datebucket, array $paths, array $sizes): ?array {
        $bodies = [];
        $shipped = [];
        $eventcount = 0;
        foreach ($paths as $path) {
            $body = self::file_body($path, $run['expected'], $run['badrecords'], $run['badfiles'], $run['undeletable']);
            if ($body === null) {
                continue;
            }
            // Count events on the plaintext body; encryption replaces the
            // newline-delimited JSONL with a single wrapped blob and would
            // make `substr_count($body, "\n")` collapse to 0.
            $eventcount += substr_count($body, "\n");
            $bodies[] = $body;
            $shipped[] = $path;
        }
        if (!$shipped) {
            return null;
        }
        // A one-file batch is the common case on a quiet site, and it is also
        // how an oversized file is shipped. Assigning the single element rather
        // than imploding it lets copy-on-write hand the string over without
        // duplicating it, so that case costs no more memory than the old
        // one-PUT-per-file code did.
        $payload = count($bodies) === 1 ? $bodies[0] : implode('', $bodies);
        unset($bodies);

        $headers = [];
        if ($run['encenabled']) {
            $payload = $run['encsvc']->encrypt($payload);
            $headers['x-amz-meta-encrypted'] = '1';
            $headers['x-amz-meta-encryption-version'] = 'v1';
        }
        $gz = gzencode($payload, 6);
        unset($payload);
        if ($gz === false) {
            return null;
        }
        // Keyed off the files that actually READ, not the files that were
        // planned, so a file lost to the pull race cannot leave the key
        // claiming content the object does not carry.
        $key = self::batch_object_key($datebucket, $shipped);
        $result = $run['s3']->put($key, $gz, 'application/gzip', $headers);
        if (!$result['ok']) {
            return ['ok' => false, 'result' => $result, 'paths' => $shipped];
        }
        // All-or-nothing, and only after a 2xx: on failure every constituent
        // stays `.closed` for the next run, which rebuilds the same batch under
        // the same deterministic key, so a retry overwrites its own object
        // instead of leaving a duplicate.
        //
        // Credit only what actually LEFT the disk. This loop used to discard
        // every unlink() result and then add count($shipped) — the files it
        // PLANNED to remove — so a host that could not delete them (sticky bit
        // plus a foreign owner, an immutable flag, a read-only remount) left
        // them `.closed` with their mtime untouched. They therefore sorted
        // first again, landed in the next slice again, and batch_object_key()
        // — a pure function of the file NAMES — rebuilt the identical key: the
        // same window was re-PUT every cron minute for ever, no newer file was
        // ever reached, and the run reported `ok` throughout. Measured on one
        // live tenant: 12.4 million counted events per day against zero new
        // rows downstream.
        foreach ($shipped as $shippedpath) {
            if (buffer::remove_file($shippedpath)) {
                $run['files']++;
                self::forget_removed($inv, $run['dir'], [$shippedpath => $sizes[$shippedpath] ?? 0]);
                continue;
            }
            $run['undeletable'][] = basename($shippedpath);
        }
        // The event and object counts are credited in full even when nothing
        // was freed: the PUT returned 2xx, so those records ARE downstream.
        // It is the FILE count — "how much of the buffer did this run drain"
        // — that was the lie, and it is the number last_ship_ok_time and the
        // mtrace depend on.
        $run['events'] += $eventcount;
        $run['objects']++;
        return ['ok' => true, 'result' => $result, 'paths' => $shipped];
    }

    /**
     * Take apart a batch object storage keeps rejecting on its content, so the file
     * that is being rejected can be found and set aside.
     *
     * One file whose content an endpoint (or a proxy in front of it) refuses used to
     * stop shipping for good: it is among the oldest, so it is in the first batch of
     * every run, and the whole batch is refused with it, run after run, while
     * everything behind it waits. Here the batch is halved, oldest half first, and
     * each refused half halved again, so the refused file is found in about two
     * uploads per halving (roughly 22 for a full batch) rather than one upload per
     * file. Halves that are accepted are delivered and removed as usual, as
     * multi-file objects. A file that is refused on its own becomes a suspect: it
     * is held out of the batches from then on (so the rest of the buffer flows again
     * at once) and tried on its own after them (retry_suspects()). It is parked only
     * if it is refused on its own again, in a later run, with the same status and S3
     * error code, while something else IS accepted: two identical refusals in two
     * runs rule out a blip, only a content error code (or a 413) counts at all
     * (parkable()), and the acceptance rules out an endpoint that is refusing
     * everything (a bucket that has gone, a bad region), which is a site-wide fault
     * to report, not a reason to set any file aside.
     *
     * After two single-file refusals with nothing accepted during this attempt it
     * stops, because that already looks site-wide. The work is also bounded per run
     * by ISOLATE_MAX_PUTS uploads and ISOLATE_MAX_SECONDS, so one bad batch can
     * never hold the ship task for long. When a bound stops it, the refusal is
     * reported, and the next run resumes the search at once (resume_rejected()).
     *
     * @param array $run The run's shared state and totals, updated in place.
     * @param array $inv The run's inventory, updated in place.
     * @param string $datebucket From plan_batches().
     * @param string[] $paths The rejected batch.
     * @param array $sizes Path => size from the inventory.
     * @param array $suspects Basename => refusal signature of each file refused on its own; added to.
     * @param array|null $refusal The whole batch's refusal, reported if the run's allowance is spent.
     * @return array|null A fault to report, or null when the run may carry on.
     */
    private static function isolate(
        array &$run,
        array &$inv,
        string $datebucket,
        array $paths,
        array $sizes,
        array &$suspects,
        ?array $refusal = null
    ): ?array {
        $before = $run['objects'];
        $alone = 0;
        // The allowance is the run's, shared by every batch the run takes apart, so a
        // run that meets several refused batches still stops at the limit.
        if (!isset($run['isolateputs'])) {
            $run['isolateputs'] = self::ISOLATE_MAX_PUTS;
            $run['isolateuntil'] = min(time() + self::ISOLATE_MAX_SECONDS, $run['deadline'] ?? PHP_INT_MAX);
        }
        $queue = self::halves($paths);
        $last = $refusal;
        while ($queue !== []) {
            if ($run['isolateputs'] <= 0 || time() >= $run['isolateuntil']) {
                // Out of this run's allowance: report the refusal and pick the
                // search up next run from the oldest file not yet delivered.
                self::resume_rejected($queue[0][0]);
                mtrace('local_intellistream: the search for the file object storage refuses paused at this '
                    . "run's limit; it continues next run.");
                return $last;
            }
            $part = array_shift($queue);
            $run['isolateputs']--;
            $shipped = self::ship_batch($run, $inv, $datebucket, $part, $sizes);
            if ($shipped === null || $shipped['ok']) {
                if ($run['undeletable'] !== []) {
                    // As after any batch, run() stops at the undeletable file.
                    return null;
                }
                continue;
            }
            if (!self::parkable($shipped['result'])) {
                return $shipped['result'];   // A different fault: report it as usual.
            }
            $last = $shipped['result'];
            if (count($shipped['paths']) > 1) {
                array_unshift($queue, ...self::halves($shipped['paths']));
                continue;
            }
            $path = $shipped['paths'][0];
            $suspects[basename($path)] = self::refusal_signature($shipped['result']);
            mtrace('local_intellistream: object storage refused buffer file ' . basename($path)
                . ' on its own (' . $shipped['result']['detail'] . '); it is held out of the batches and '
                . 'tried on its own again next run.');
            if (++$alone >= 2 && $run['objects'] === $before) {
                // Refused alone twice with nothing accepted: that looks site-wide, so
                // report it and stop the run rather than take the next batch apart.
                return $last;
            }
        }
        return null;
    }

    /**
     * Split a batch into its older and newer half, keeping the order.
     *
     * @param string[] $paths Oldest first.
     * @return string[][] One part for a single file, else two.
     */
    private static function halves(array $paths): array {
        $paths = array_values($paths);
        if (count($paths) < 2) {
            return [$paths];
        }
        $mid = intdiv(count($paths), 2);
        return [array_slice($paths, 0, $mid), array_slice($paths, $mid)];
    }

    /**
     * Whether a refusal says the batch was too large, which halving answers without
     * waiting for it to be refused again: a 413, or S3's EntityTooLarge (sent as a
     * 400), on more than one file. The same on a single file is left to the usual rule.
     *
     * @param array $result From s3_client::put().
     * @param string[] $paths The refused batch.
     * @return bool
     */
    private static function too_large_batch(array $result, array $paths): bool {
        $toolarge = (int)($result['status'] ?? 0) === 413 || (string)($result['code'] ?? '') === 'EntityTooLarge';
        return $toolarge && count($paths) > 1;
    }

    /**
     * Try the files an earlier run found refused on their own, one at a time, and
     * park the ones refused again while something else was accepted this run.
     *
     * @param array $run The run's shared state and totals, updated in place.
     * @param array $inv The run's inventory, updated in place.
     * @param string[] $paths Suspect files, oldest first.
     * @param array $sizes Path => size from the inventory.
     * @param array $known Basename => signature of each suspect's earlier refusal.
     * @param array $suspects Basename => signature of the files that stay suspects; added to.
     * @return array|null A failure to report, or null.
     */
    private static function retry_suspects(
        array &$run,
        array &$inv,
        array $paths,
        array $sizes,
        array $known,
        array &$suspects
    ): ?array {
        $failure = null;
        foreach ($paths as $i => $path) {
            if (isset($run['deadline']) && time() >= $run['deadline']) {
                // Out of this run's time: the rest stay suspects for the next run.
                foreach (array_slice($paths, $i) as $rest) {
                    $suspects[basename($rest)] = $known[basename($rest)] ?? '';
                }
                $run['truncated'] = true;
                break;
            }
            $name = basename($path);
            $plan = self::plan_batches([$path => $sizes[$path] ?? 0], self::MAX_BATCH_BYTES);
            $shipped = self::ship_batch($run, $inv, $plan[0]['datebucket'], [$path], $sizes);
            if ($shipped === null || $shipped['ok']) {
                if ($run['undeletable'] !== []) {
                    // As after any batch, run() stops at the undeletable file.
                    foreach (array_slice($paths, $i + 1) as $rest) {
                        $suspects[basename($rest)] = $known[basename($rest)] ?? '';
                    }
                    return null;
                }
                continue;
            }
            if (!self::parkable($shipped['result'])) {
                foreach (array_slice($paths, $i) as $rest) {
                    $suspects[basename($rest)] = $known[basename($rest)] ?? '';
                }
                return $shipped['result'];
            }
            // The same answer both times (status and S3 error code), something else
            // accepted this run, and one of this plugin's own files (the only kind it
            // renames): that is the file, not the endpoint.
            $signature = self::refusal_signature($shipped['result']);
            if ($run['objects'] > 0 && $signature === ($known[$name] ?? null) && strpos($name, 'events-') === 0) {
                self::park($path, $inv, $run['dir'], $shipped['result']);
                continue;
            }
            $suspects[$name] = $signature;
            $failure = $shipped['result'];
        }
        return $run['objects'] > 0 ? null : $failure;
    }

    /**
     * Whether a refusal can be about the file's content, and so count towards
     * setting it aside. Only REJECTED_CATEGORY, and not a 408 (request timeout) or
     * a 409 (conflict), which say nothing about the bytes.
     *
     * @param array $result From s3_client::put().
     * @return bool
     */
    private static function parkable(array $result): bool {
        $status = (int)($result['status'] ?? 0);
        if ($status === 413) {
            return true;   // Payload too large: a property of these bytes, whoever says it.
        }
        return ($result['category'] ?? '') === self::REJECTED_CATEGORY
            && !in_array($status, [408, 409], true)
            && in_array((string)($result['code'] ?? ''), self::CONTENT_CODES, true);
    }

    /**
     * S3 error codes that describe the request body itself, and so can single out
     * one file. Everything else a 4xx can carry is left out on purpose: a timeout
     * (`RequestTimeout` is a 400, not a 408), a truncated upload (`IncompleteBody`),
     * an argument or request-document error (`InvalidArgument`, `MalformedXML`: the
     * request, not the bytes, for a PUT with fixed headers and an opaque body),
     * an expired or wrong credential (`ExpiredToken`, `AccessDenied`,
     * `SignatureDoesNotMatch`), throttling (`SlowDown`), a missing bucket, and a 400
     * with no S3 code at all (a proxy's own error page) all depend on the moment or
     * the site, not on the file, and parking a file for one would hold good records
     * until a person noticed.
     */
    const CONTENT_CODES = ['EntityTooLarge', 'BadDigest', 'InvalidDigest'];

    /**
     * Longest a run keeps starting new batches, taking files apart or retrying suspects
     * (seconds), counted from the end of the inventory. With at most one upload in
     * flight past it, a run stays inside the ship lock's lifetime (SHIP_LOCK_SECONDS).
     */
    public const RUN_MAX_SECONDS = 600;

    /** How often a long run republishes the measurement (seconds); under SHIP_IDLE_SEC. */
    public const RUN_REPUBLISH_SEC = 60;

    /** Lifetime of the ship lock (seconds): RUN_MAX_SECONDS plus the inventory and in-flight uploads. */
    public const SHIP_LOCK_SECONDS = 1800;

    /** Longest a stored ship status that has not changed goes without being rewritten. */
    public const STATUS_HEARTBEAT_SEC = 300;

    /** Most uploads isolate() spends in one run, across every batch it takes apart. */
    public const ISOLATE_MAX_PUTS = 24;

    /** Most seconds isolate() spends in one run, across every batch it takes apart. */
    public const ISOLATE_MAX_SECONDS = 120;

    /** Names of each kind of odd buffer entry kept for the log. */
    private const ODD_NAMES_KEPT = 3;

    /**
     * What a refusal said, to compare two refusals of the same file.
     *
     * @param array $result From s3_client::put().
     * @return string
     */
    private static function refusal_signature(array $result): string {
        return (int)($result['status'] ?? 0) . ':' . (string)($result['code'] ?? '');
    }

    /**
     * The files refused on their own by an earlier run, with what they were refused
     * with (refusal_signature()).
     *
     * @return array<string, string> Basename => signature.
     */
    private static function suspects(): array {
        $out = [];
        foreach (array_filter(explode("\n", (string)config::get('ship_rejected_isolated', ''))) as $line) {
            $parts = explode("\t", $line, 2);
            $out[$parts[0]] = $parts[1] ?? '';
        }
        return $out;
    }

    /**
     * Store the suspect set, bounded like the undeletable window. On change only.
     *
     * @param array $suspects Basename => refusal signature.
     * @return void
     */
    private static function remember_suspects(array $suspects): void {
        $lines = [];
        foreach (array_slice($suspects, 0, self::MAX_UNDELETABLE_TRACKED, true) as $name => $signature) {
            $lines[] = $name . "\t" . $signature;
        }
        $value = implode("\n", $lines);
        if ((string)config::get('ship_rejected_isolated', '') === $value) {
            return;
        }
        if ($value === '') {
            unset_config('ship_rejected_isolated', config::COMPONENT);
        } else {
            set_config('ship_rejected_isolated', $value, config::COMPONENT);
        }
    }

    /**
     * Rename a file object storage refuses on its own to its parked name.
     *
     * @param string $path Closed buffer file.
     * @param array $inv The run's inventory, updated in place.
     * @param string $dir Current buffer directory.
     * @param array|null $result The refusal, for the log line.
     * @return void
     */
    private static function park(string $path, array &$inv, string $dir, ?array $result): void {
        $to = $path . buffer::PARKED_SUFFIX;
        // Never over anything already there, and only a plain file: the rename would
        // otherwise replace whatever holds the parked name.
        if (!buffer::is_safe_file($path) || @lstat($to) !== false || !@rename($path, $to)) {
            mtrace('local_intellistream: ALERT could not park ' . basename($path) . ', which object storage '
                . 'refuses on its own; it stays queued.');
            return;
        }
        if (isset($inv['current']['parked'])) {
            $inv['current']['parked']++;
            $mtime = @filemtime($to);
            self::note_parked_at($inv, $mtime === false ? time() : (int)$mtime);
            if (dirname($path) !== $dir) {
                $inv['current']['parkedprev']++;
                $inv['parkeddir'][dirname($path)] = ($inv['parkeddir'][dirname($path)] ?? 0) + 1;
            }
        }
        if (dirname($path) === $dir && isset($inv['current']['closed'])) {
            $inv['current']['closed'] = max(0, $inv['current']['closed'] - 1);
            if (strpos(basename($path), 'events-') === 0) {
                $size = (int)@filesize($to);
                $inv['current']['closedbytes'] = max(0, $inv['current']['closedbytes'] - $size);
            }
        }
        // English on purpose: cron output is a log stream read by operators, not UI.
        mtrace('local_intellistream: ALERT object storage refused buffer file ' . basename($path)
            . ' on its own (' . ($result['detail'] ?? 'rejected') . '), so it has been PARKED as ' . basename($to)
            . ' in "' . dirname($path) . '" and shipping continues without it. Nothing has been deleted: it '
            . 'stays on disk (counting against the buffer cap while in the current buffer directory). Find out '
            . 'why it is refused; removing the '
            . buffer::PARKED_SUFFIX . ' suffix queues it again.');
    }

    /**
     * Count a rejected batch, by its first (oldest) file, across runs.
     *
     * @param string $firstpath The batch's first file.
     * @return bool Whether it has now been rejected REJECTED_RUNS times.
     */
    private static function rejected_again(string $firstpath): bool {
        $name = basename($firstpath);
        $parts = explode("\t", (string)config::get('ship_rejected', ''), 2);
        $runs = ($parts[1] ?? null) === $name ? (int)$parts[0] + 1 : 1;
        // One key, so a refused run costs one config write for this, not two.
        set_config('ship_rejected', $runs . "\t" . $name, config::COMPONENT);
        return $runs >= self::REJECTED_RUNS;
    }

    /**
     * Forget a rejected batch once shipping gets past it. On change only: this runs
     * at the end of every healthy run, and set_config() purges this plugin's cache.
     *
     * @return void
     */
    private static function clear_rejected(): void {
        if (config::get('ship_rejected', null) !== null) {
            unset_config('ship_rejected', config::COMPONENT);
        }
    }

    /**
     * Arrange for the next run to resume a search isolate() had to pause: the batch
     * that next run starts with (the oldest file not yet delivered) is taken apart
     * as soon as it is refused again, instead of after REJECTED_RUNS more refusals.
     *
     * @param string $firstpath The oldest file still in the search.
     * @return void
     */
    private static function resume_rejected(string $firstpath): void {
        set_config('ship_rejected', (self::REJECTED_RUNS - 1) . "\t" . basename($firstpath), config::COMPONENT);
    }

    /**
     * Read one closed file for shipping: its plaintext body with any record for
     * another Site ID dropped, or null when there is nothing to ship from it.
     *
     * @param string $path Closed buffer file.
     * @param string $expected Current Site ID.
     * @param int $badrecords Incremented by the records dropped for a Site ID mismatch.
     * @param int $badfiles Incremented when every record was a mismatch and the file went.
     * @param string[] $undeletable Such a file that could not be removed is appended.
     * @return string|null
     */
    private static function file_body(
        string $path,
        string $expected,
        int &$badrecords,
        int &$badfiles,
        array &$undeletable
    ): ?string {
        $body = buffer::safe_contents($path);
        if ($body === false) {
            // Vanished between the scan and here — pull_export commits by removing
            // a drained file, and it races us for the same closed files — or it was
            // replaced by something that is not a plain single-linked file, which
            // safe_contents() refuses to read. Either way it is not ours any more.
            return null;
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
            // Count it only if it actually went: an unremovable mismatch
            // file used to be re-counted into badrecords/badfiles, rewrite
            // last_sitemismatch_* and re-fire the ALERT below on EVERY run,
            // for ever, because $badfiles++ ran whatever unlink did.
            if (buffer::remove_file($path)) {
                $badfiles++;
            } else {
                $undeletable[] = basename($path);
            }
            return null;
        }
        // A file can end mid-line: buffer::append() drops a record whose
        // fwrite came up short, but the partial bytes are already on disk.
        // On its own that is one malformed line the middleware skips
        // per-line; concatenated without this guard the fragment would fuse
        // with the next file's first record and lose TWO.
        if (substr($body, -1) !== "\n") {
            $body .= "\n";
        }
        return $body;
    }

    /**
     * Publish the run's measurement for the capture path, the status page and
     * get_status: the current directory's sizes and counts, and what is left in each
     * previous directory. Stamped with the time passed in: the run's start, the moment
     * its inventory describes, or, for a long run's republish, the time of the
     * republish (the counts then already exclude what the run has shipped).
     *
     * @param string $dir Current buffer directory.
     * @param array $inv From inventory().
     * @param int $runstart The time the measurement describes (see above).
     * @param int $mode One of buffer::PUBLISH_*.
     * @return void
     */
    private static function publish(string $dir, array $inv, int $runstart, int $mode): void {
        $fields = $inv['current'] + ['prev' => $inv['prev'] ?? []];
        buffer::publish_capacity($dir, $fields, buffer::read_capacity($dir), $runstart, $mode);
    }

    /**
     * Take files that left the disk off the run's measurement, so each republish
     * describes what is still there.
     *
     * @param array $inv From inventory(), updated in place.
     * @param string $dir Current buffer directory.
     * @param array $removed Path => size in bytes.
     * @return void
     */
    private static function forget_removed(array &$inv, string $dir, array $removed): void {
        foreach ($removed as $path => $size) {
            $name = basename($path);
            $parent = dirname($path);
            if (isset($inv['prev'][$parent]) && strpos($name, 'events-') === 0) {
                $inv['prev'][$parent] = max(0, $inv['prev'][$parent] - 1);
            }
            if ($parent !== $dir) {
                continue;
            }
            $kind = buffer::entry_kind($name);
            if (!isset($inv['current'][$kind])) {
                continue;
            }
            $inv['current'][$kind] = max(0, $inv['current'][$kind] - 1);
            if (strpos($name, 'events-') === 0) {
                $inv['current']['bytes'] = max(0, $inv['current']['bytes'] - $size);
                if ($kind === 'closed') {
                    $inv['current']['closedbytes'] = max(0, $inv['current']['closedbytes'] - $size);
                }
            }
        }
    }

    /**
     * One pass over the buffer directories: everything run() needs to know about
     * them, and the housekeeping that used to take a glob of its own each time.
     *
     * Each directory is read once, entry by entry, and each entry that matters is
     * stat-ed once (see scan_entry()). What is kept is small whatever the backlog:
     * counts and byte totals, the oldest MAX_FILES_PER_RUN closed files across all
     * the directories (buffer::keep_oldest()), and the names of the rare entries
     * the status page reports (unsafe, unowned, unreadable). The previous
     * glob-per-question code held every closed file's path, and sorted them all
     * with two stats per comparison.
     *
     * @param string[] $dirs Existing directories to drain, current first.
     * @param string $current The current buffer directory.
     * @return array{closed:array, current:array, entries:array, foreign:array, unreadable:string[],
     *               unowned:string[], unsafe:string[], residue:int, pulledreaped:int, lock:bool}
     *         `closed` is [mtime, path, size] entries, oldest first; `current` is
     *         the current directory's measurement, shaped as buffer::measure_dir();
     *         `entries` is directory => how many of this plugin's entries are left
     *         in it, as buffer::entry_count() counts them, or -1 when it could not
     *         be read.
     */
    private static function inventory(array $dirs, string $current): array {
        $inv = [
            'closed' => [],
            'current' => ['bytes' => 0, 'closed' => 0, 'closedbytes' => 0, 'active' => 0, 'pulled' => 0, 'parked' => 0,
                'parkedprev' => 0, 'parkedat' => 0],
            'entries' => [],
            'parkeddir' => [],
            'foreign' => [],
            'unreadable' => [],
            'unowned' => [],
            'unsafe' => [],
            'oddcount' => ['unreadable' => 0, 'unowned' => 0, 'unsafe' => 0],
            'residue' => 0,
            'pulledreaped' => 0,
            'lock' => false,
        ];
        foreach ($dirs as $target) {
            $scan = ['current' => $target === $current, 'entries' => 0, 'parked' => 0, 'promoted' => []];
            $read = buffer::each_entry($target, function (string $name, string $path) use (&$inv, &$scan) {
                self::scan_entry($name, $path, $inv, $scan);
            });
            // A directory that could not be read is not known to be empty: -1 keeps
            // run() from forgetting it (and taking it out of privacy and uninstall).
            $inv['entries'][$target] = $read ? $scan['entries'] : -1;
            $inv['parkeddir'][$target] = $scan['parked'];
        }
        $inv['closed'] = $inv['closed'] ? buffer::oldest_first($inv['closed'], self::MAX_FILES_PER_RUN) : [];
        return $inv;
    }

    /**
     * Classify one directory entry for inventory(), and do its housekeeping.
     *
     * The same rules the separate globs applied, entry by entry:
     *
     *  - a rewrite or bookkeeping temporary is removed once RESIDUE_STRAY_SEC
     *    old (a link at once, by the link), as buffer::purge_residue() does;
     *  - a dead worker's pointer is removed (buffer::reclaim_pointer());
     *  - anything under a buffer-shaped name that is not a plain, single-linked
     *    file is reported and never touched (buffer::unsafe_files());
     *  - a stale active file is promoted to `.closed` (the idle sweep, see
     *    promote_if_stale());
     *  - a legacy `.pulled` file is removed: its records were delivered to the
     *    puller before it got that name, so removing it loses nothing, and
     *    nothing else ever would (db/upgrade.php cleared the ones present then);
     *  - a parked file is counted and left alone (see park()).
     *
     * A file promoted here can come back from the same directory read under its new
     * name (whether a renamed entry reappears mid-read is unspecified), so the new
     * names are remembered and a second sighting is ignored.
     *
     * @param string $name Entry name.
     * @param string $path Full path.
     * @param array $inv Run inventory, updated in place.
     * @param array $scan This directory's scan state, updated in place.
     * @return void
     */
    private static function scan_entry(string $name, string $path, array &$inv, array &$scan): void {
        $kind = buffer::entry_kind($name);
        if ($kind === 'lock' && $scan['current']) {
            $inv['lock'] = true;
            return;
        }
        if ($kind === null || $kind === 'state' || $kind === 'lock') {
            return;
        }
        if ($kind === 'pointer') {
            buffer::reclaim_pointer($path);
            return;
        }
        $st = buffer::safe_stat($path);
        if ($kind === 'residue') {
            if ($st === null && buffer::is_lock_temp($path)) {
                // The lock's creation temporary, still sharing the lock's inode (its
                // creator was killed between link and unlink): removing the name
                // never affects the lock.
                clearstatcache();
                $st = @lstat($path) ?: null;
            }
            $islink = $st === null && is_link($path);
            if ($st === null && !$islink) {
                // A FIFO, a directory or a hard link at a temporary's name: never
                // removed by name, reported like any other unsafe entry.
                if ($name[0] !== '.' && @lstat($path) !== false) {
                    self::note_odd($inv, 'unsafe', $name);
                    $scan['entries']++;
                }
                return;
            }
            $stray = $islink || (time() - (int)$st['mtime']) >= buffer::RESIDUE_STRAY_SEC;
            if ($stray && @unlink($path)) {
                $inv['residue']++;
                return;
            }
            $scan['entries']++;
            return;
        }
        if ($st === null) {
            if (@lstat($path) !== false) {
                self::note_odd($inv, 'unsafe', $name);
                $scan['entries']++;
            }
            return;
        }
        if ($kind === 'other') {
            return;
        }
        $own = strpos($name, 'events-') === 0;
        if ($kind === 'active') {
            if (!$own) {
                self::note_odd($inv, 'unowned', $name);
                return;
            }
            $scan['entries']++;
            $promoted = self::promote_if_stale($path, $name, $st, $inv);
            if ($promoted === null) {
                self::count_current($inv, $scan, 'active', $st, true);
                return;
            }
            $scan['promoted'][$promoted] = true;
            $path .= '.closed';
            $kind = 'closed';
        } else if ($kind === 'closed' && isset($scan['promoted'][$name])) {
            return;
        }
        if ($kind === 'pulled') {
            if (!$own) {
                return;
            }
            if (buffer::remove_file($path)) {
                $inv['pulledreaped']++;
                return;
            }
        }
        if ($kind === 'closed') {
            buffer::keep_oldest($inv['closed'], [(int)$st['mtime'], $path, (int)$st['size']], self::MAX_FILES_PER_RUN);
        }
        if ($own) {
            $scan['entries'] += $kind === 'closed' && isset($scan['promoted'][basename($path)]) ? 0 : 1;
        }
        self::count_current($inv, $scan, $kind, $st, $own);
    }

    /**
     * Keep the modification time of the oldest parked file, for the status page.
     *
     * @param array $inv Run inventory, updated in place.
     * @param int $mtime One parked file's modification time.
     * @return void
     */
    private static function note_parked_at(array &$inv, int $mtime): void {
        if (!isset($inv['current']['parkedat'])) {
            return;
        }
        $known = (int)$inv['current']['parkedat'];
        $inv['current']['parkedat'] = $known === 0 ? $mtime : min($known, $mtime);
    }

    /**
     * Add one file to the current directory's measurement.
     *
     * @param array $inv Run inventory, updated in place.
     * @param array $scan This directory's scan state, updated in place.
     * @param string $kind 'active', 'closed', 'pulled' or 'parked'.
     * @param array $st The file's lstat().
     * @param bool $own Whether it is one of this plugin's `events-` files.
     * @return void
     */
    private static function count_current(array &$inv, array &$scan, string $kind, array $st, bool $own): void {
        if ($kind === 'parked' && $own) {
            self::note_parked_at($inv, (int)$st['mtime']);
        }
        if ($kind === 'parked' && $own && !$scan['current']) {
            // A parked file waits for a person wherever it is, so the count the status
            // page and get_status show covers every scanned directory; only its bytes
            // are the current directory's alone, like every other byte of the cap.
            // parkedprev keeps that part separately, so an exact measurement of the
            // current directory alone (buffer::measure()) can carry it forward.
            $inv['current']['parked']++;
            $inv['current']['parkedprev']++;
            $scan['parked']++;
            return;
        }
        if (!$scan['current'] || (!$own && $kind !== 'closed')) {
            return;
        }
        $inv['current'][$kind]++;
        if ($own) {
            $inv['current']['bytes'] += (int)$st['size'];
            if ($kind === 'closed') {
                $inv['current']['closedbytes'] += (int)$st['size'];
            }
        }
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
     * The directory scan already judged this path, and this re-checks immediately
     * before the rename. That is not redundancy for its own sake: the buffer
     * directory is inside moodledata, so anything able to write there as the web
     * user could replace an approved plain file with a link in between. Renaming it
     * would then hand a link a name the shipper treats as ready to send.
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
     * @return bool Whether it now has its `.closed` name.
     */
    private static function promote(string $path): bool {
        if (!buffer::is_safe_file($path)) {
            return false;
        }
        return @rename($path, $path . '.closed');
    }

    /**
     * Promote an active `events-` file to `.closed` if it is stale, so the shipper
     * picks it up.
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
     * run(), pull_export and buffer::delete_user_records() all rely on.
     *
     * A THIRD case exists and used to have no exit at all: an `events-*.jsonl`
     * whose name parse_name() cannot read. It was skipped here, so it was never
     * promoted and never shipped, while its bytes still counted against the cap.
     * A stuck file therefore displaced genuine unshipped records permanently.
     * Such a name means unknown ownership, which is already treated as foreign
     * above, so judge it the same way: on mtime alone. It then ships like any other
     * closed file (unreadable CONTENT is skipped line-by-line by the middleware,
     * which is the existing and correct behaviour).
     *
     * A `*.jsonl` that is not `events-*` is not ours — nothing in this plugin
     * writes one (buffer::own_file_patterns()). scan_entry() counts and reports it
     * but deliberately leaves it alone: shipping or deleting a file we did not
     * write is not ours to do. The cap does not measure it, so it cannot displace
     * real data either.
     *
     * @param string $path Active file.
     * @param string $name Its basename (starts `events-`).
     * @param array $st Its lstat() from the scan.
     * @param array $inv Run inventory: `foreign` (host ids seen holding active
     *        files) and `unreadable` (names reclaimed by idle time alone) are
     *        updated in place.
     * @return string|null The file's new basename when it was promoted.
     */
    private static function promote_if_stale(string $path, string $name, array $st, array &$inv): ?string {
        $idlefor = time() - (int)$st['mtime'];
        $parsed = buffer::parse_name($name);
        if ($parsed === null) {
            if ($idlefor >= self::remote_idle_sec()) {
                self::note_odd($inv, 'unreadable', $name);
                return self::promote($path) ? $name . '.closed' : null;
            }
            return null;
        }
        $own = $parsed['hostid'] !== null && $parsed['hostid'] === buffer::host_id();
        if ($own) {
            $stale = !self::pid_alive($parsed['pid']) || $idlefor >= self::SHIP_IDLE_SEC;
        } else {
            // Includes a legacy name with no host component: unknown origin
            // is treated as foreign, which is the safe direction (it may be
            // a not-yet-upgraded node's file, and the only cost of being
            // wrong is a slower reclaim).
            if ($parsed['hostid'] !== null) {
                $inv['foreign'][$parsed['hostid']] = true;
            }
            $stale = $idlefor >= self::remote_idle_sec();
        }
        return ($stale && self::promote($path)) ? $name . '.closed' : null;
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
     * Report buffer files whose names the scan could not read, or that it will not touch.
     *
     * Both classes were previously invisible: nothing logged them, nothing counted
     * them, and the only way to discover one was to list the directory by hand —
     * which is why the disk-cap interaction went unnoticed. They are reported
     * differently because their remedies differ:
     *
     *  - `unreadable` is an EVENT. The file has just been reclaimed, so it is about
     *    to ship and the condition is self-healing; the record persists (like
     *    last_undeletable_time) so that an admin who was not watching cron still sees that
     *    something odd happened — a crashed rotation, or a hand-edited buffer.
     *  - `unowned` is a STATE. Nothing here will ever remove that file, so it is
     *    written on change (like foreign_hosts) and stays on the status page until
     *    an operator clears it.
     *
     * Names are logged, not just counted, because the name is the only thing that
     * says where the file came from. Capped at three per class: a partial restore
     * could drop thousands in, and a flooded cron log is its own outage.
     *
     * @param array $inv The run's inventory: the first few names of each kind
     *        (unreadable: reclaimed by mtime alone; unowned: not written by this
     *        plugin; unsafe: not plain single-linked files) and 'oddcount'.
     */
    private static function record_odd_files(array $inv): void {
        $count = $inv['oddcount'];
        if ($count['unreadable'] > 0) {
            set_config('last_unreadable_count', $count['unreadable'], config::COMPONENT);
            set_config('last_unreadable_time', time(), config::COMPONENT);
            mtrace('local_intellistream: reclaimed ' . $count['unreadable']
                . ' buffer file(s) whose name could not be read, by idle time alone: '
                . self::name_sample($inv['unreadable'], $count['unreadable']) . '. They will ship on this run. This is '
                . 'not normal — a rotation may have crashed, or something wrote into the '
                . 'buffer directory by hand.');
        }

        if ((int)config::get('unowned_files', 0) !== $count['unowned']) {
            set_config('unowned_files', $count['unowned'], config::COMPONENT);
            set_config('unowned_files_time', time(), config::COMPONENT);
        }
        if ($count['unowned'] > 0) {
            mtrace('local_intellistream: ' . $count['unowned'] . ' file(s) in the buffer '
                . 'directory were not written by this plugin and are being left alone: '
                . self::name_sample($inv['unowned'], $count['unowned']) . '. They are excluded from the buffer disk '
                . 'cap, so they cannot displace unshipped data, but nothing here will '
                . 'remove them — delete them or move them out of the buffer directory.');
        }

        // STATE, like unowned: nothing here will ever remove one of these, so the
        // count is written on change and stays visible until an operator acts.
        if ((int)config::get('unsafe_files', 0) !== $count['unsafe']) {
            set_config('unsafe_files', $count['unsafe'], config::COMPONENT);
            set_config('unsafe_files_time', time(), config::COMPONENT);
        }
        if ($count['unsafe'] > 0) {
            mtrace('local_intellistream: ALERT ' . $count['unsafe'] . ' entr(y/ies) in the '
                . 'buffer directory are not plain files and are being left strictly alone: '
                . self::name_sample($inv['unsafe'], $count['unsafe']) . '. NOTHING has been read, written, shipped or '
                . 'deleted through them. A symlink, FIFO or directory here can only have been '
                . 'created by something with write access as the web server user — treat it as '
                . 'a possible attempt to make this plugin read or overwrite a file elsewhere. A '
                . 'hard link is more often innocent (a moodledata restore made with cp -al or '
                . 'rsync --link-dest); replace it with a real copy so its records can ship.');
        }
    }

    /**
     * How many delivered-but-unremovable basenames the gate tracks by name.
     *
     * The gate needs to re-ask "is that file still there?", which needs names —
     * but the whole point of the fault is that it is usually the DIRECTORY that is
     * at fault, not one file, so the first run under it can produce
     * MAX_FILES_PER_RUN (2,000) of them. Persisting 2,000 basenames would put
     * ~100 KB into a value that config::enabled() re-reads on every page render of
     * the capture path, which is a second outage bolted onto the first.
     *
     * So the COUNT is exact and the tracked NAMES are the oldest few. The gate
     * re-attempts removal of the tracked ones on every run and lifts once they all
     * go, so an administrator who fixes the host sees shipping resume on the next
     * run with no duplicate object at all.
     *
     * Files beyond the tracked window are not named, so once the gate lifts they
     * are picked up again by the next scan and delivered a second time. That is bounded to ONE batch,
     * because run() stops at the first batch whose removals fail, and the
     * middleware is idempotent on the record id — against one duplicate delivery
     * per cron minute, indefinitely, before any of this existed.
     */
    const MAX_UNDELETABLE_TRACKED = 8;

    /**
     * Record that files were delivered to object storage but could not be removed.
     *
     * Cumulative: it persists so that a later
     * successful run flipping `ship_state` back to `ok` cannot hide the fact that
     * this happened, and status_class() ranks it as a problem on the strength of
     * the count alone. Unlike `last_sitemismatch_*` — three keys with no reader
     * anywhere in this plugin — every key written here is read: by the gate in
     * run(), by status.php, and by the external status service.
     *
     * `last_undeletable_names` holds the tracked subset the gate re-checks;
     * `last_undeletable_count` is the total the reporting subsystem saw, which may
     * be larger than the tracked subset.
     *
     * Public because there are two reporting subsystems, not one. The pull
     * integration commits by removing a drained `.closed` file too, and an
     * unremovable file there makes the caller re-receive the same records on every
     * pull — the same fault, on a site that may have no object storage configured
     * at all and therefore never reaches the shipper's own removal path. Names are
     * MERGED rather than replaced so whichever subsystem reports second does not
     * erase the other's evidence, and so the gate keeps re-checking both.
     *
     * The incoming names go FIRST in the merge, and that ordering is load-bearing,
     * not cosmetic. Keeping the already-stored ones first reintroduced the very
     * defect this whole change fixes: once the tracked names had been removed but
     * OTHER files were still stuck, the window stayed full of the stale names, the
     * gate saw none of them on disk, lifted, shipped, failed to remove again, and
     * re-stored the same stale window — an unbounded re-ship loop with a green
     * gate. New names first means the window always contains at least one file that
     * is genuinely still there, so the gate holds until the fault is really gone.
     *
     * Written unconditionally rather than on-change, because a caller only reaches
     * this having actually failed to remove something, and the timestamp has to
     * move so an operator can see it is still happening rather than historical.
     *
     * @param string[] $names Basenames, in the order encountered (oldest first).
     * @param int|null $total How many the caller saw; defaults to count($names).
     */
    public static function record_undeletable(array $names, ?int $total = null): void {
        $known = array_filter(explode("\n", (string)config::get('last_undeletable_names', '')));
        $merged = array_values(array_unique(array_merge($names, $known)));
        set_config('last_undeletable_time', time(), config::COMPONENT);
        set_config('last_undeletable_count', $total ?? count($names), config::COMPONENT);
        set_config(
            'last_undeletable_names',
            implode("\n", array_slice($merged, 0, self::MAX_UNDELETABLE_TRACKED)),
            config::COMPONENT
        );
    }

    /**
     * Narrow the recorded fault to the set just VERIFIED to be still stuck.
     *
     * Separate from record_undeletable() because the two do opposite things on
     * purpose. record_undeletable() MERGES, so that whichever reporting subsystem
     * writes second does not erase the other's evidence. This one REPLACES, and may
     * only be called straight after a reap, which is the one moment the whole
     * tracked window has just been re-checked against the disk — so anything absent
     * from `$stuck` is known to be gone rather than merely unmentioned.
     *
     * Merging here instead was wrong in a way worth naming: it left the freed names
     * in the window, so the count said 1 while the names still listed 2, and the
     * inconsistency this was meant to remove simply moved from the count to the
     * names.
     *
     * @param string[] $stuck Basenames confirmed still on disk.
     */
    private static function retain_undeletable(array $stuck): void {
        set_config('last_undeletable_time', time(), config::COMPONENT);
        set_config('last_undeletable_count', count($stuck), config::COMPONENT);
        set_config(
            'last_undeletable_names',
            implode("\n", array_slice(array_values($stuck), 0, self::MAX_UNDELETABLE_TRACKED)),
            config::COMPONENT
        );
    }

    /**
     * Re-attempt removal of the tracked files, and clear the fault when none is left.
     *
     * Public because run() cannot do this job for every site. A PULL-ONLY site has
     * no object storage configured, so run() returns at the `unconfigured` gate —
     * which sits ABOVE both the reap and the clear. A fault recorded from the pull
     * path therefore had nothing anywhere that could ever clear it: the status page
     * stayed red and get_status kept reporting stuck files for ever, long after the
     * administrator had fixed the permission. The fault was permanent by
     * construction, on exactly the sites that cannot use the shipper's own path.
     *
     * Reaps rather than merely re-checking presence, for the same reason run() does:
     * these records are already delivered, so what they need is removing, and
     * retrying the removal is the only thing that can lift the fault.
     *
     * @return string[] Basenames still on disk after the retry.
     */
    public static function reap_undeletable_fault(): array {
        $tracked = array_filter(explode("\n", (string)config::get('last_undeletable_names', '')));
        $dirs = [];
        foreach (config::buffer_dirs() as $candidate) {
            if ($candidate !== '' && is_dir($candidate)) {
                $dirs[] = $candidate;
            }
        }
        // Names on record but not one directory to look in — a moodledata mount
        // that has gone away, typically. We cannot see whether those files are
        // gone, so we must not conclude that they are. reap_undeletable() would
        // iterate zero directories, find nothing and report an empty set, which is
        // indistinguishable from a genuine recovery; clearing on that evidence
        // drops the gate while the files are still sitting there, and the next
        // successful ship re-delivers them. That is the exact loop this whole
        // change exists to stop, reintroduced by a transient unreadable mount.
        // run() cannot reach this state — it returns at `bufferdir_unreadable`
        // above its gate — so the guard belongs here, on the entry point that has
        // no such early return.
        if ($dirs === [] && $tracked !== []) {
            return $tracked;
        }
        $stuck = self::reap_undeletable($dirs);
        if ($stuck === []) {
            self::clear_undeletable();
        } else {
            // The same narrowing run() does at its own gate. The window has just
            // been re-checked against the disk, so the record must describe what is
            // actually left rather than the total the first failing run saw —
            // otherwise a pull-only site, whose ONLY entry point this is, keeps the
            // frozen count that fixing it here was meant to remove.
            self::retain_undeletable($stuck);
        }
        return $stuck;
    }

    /**
     * Forget a cleared removal fault.
     *
     * Written on change only: this runs on every healthy ship, and set_config()
     * purges this plugin's entire configuration cache.
     */
    private static function clear_undeletable(): void {
        $count = (int)config::get('last_undeletable_count', 0);
        $names = (string)config::get('last_undeletable_names', '');
        if ($count === 0 && $names === '') {
            return;
        }
        set_config('last_undeletable_count', 0, config::COMPONENT);
        set_config('last_undeletable_names', '', config::COMPONENT);
        // The timestamp is deliberately LEFT as it was: it says when the fault
        // last happened, which is worth keeping after it clears. No third
        // "cleared at" key, because nothing would read it — see
        // last_sitemismatch_*, three keys with no reader anywhere in this plugin.
        // Reachable on a web request through reap_undeletable_fault() (the pull service).
        buffer::trace('local_intellistream: the buffer files that could not be removed are gone — '
            . 'shipping has resumed.');
    }

    /**
     * Which tracked undeletable files are still on disk.
     *
     * Searched across EVERY directory this task drains, not just the current one:
     * `bufferdir` is admin-editable, so the fault can be recorded against one
     * directory and an administrator can repoint the setting before fixing it. A
     * name-only match is deliberate — it is the same basename in whichever
     * directory it is found, and resolving stored absolute paths would strand the
     * gate the moment the setting moved.
     *
     * Each one found is RE-REMOVED rather than merely counted, because it has
     * already been delivered: re-sending it is exactly the loop being fixed, and a
     * successful removal here is what lets shipping resume. That also means the
     * fault clears on the first run after the host is fixed, in one pass, with no
     * duplicate object.
     *
     * Returns [] both when there was no fault and when it has cleared, so the
     * caller cannot tell those apart — it does not need to, and collapsing them
     * means a site that has been fixed resumes on the very next run rather than
     * losing a cron minute to bookkeeping.
     *
     * @param string[] $dirs Directories to look in.
     * @param array $reaped Receives path => size of each file this call removed, so
     *        the caller can take it off its measurement.
     * @return string[] Basenames that are still there after a fresh removal attempt.
     */
    private static function reap_undeletable(array $dirs, array &$reaped = []): array {
        $tracked = array_filter(explode("\n", (string)config::get('last_undeletable_names', '')));
        if ($tracked === []) {
            return [];
        }
        $present = [];
        foreach ($tracked as $name) {
            // Re-basename the stored value. It is written from basename()
            // already, but it is stored config and a hand-edited row must not be
            // able to make this stat a path outside the buffer directories.
            $name = basename(trim($name));
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            foreach ($dirs as $target) {
                $path = $target . '/' . $name;
                clearstatcache(true, $path);
                if (@lstat($path) === false) {
                    continue;
                }
                $st = buffer::safe_stat($path);
                if (!buffer::remove_file($path)) {
                    $present[] = $name;
                } else if ($st !== null) {
                    $reaped[$path] = (int)$st['size'];
                }
                break;
            }
        }
        return $present;
    }

    /**
     * The first few names of a list for a log line, with how many more there were.
     *
     * @param string[] $names The names kept (at most ODD_NAMES_KEPT).
     * @param int|null $total How many there were in all; count($names) when null.
     * @return string
     */
    private static function name_sample(array $names, ?int $total = null): string {
        $shown = array_slice($names, 0, self::ODD_NAMES_KEPT);
        $rest = ($total ?? count($names)) - count($shown);
        return implode(', ', $shown) . ($rest > 0 ? ' (+' . $rest . ' more)' : '');
    }

    /**
     * Count one odd directory entry, keeping only the first few names: a directory
     * someone flooded must not grow the run's memory with every name in it.
     *
     * @param array $inv Run inventory, updated in place.
     * @param string $kind 'unreadable', 'unowned' or 'unsafe'.
     * @param string $name Entry name.
     * @return void
     */
    private static function note_odd(array &$inv, string $kind, string $name): void {
        $inv['oddcount'][$kind]++;
        if (count($inv[$kind]) < self::ODD_NAMES_KEPT) {
            $inv[$kind][] = $name;
        }
    }


    /**
     * Best-effort check of whether a PID is still running.
     *
     * On a host with neither `/proc` nor ext-posix this cannot tell a live
     * PID from a dead one, so it returns true (conservative: never rename a
     * possibly-live writer's file). promote_if_stale() still reclaims a truly
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
     * Say so when the buffer is over its cap. Nothing is deleted to get it back
     * under.
     *
     * This used to evict the oldest undelivered `.closed` files until the buffer
     * was back under the cap, recording them as `last_drop_*` — records captured,
     * never delivered, and deleted. It no longer deletes anything that has not been
     * delivered: the cap is held by refusing new buffer files instead
     * (buffer::have_capacity()), which stops the growth without destroying what is
     * already captured. The trade is stated plainly in the settings text: while
     * the buffer is full, new live events are refused, which is the only loss left,
     * and it is a loss either way; bulk rows the exporters are refused are read
     * again from Moodle once there is room. `last_drop_*` is therefore no longer
     * written; a value an earlier release left is shown as history on the status
     * page, and change_ledger::loss_since() still honours it.
     *
     * The legacy `.pulled` files that this used to evict are removed by the scan
     * whatever the cap (see scan_entry()): they were delivered already.
     *
     * @param int $bytes The current directory's measured size.
     * @return void
     */
    private static function note_over_cap(int $bytes): void {
        $cap = config::max_buffer_bytes();
        if ($bytes < $cap) {
            return;
        }
        // English on purpose: cron output is a log stream read by operators, not UI.
        mtrace('local_intellistream: ALERT buffer is at or over its cap (' . $bytes . ' of ' . $cap
            . ' bytes). New buffer files are refused until shipping drains it; nothing already '
            . 'captured is deleted. If this persists, check that shipping succeeds, raise maxbuffergb, '
            . 'or review dwellmaxperminute.');
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
        // Every set_config() purges this plugin's config cache, and this runs every
        // minute. So the status is written when it changes, and otherwise only as a
        // heartbeat every STATUS_HEARTBEAT_SEC: the time of the last run itself is
        // also in the published measurement (buffer::read_capacity()['start']), which
        // is what the status page and get_status show when it is newer.
        $now = time();
        $beat = $now - (int)config::get('ship_time', 0) >= self::STATUS_HEARTBEAT_SEC;
        $changed = $state !== (string)config::get('ship_state', '')
            || !self::same_detail($detail, (string)config::get('ship_detail', ''));
        if ($beat || $changed) {
            set_config('ship_state', $state, config::COMPONENT);
            set_config('ship_detail', $detail, config::COMPONENT);
            set_config('ship_time', $now, config::COMPONENT);
        }
        if ($state === 'ok' && $now - (int)config::get('last_ship_ok', 0) >= self::STATUS_HEARTBEAT_SEC) {
            set_config('last_ship_ok', $now, config::COMPONENT);
        }
    }

    /**
     * Whether two stored status details say the same thing. A translatable detail
     * (set_status_string()) is compared by its lang key alone, because its
     * parameters are this run's counts and change on every run.
     *
     * @param string $a One stored detail.
     * @param string $b The other.
     * @return bool
     */
    private static function same_detail(string $a, string $b): bool {
        if ($a === $b) {
            return true;
        }
        $da = json_decode($a, true);
        $db = json_decode($b, true);
        return is_array($da) && is_array($db) && isset($da['key'], $db['key']) && $da['key'] === $db['key'];
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
     * @param int    $dropcount  Undelivered files deleted at the cap. No current code
     *                           deletes one, so the status page passes 0 and shows a
     *                           stored `last_drop_count` as history instead; the
     *                           parameter stays for callers that still pass it.
     * @param int    $undeletablecount Stored `last_undeletable_count`.
     * @param bool   $capfailrecent Whether capture was refused for a full buffer
     *                           recently (buffer::capture_refused_recently()).
     * @param int    $parked     Parked buffer files, from the published measurement.
     * @return string 'problem' | 'norun' | 'healthy'
     */
    public static function status_class(
        string $state,
        int $dropcount,
        int $undeletablecount = 0,
        bool $capfailrecent = false,
        int $parked = 0
    ): string {
        // A drop is unshipped data deleted off the disk. It outranks everything,
        // including "never run", because the events are already gone.
        if ($dropcount > 0) {
            return 'problem';
        }
        // Capture being refused is records lost right now, whatever the last ship run
        // said: a green banner beside it is exactly the contradiction this function
        // exists to prevent. A parked file is undelivered data waiting for a person.
        if ($capfailrecent || $parked > 0) {
            return 'problem';
        }
        // Delivered-but-unremovable files outrank a later `ok` for the same reason
        // a drop does: the run that reported them has stopped shipping and the
        // buffer is now filling, so a healthy-looking `ok` from a later run — and
        // every run IS a later run once the gate lifts — must not clear the page.
        // Third parameter with a default rather than a required one, so every
        // existing caller keeps compiling.
        if ($undeletablecount > 0) {
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
