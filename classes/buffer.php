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
 * Buffer manager for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Manages append-only JSONL buffer files on local disk.
 *
 * Each PHP process writes to its own file named
 * `events-<pid>-<created_us>-<proctoken>-<hostid>.jsonl`, so there is exactly
 * one writer per file and no cross-process lock contention. The `<proctoken>`
 * is the OS process start time (hex): it is STABLE for the whole life of
 * the process — so a long-lived PHP-FPM worker re-finds and re-uses its own
 * file across every request it serves, batching many records into one file
 * — yet it still differs for a recycled PID (a new process reusing the PID
 * has a later start time), so a recycled worker never adopts a dead one's
 * still-active file. A per-REQUEST value cannot be used here: PHP-FPM tears
 * down all userland static state between requests, so a token regenerated
 * per request would make handle()'s reuse glob miss this worker's own file
 * and open a brand-new file on every request (~one record per file).
 *
 * The `<hostid>` names the WRITING HOST. The buffer dir is required to live
 * inside moodledata, which on a clustered Moodle is shared across every web
 * node, so one directory holds files from all of them. `<pid>` and
 * `<proctoken>` are both host-relative (a PID means nothing off-host, and the
 * token is ticks since THAT host's boot), so without a host component two
 * nodes can agree on the same name and adopt each other's file — likely, not
 * theoretical: an FPM pool forks its whole worker set inside one clock tick,
 * so tokens cluster hard and a homogeneous fleet allocates PIDs in step.
 * It is also what lets the shipper tell "this file's writer is on my host, so
 * /proc can answer for it" from "this file is another node's, so /proc cannot".
 *
 * Files rotate (rename to `.jsonl.closed`) on size or age; the shipper task
 * also sweeps stale active files belonging to idle/dead workers.
 */
class buffer {
    /** Hard cap on a single serialized event, in bytes. */
    const MAX_EVENT_BYTES = 1048576;

    /** @var resource|false|null Open handle, false if unusable, null if untried. */
    private static $handle = null;

    /** @var string|null Path of the active file. */
    private static $path = null;

    /** @var int Bytes in the active file (as this process sees it). */
    private static $bytes = 0;

    /** @var int Unix-seconds creation time of the active file, 0 if none. */
    private static $created = 0;

    /** @var string|null Per-process identity token (process start time, hex). */
    private static $token = null;

    /** @var string|null This host's identity, hex. */
    private static $hostid = null;

    /** @var bool Whether the process token is the stable /proc start time. */
    private static $tokenstable = false;

    /**
     * @var int Bytes this process has appended since $ownsince. See own_bytes_since().
     */
    private static $ownbytes = 0;

    /**
     * @var int Unix time from which $ownbytes counts. 0 means since the process
     *          started, which is what it is until this process measures the buffer.
     */
    private static $ownsince = 0;

    /** Width of the `<hostid>` filename segment, in hex chars. */
    const HOSTID_LEN = 8;

    /**
     * Append one already-serialized line. The line must include its own
     * trailing newline.
     *
     * @param string $line
     * @return bool True when the line was written. This was void, which made
     *              append_record() report success for a record the capacity gate
     *              had refused, or one lost to a short write.
     */
    public static function append(string $line): bool {
        // Never buffer while unpaired. An empty Site ID means
        // "not paired yet"; a record captured now is stamped with an empty
        // site_id (observer.php / dwell.php stamp it at capture time), then
        // shipped verbatim once paired — to the correct prefix but with an empty
        // payload site_id — which the middleware rejects as spoofed and drops.
        // Refusing to buffer at the one chokepoint every writer shares (events,
        // dwell, exceptions, exporter/tracking snapshots) guarantees no such
        // record is ever created. Recovery of pre-pairing history is handled
        // separately by the historical backfill, which runs once paired.
        if (config::site_id() === '') {
            return false;
        }
        $h = self::handle();
        if ($h === false || $h === null) {
            return false;
        }
        // A full disk makes fwrite() return false or a short count. Do not
        // advance the byte counter for bytes that never landed: that would
        // both lose the record silently and skew rotation. Surface it.
        $written = @fwrite($h, $line);
        if ($written === false || $written < strlen($line)) {
            self::trace('local_intellistream: ALERT buffer write failed/short ('
                . var_export($written, true) . ' of ' . strlen($line)
                . ' bytes) — record dropped (disk full?).');
            return false;
        }
        self::$bytes += $written;
        self::$ownbytes += $written;

        // Rotate on size or age. The age check lets a long-lived FPM worker
        // bound its own shipping latency, so the shipper's sweeper never has
        // to rename a file out from under a live writer.
        $agedout = self::$created > 0
            && (time() - self::$created) >= config::rotate_age_sec();
        if (self::$bytes >= config::rotate_size_bytes() || $agedout) {
            self::rotate();
        }
        return true;
    }

    /**
     * Report a message in the channel the process has: mtrace() on the CLI (cron,
     * its progress channel), debugging() on a web request.
     *
     * Anything reachable from a web request — the event-capture hot path (append(),
     * the capacity check), an observer, the pull and status web services, the
     * control webhook, a settings callback, the uninstall page — is in the middle of
     * building an HTTP response there. mtrace() is echo + flush() and would splice
     * the text into the page, JSON or file being served, so every such call site
     * goes through this instead.
     *
     * @param string $msg
     * @return void
     */
    public static function trace(string $msg): void {
        if (CLI_SCRIPT) {
            mtrace($msg);
        } else {
            debugging($msg, DEBUG_NORMAL);
        }
    }

    /**
     * Refusal reason: this record can never be written — it could not be
     * serialised, or it exceeds MAX_EVENT_BYTES. Decided before anything is
     * written, from the record alone, so retrying it can never succeed.
     *
     * @var string
     */
    const REFUSED_PERMANENT = 'permanent';

    /**
     * Refusal reason: the buffer could not take the record NOW — the site is not
     * paired, the disk cap is reached, or the write failed. The same record can
     * be accepted later.
     *
     * @var string
     */
    const REFUSED_TRANSIENT = 'transient';

    /**
     * Serialize a record payload and append it as one JSONL line.
     *
     * Shared by every writer (event observer, page-dwell endpoint, entity
     * exporter) so the on-disk envelope stays consistent. Oversized payloads
     * are reported back to the caller (which decides whether to shrink and
     * retry) rather than silently dropped.
     *
     * @param array $payload Decoded record. Must be JSON-encodable.
     * @param string|null $reason Set on refusal to REFUSED_PERMANENT or
     *        REFUSED_TRANSIENT, and to null on success. Callers that do not need
     *        it can ignore it; the return value is unchanged.
     * @return bool True if appended, false if it could not be serialized,
     *              exceeded MAX_EVENT_BYTES, or the buffer refused it.
     */
    public static function append_record(array $payload, ?string &$reason = null): bool {
        $reason = null;
        // Guard here too (see append()), so an unpaired site is rejected before
        // the payload is even serialised.
        if (config::site_id() === '') {
            $reason = self::REFUSED_TRANSIENT;
            self::$transientrefusals++;
            return false;
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($payload, $flags);
        if ($json === false) {
            $reason = self::REFUSED_PERMANENT;
            return false;
        }
        if (strlen($json) > self::MAX_EVENT_BYTES) {
            $reason = self::REFUSED_PERMANENT;
            return false;
        }
        if (!self::append($json . "\n")) {
            $reason = self::REFUSED_TRANSIENT;
            self::$transientrefusals++;
            return false;
        }
        return true;
    }

    /** @var int Records this process has had refused for a TRANSIENT reason. */
    private static $transientrefusals = 0;

    /**
     * How many records this process has had refused for a TRANSIENT reason so far.
     *
     * For the lanes that cannot carry a refusal reason back from every append they
     * make (a derived export, the census, a whole-table re-fetch): they read this
     * before and after, and a difference means a record the buffer may take later
     * was not written, so the lane must not report itself complete, advance a
     * watermark past it, or mark anything done.
     *
     * @return int
     */
    public static function transient_refusals(): int {
        return self::$transientrefusals;
    }

    /**
     * Close this process's active buffer file now, making its records
     * shippable (renamed to `.jsonl.closed`) instead of waiting for size/age
     * rotation or the shipper's idle sweep.
     *
     * Used by the historical backfill to bound its on-disk outbox: after a
     * page it flushes, then ships, so the shippable backlog stays under the
     * disk cap. A no-op when this process has no open file.
     */
    public static function flush(): void {
        if (is_resource(self::$handle) || self::$path !== null) {
            self::rotate();
        }
    }

    /**
     * Tell the shipper's sweeper that this process is still writing.
     *
     * The sweeper treats an active file untouched for SHIP_IDLE_SEC as
     * abandoned, on the reasoning that PHP closes a worker's handles at request
     * shutdown — true for a web request, FALSE for a long-lived CLI process.
     * A full export holds ONE handle across every entity in the run and appends
     * only when an entity actually yields rows; a stretch of empty entities
     * therefore leaves the file untouched while its writer is very much alive,
     * and the sweeper renames, ships and unlinks it out from under the export.
     * Everything appended afterwards goes to an unlinked inode and is lost —
     * silently, and while the export still reports the rows as exported.
     *
     * A long-running writer calls this between units of work to refresh the
     * mtime the sweeper reads. Cheap enough to call freely (one touch()), and
     * safe: age rotation is measured from the creation time encoded in the
     * FILENAME, not from mtime, so keeping mtime fresh cannot defeat rotation.
     * A no-op when this process has no file open. Prefer this over flush() in
     * a loop — flush() would emit one object per unit of work.
     */
    public static function keepalive(): void {
        if (is_resource(self::$handle) && self::$path !== null) {
            @touch(self::$path);
        }
    }

    /**
     * Seconds before a process that hit the cap re-measures the buffer.
     *
     * Bounds the directory scan to once per this interval per process while the
     * buffer is full, without making the refusal permanent for the process.
     *
     * @var int
     */
    const CAP_RECHECK_SEC = 10;

    /** @var int Unix time until which this process skips the capacity re-check. */
    private static $capretryat = 0;

    /**
     * Seconds between durable "buffer full" markers. See note_cap_refusal().
     *
     * @var int
     */
    const REFUSAL_NOTE_SEC = 300;

    /**
     * Glob patterns covering the RECORD-BEARING files of the buffer lifecycle.
     *
     * The full lifecycle is `events-<pid>-<us>-<token>-h<hostid>.jsonl` (active)
     * -> `.jsonl.closed` (shippable) -> `.jsonl.pulled` (drained by the pull web
     * service). The legacy host-less and tokenless forms are covered by the same
     * prefix. A closed file that object storage keeps rejecting on its own is
     * renamed `.jsonl.closed.parked` (see shipper::park()): it matches no pattern
     * the shipper or the pull service reads, so it no longer blocks shipping, but it
     * is still here, still counted against the cap, and still reached by the
     * privacy and uninstall paths through this list.
     *
     * These are the files a caller may READ, SHIP or REWRITE. They are not the
     * only things this plugin writes here — see {@see residue_patterns} for the
     * rewrite temporaries, and the small bookkeeping dot-files (the published
     * capacity measurement, its lock and measurement claim, the lane marks and the
     * per-worker pointers, see CAPACITY_FILE and MARKS_FILE) — so do not use this list to answer "is anything of
     * ours left in this directory". That distinction is load-bearing; the
     * docblock used to claim nothing else was ever written here, and that claim
     * is what let a leftover temporary sit outside the purge and the erasure
     * paths entirely.
     *
     * @return string[]
     */
    private static function own_file_patterns(): array {
        return ['events-*.jsonl', 'events-*.jsonl.closed', 'events-*.jsonl.pulled', 'events-*.jsonl.closed' . self::PARKED_SUFFIX];
    }

    /** Suffix a closed file gets when object storage rejects it on its own. */
    const PARKED_SUFFIX = '.parked';

    /**
     * Glob patterns covering the rewrite temporaries this plugin writes.
     *
     * Two call sites write a sibling temp and rename() it over the original:
     * the privacy rewrite in {@see delete_user_records} (`.privacy-<pid>-<rand>.tmp`)
     * and the pull-export rewrite in `pull_export::drain_file()`
     * (`.rewrite-<pid>-<rand>`). Both are created with fopen('xb') and unlinked on
     * every failure branch, but a process KILLED between the create and the
     * rename — OOM, max_execution_time, a deploy restart — leaves one behind, and
     * both call sites handle whole buffer files, so they are exactly where a kill
     * is plausible.
     *
     * Such a file matches none of {@see own_file_patterns} (it ends in `.tmp` or
     * a random suffix, not `.jsonl`/`.closed`/`.pulled`), and it IS a plain file
     * with one link, so {@see unsafe_files} does not report it either. It was
     * therefore invisible to entry_count(), to purge_all() and to the privacy
     * paths, while holding real records.
     *
     * A temporary is never authoritative: the rename did not happen, so the
     * original file is still intact and still holds the records. The only correct
     * treatment for a stray one is to DELETE it — never to read, ship or rewrite
     * it.
     *
     * The patterns after the first two are the temporaries the small bookkeeping
     * files (the capacity measurement, the lane marks, the lock and the pointers) are
     * written through (replace_small_file()), which a killed process can leave
     * behind the same way. They hold a byte count, a mark or a buffer file name, never
     * a record, but they would hold a directory non-empty just as well. Their names
     * have the same shape whichever release wrote them, so this also reclaims the
     * ones an earlier build of the capacity measurement left behind.
     *
     * @return string[]
     */
    private static function residue_patterns(): array {
        return [
            'events-*.jsonl*.privacy-*.tmp',
            'events-*.jsonl*.rewrite-*',
            self::CAPACITY_FILE . '.tmp-*',
            self::MARKS_FILE . '.tmp-*',
            self::CAPACITY_LOCK . '.tmp-*',
            self::POINTER_PREFIX . '*.tmp-*',
        ];
    }

    /**
     * Whether a path is the lock's creation temporary still sharing the lock's inode
     * (a plain file with up to LOCK_MAX_LINKS links under a `.capacity.lock.tmp-*`
     * name). Removing that name never affects the lock, and leaving it would keep
     * the directory non-empty for ever.
     *
     * @param string $path
     * @return bool
     */
    public static function is_lock_temp(string $path): bool {
        return fnmatch(self::CAPACITY_LOCK . '.tmp-*', basename($path)) && self::open_ok_lock($path);
    }

    /**
     * Seconds a rewrite temporary must be untouched before it counts as stray.
     *
     * A live rewrite holds its temp for the time it takes to stream one buffer
     * file, so anything older than this window belongs to a process that is gone.
     * Deleting a temp another process is still writing would make its rename()
     * fail, so the window is deliberately far longer than any single rewrite.
     */
    const RESIDUE_STRAY_SEC = 900;

    /**
     * Rewrite temporaries present in a directory.
     *
     * @param string $dir Directory to inspect.
     * @param int $minage Only return files untouched for at least this many
     *        seconds. 0 returns every temporary, which is what the uninstall
     *        purge wants — nothing is in flight on a site being uninstalled.
     * @return string[] Full paths.
     */
    public static function residue_files(string $dir, int $minage = 0): array {
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $now = time();
        $out = [];
        foreach (self::residue_patterns() as $pattern) {
            foreach (glob($dir . '/' . $pattern) ?: [] as $path) {
                // Mirrors purge_all()'s rule: a plain file, or a symlink, which
                // unlink() removes by the link and never by its target.
                $islink = is_link($path);
                if (!self::is_safe_file($path) && !$islink && !self::is_lock_temp($path)) {
                    continue;
                }
                // The age guard exists for ONE case: a rewrite still in flight in
                // another process, whose temp must survive so its rename() lands.
                // Such a temp is always a plain file — fopen('xb') fails outright
                // if the path already exists, so it can neither open a planted
                // symlink nor create one. A symlink here is therefore never an
                // in-flight temp and has nothing to protect.
                //
                // Applying the guard to one anyway was worse than pointless:
                // filemtime() stat()s through the link and reports the TARGET's
                // mtime, so a link pointing at anything recently touched read as
                // brand new and was spared for ever. Measured: a link aged 7200s
                // pointing at a file touched now reported an age of 0s, and every
                // sweep skipped it. Because entry_count() still counts it, the
                // previous buffer directory holding it could then never be
                // forgotten — it would hold one of the MAX_BUFFER_DIRS slots and
                // repeat its "files still in a previous buffer directory" warning
                // on every ship run, with nothing an admin could do to clear it.
                if ($minage > 0 && !$islink) {
                    $mtime = (int)@filemtime($path);
                    if ($mtime === 0 || ($now - $mtime) < $minage) {
                        continue;
                    }
                }
                $out[] = $path;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Delete stray rewrite temporaries from a directory.
     *
     * Safe because a temporary is never authoritative (see
     * {@see residue_patterns}): its records are still in the original file, which
     * this never touches.
     *
     * @param string $dir Directory to clean.
     * @param int $minage Age guard, as for {@see residue_files}.
     * @return int Files removed.
     */
    public static function purge_residue(string $dir, int $minage = 0): int {
        $removed = 0;
        foreach (self::residue_files($dir, $minage) as $path) {
            if (@unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Bit mask isolating the file-type bits of a stat mode. */
    const STAT_TYPE_MASK = 0170000;

    /** Stat mode file-type value for a plain (regular) file. */
    const STAT_TYPE_REGULAR = 0100000;

    /**
     * Whether a path is a buffer file this plugin may safely touch.
     *
     * The buffer directory lives inside moodledata, so anything able to write
     * there as the web user can leave an entry with a buffer-shaped name that is
     * not a plain file. Every consumer here either reads a file's CONTENTS (and
     * ships them to object storage, or returns them from a web service), opens it
     * for append, or trusts its size — so "is it really a plain file" has to be
     * answered before any of that, not assumed from the name.
     *
     * The test is deliberately POSITIVE — "this IS one plain file" — rather than a
     * list of things to exclude, because the obvious exclusion is incomplete:
     *
     *  - `lstat()` describes the LINK, never what it points at, so every symlink
     *    shape fails here without the target ever being resolved or opened:
     *    absolute, relative, dangling, to a file, to a directory. That is why
     *    there is no realpath()/containment check anywhere below — resolving the
     *    target is exactly what must not happen.
     *  - The type check also rejects a directory, socket, device and FIFO. The
     *    last one matters more than it looks: file_get_contents() on a FIFO blocks
     *    for ever, so a single well-named entry would wedge the shipper task, and
     *    a link to /dev/zero would read until the process is killed.
     *  - `nlink === 1` is the part `is_link()` cannot do. A hardlink is
     *    indistinguishable from the original by every is_*() function, and the
     *    buffer dir is required to live inside moodledata, so anything else in
     *    there owned by the web user is on the same filesystem and can be linked
     *    in under a shippable name.
     *
     * `clearstatcache()` first because PHP memoises stat results per path for the
     * life of the request: a long cron run that stat'ed this path minutes ago
     * would otherwise answer from a memo taken before the entry was replaced, and
     * a check that can be served from a cache is not a check.
     *
     * @param string $path
     * @return bool
     */
    public static function is_safe_file(string $path): bool {
        return self::safe_stat($path) !== null;
    }

    /**
     * The lstat() of a path that passes is_safe_file(), or null.
     *
     * The same test, returning the stat it was decided on, so a caller that also
     * needs the size or the modification time (the directory scans below) takes
     * them from the one lstat() that approved the entry instead of stat-ing it
     * again.
     *
     * @param string $path
     * @return array|null
     */
    public static function safe_stat(string $path): ?array {
        // The stat cache only (see is_safe_file()). PHP's realpath cache is kept,
        // because dropping it made the next fopen() of the path lstat() it again.
        // An out-of-date realpath entry CAN make fopen() open a different file (the
        // old target of a link that has since been replaced): for a read, the device
        // and inode check against this stat then refuses the handle before anything
        // is read; a WRITING open cannot rely on that, because O_CREAT may already
        // have created the file, so safe_open() drops the entry before one.
        clearstatcache();
        $st = @lstat($path);
        if ($st === false) {
            return null;
        }
        if (($st['mode'] & self::STAT_TYPE_MASK) !== self::STAT_TYPE_REGULAR) {
            return null;
        }
        return (int)$st['nlink'] === 1 ? $st : null;
    }

    /**
     * Glob a buffer directory and keep only entries safe to touch.
     *
     * The one place the glob-then-trust pattern is allowed to live. Callers pass
     * the patterns they care about and get back paths that have already been
     * judged by {@see is_safe_file()}.
     *
     * The empty-$dir guard is load-bearing rather than ceremony: `glob('' . '/*')`
     * globs the filesystem ROOT, and config::buffer_dir() returns '' when
     * $CFG->dataroot is unset.
     *
     * @param string   $dir
     * @param string[] $patterns Glob patterns, relative to $dir.
     * @return string[] Absolute paths, de-duplicated.
     */
    public static function safe_files(string $dir, array $patterns): array {
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $paths = [];
        foreach ($patterns as $pattern) {
            foreach (glob($dir . '/' . $pattern) ?: [] as $path) {
                if (self::is_safe_file($path)) {
                    $paths[$path] = true;
                }
            }
        }
        return array_keys($paths);
    }

    /**
     * Remove a buffer file, and report truthfully whether it is gone.
     *
     * The single place a buffer file is removed. Every caller used to write
     * `@unlink($path)` and discard the result, so a removal that FAILED was
     * indistinguishable from one that succeeded — and the shipper then credited
     * itself the file, reported `ok`, and re-shipped the identical batch under the
     * identical object key on the next run, for ever, without ever reaching a
     * newer file. The `@` is kept (a failure here is expected and handled, not a
     * PHP warning worth printing) but the verdict is no longer thrown away.
     *
     * The plausible causes are all host-side and all leave the directory itself
     * writable, which is why capture keeps working while shipping cannot drain:
     * a sticky bit on the buffer directory with the files owned by another uid
     * (a cron uid that is not the web uid), an immutable flag on the files, or a
     * remount read-only. Under every one of them `rename()` fails too — both
     * operations need write+execute on the DIRECTORY, not on the file — so there
     * is no "move it aside instead" escape, and the caller has to be told.
     *
     * A vanished file is a SUCCESS, not a failure. Four things race us for the
     * same closed files: pull_export commits by removing them, the shipper
     * removes what it delivered, delete_user_records() rewrites, and on
     * shared moodledata another node's shipper does all three. Treating the
     * resulting ENOENT as a fault would make a perfectly healthy pull-enabled
     * site report a permanent removal failure — a worse defect than the one this
     * function exists to expose.
     *
     * @param string $path
     * @return bool True when the file is no longer on disk, whether this call
     *              removed it or something else already had.
     */
    public static function remove_file(string $path): bool {
        clearstatcache(true, $path);
        // No directory entry at all: nothing to remove, so the caller's goal is
        // already met. Checked FIRST, and with lstat() rather than file_exists():
        // is_safe_file() also fails on an absent path, so testing safety first
        // reported a vanished file as a REMOVAL FAILURE — which on a pull-enabled
        // site (pull_export commits by renaming, and races this) would raise the
        // undeletable alarm on an entirely healthy host. file_exists() would be
        // wrong here too: it follows symlinks, so a dangling one would be reported
        // as gone while its entry stayed on disk.
        if (@lstat($path) === false) {
            return true;
        }
        if (!self::is_safe_file($path)) {
            // Present, but not a plain single-linked file: refuse to unlink through
            // it at all, and do NOT claim it is gone. Something replaced the entry.
            return false;
        }
        if (@unlink($path)) {
            return true;
        }
        clearstatcache(true, $path);
        return @lstat($path) === false;
    }

    /**
     * Open a buffer file, verifying the handle is the file that was inspected.
     *
     * A check-then-open pair resolves the same name TWICE, and the two results
     * need not agree: an entry swapped in between gets the attacker's target
     * opened on the strength of a check that passed against the real file. Since
     * the whole point of the check is to refuse a file the plugin must not read
     * or append to, that race is worth closing rather than documenting.
     *
     * fstat() describes the OPEN DESCRIPTOR, which no later rename() or
     * symlink() can change. Same device and inode as the lstat() that just
     * approved the path therefore means "this handle IS that file". PHP exposes
     * no O_NOFOLLOW, so this is the portable equivalent of one.
     *
     * The inode comparison is skipped when lstat() reports 0, which is what
     * platforms without inode numbers do; there the verdict rests on the type
     * and link-count checks alone.
     *
     * @param string $path
     * @param string $mode fopen() mode. Callers open for read or append only —
     *                     a CREATE must use 'xb' instead, see handle().
     * @param array|null $before A safe_stat() of $path the caller has just taken,
     *                     so it is not taken twice; the checks are the same.
     * @return resource|false
     */
    public static function safe_open(string $path, string $mode = 'rb', ?array $before = null) {
        $writing = strpbrk($mode, 'waxc+') !== false;
        if ($writing) {
            // Before a writing open, drop the path's realpath entry as well: a stale
            // one would send fopen() to a link's old target, and 'a' creates what is
            // not there before the identity check below could refuse the handle.
            clearstatcache(true, $path);
        }
        if ($before === null) {
            if (!$writing) {
                clearstatcache();   // Stat cache only; see safe_stat().
            }
            $before = @lstat($path);
        }
        if (
            $before === false
            || ($before['mode'] & self::STAT_TYPE_MASK) !== self::STAT_TYPE_REGULAR
            || (int)$before['nlink'] !== 1
        ) {
            return false;
        }
        $fh = @fopen($path, $mode);
        if ($fh === false) {
            return false;
        }
        $after = @fstat($fh);
        if (
            $after === false
            || ($after['mode'] & self::STAT_TYPE_MASK) !== self::STAT_TYPE_REGULAR
            || (int)$after['nlink'] !== 1
            || (int)$after['dev'] !== (int)$before['dev']
            || ((int)$before['ino'] !== 0 && (int)$after['ino'] !== (int)$before['ino'])
        ) {
            fclose($fh);
            return false;
        }
        return $fh;
    }

    /**
     * Read a buffer file's contents, or false if it is not safe to read.
     *
     * Exists so the paths that ship bytes off the host are a one-line swap for
     * file_get_contents() and cannot drift back to the unguarded form.
     *
     * @param string $path
     * @return string|false
     */
    public static function safe_contents(string $path) {
        $fh = self::safe_open($path, 'rb');
        if ($fh === false) {
            return false;
        }
        try {
            return stream_get_contents($fh);
        } finally {
            fclose($fh);
        }
    }

    /**
     * Buffer-shaped entries that are NOT safe to touch, for reporting.
     *
     * Detection only: reads nothing, renames nothing, removes nothing. Its caller
     * is entry_count(), for a previous directory the shipper's scan does not read;
     * the scan reports unsafe entries itself (shipper::scan_entry()) to the status
     * page and the cron trace, because an entry of this kind is either operator
     * error or someone probing, and silently skipping it forever is how it stays
     * invisible.
     *
     * Globs one wide `*.jsonl*` pattern rather than own_file_patterns(), because
     * this is the REPORT: it has to see everything any other call site could pick
     * up, including the `.rewrite-` and `.privacy-` temp siblings whose names are
     * predictable enough to be pre-planted.
     *
     * @param string $dir
     * @return string[] Basenames.
     */
    public static function unsafe_files(string $dir): array {
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.jsonl*') ?: [] as $path) {
            if (!self::is_safe_file($path)) {
                $out[] = basename($path);
            }
        }
        return $out;
    }

    /**
     * Unpredictable suffix for a temporary file name.
     *
     * A temp path built only from the pid is guessable, and a guessable name can
     * be pre-created as a symlink so the write lands somewhere else. Randomness
     * removes the guess; O_EXCL at the call site removes the race.
     *
     * It also removes two failure modes O_EXCL alone would introduce: a temp left
     * behind by a crashed run would otherwise wedge that path for every later run
     * that computes the same name, and two cluster nodes sharing moodledata can
     * reach the same pid.
     *
     * Falls back to a hash when no CSPRNG is available, on the same reasoning as
     * {@see process_token()} — uniqueness here is a robustness property, not a
     * secret.
     *
     * Public because pull_export builds a sibling temp beside a `.closed` file for
     * the same reason and must not re-derive this.
     *
     * @return string
     */
    public static function temp_suffix(): string {
        try {
            return bin2hex(random_bytes(6));
        } catch (\Exception $e) {
            return substr(hash('sha256', getmypid() . '|' . microtime(true)), 0, 12);
        }
    }

    /**
     * Every buffer file currently on disk, oldest name first.
     *
     * Spans every directory config::tracked_buffer_dirs() names, not just the one
     * in use. The callers are the privacy provider's export, erasure and
     * user-listing paths, and a record does not stop being personal data
     * because the buffer has since been pointed somewhere else. Reading only
     * the current directory meant a relocated site answered a subject access
     * request from part of its buffer and erased part of it.
     *
     * tracked_buffer_dirs() rather than buffer_dirs() on purpose: a directory that
     * no longer passes buffer_dir_problem() (moodledata moved, or a rule tightened)
     * still holds our records, and erasure must reach them. safe_files() keeps this
     * to our own file names, so reading a now-reserved directory is still safe.
     *
     * @return string[] Absolute paths.
     */
    private static function own_files(): array {
        $paths = [];
        foreach (config::tracked_buffer_dirs() as $dir) {
            foreach (self::safe_files($dir, self::own_file_patterns()) as $path) {
                $paths[] = $path;
            }
        }
        sort($paths);
        return $paths;
    }

    /**
     * Entities whose data subject is named by a column other than `userid`
     * (record_user_ids()). Entity name => column(s) holding a Moodle user id.
     */
    public const ENTITY_SUBJECT_FIELDS = [
        'user' => ['id'],
        'userlogins' => ['id'],
        'message' => ['useridfrom', 'useridto'],
        'messages' => ['useridfrom'],
        'attendance_log' => ['studentid'],
        'tag_instance' => ['tiuserid'],
        'local_intellistream_colpart' => ['external_user_id'],
    ];

    /**
     * The Moodle user ids a buffered record is ABOUT.
     *
     * Used by the privacy provider to decide whether a line belongs to a data
     * subject. Each record type carries the subject in a different place:
     *
     *   - `event`: the acting user and, when the event names one, the user acted
     *     upon (`userid` / `relateduserid` inside the core event payload).
     *   - `page_dwell` / `media_segment`: `dwell.userid`.
     *   - `entity_snapshot`: a snapshot of one Moodle table row, so there is no
     *     single guaranteed subject column. A `userid` key is matched when the row
     *     has one, which covers the user-scoped entities (enrolments, grades,
     *     completions, dwell-adjacent tables), and ENTITY_SUBJECT_FIELDS names the
     *     entities whose subject is in another column (the user row itself, both
     *     parties of a message, and so on). Only the data subject counts: a column
     *     that names who acted on the row (`usermodified`, `grader`, `modifierid`)
     *     does not, because matching it would put other people's records in that
     *     person's export and delete them on that person's erasure. This is
     *     deliberately a heuristic: the authoritative erasure for the underlying
     *     Moodle tables is core's own, and these rows are a transient copy en route
     *     to the warehouse. A discovered or custom whole-row table is matched on
     *     `userid` only, since its columns are not known.
     *   - `exception`: a TOP-LEVEL `userid`, unlike every type above — the
     *     exceptions observer writes it directly on the record rather than nesting
     *     it. That difference is why this branch was missing, and the omission was
     *     not cosmetic: all three privacy paths funnel through this one method, so
     *     an exception record carrying a subject's userid, request URL and stack
     *     trace was invisible to the subject-access export, survived an erasure
     *     request, and — worse — could not even put the user in the system context,
     *     so core dropped this component from their privacy request entirely.
     *
     * Anything added to the record-type set must be added here too. This method is
     * the single definition of "belongs to a data subject" for the buffer.
     *
     * @param array $rec Decoded buffer record.
     * @return int[] Distinct positive user ids.
     */
    private static function record_user_ids(array $rec): array {
        $ids = [];
        $take = static function ($value) use (&$ids) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = true;
            }
        };

        $type = (string) ($rec['record_type'] ?? '');
        if ($type === 'event') {
            $data = $rec['event_data'] ?? null;
            if (is_array($data)) {
                $take($data['userid'] ?? 0);
                $take($data['relateduserid'] ?? 0);
            }
        } else if ($type === 'page_dwell' || $type === 'media_segment') {
            $dwell = $rec['dwell'] ?? null;
            if (is_array($dwell)) {
                $take($dwell['userid'] ?? 0);
            }
        } else if ($type === 'entity_snapshot') {
            $data = $rec['entity_data'] ?? null;
            if (is_array($data)) {
                $fields = self::ENTITY_SUBJECT_FIELDS[(string)($rec['entity'] ?? '')] ?? [];
                foreach (array_merge(['userid'], $fields) as $field) {
                    if (isset($data[$field])) {
                        $take($data[$field]);
                    }
                }
                // A tag on a user profile is about that user as well as its tagger
                // (core_tag's provider treats tiuserid as the tagger's data).
                if (($rec['entity'] ?? '') === 'tag_instance' && ($data['itemtype'] ?? '') === 'user') {
                    $take($data['itemid'] ?? 0);
                }
            }
        } else if ($type === datatypes\exceptions_datatype::RECORD_TYPE) {
            $take($rec['userid'] ?? 0);
        }

        return array_keys($ids);
    }

    /**
     * Mode for a buffer file this plugin creates: the site's `$CFG->filepermissions`,
     * the same policy make_writable_directory() applies to the directory.
     *
     * @return int
     */
    public static function file_permissions(): int {
        global $CFG;
        return isset($CFG->filepermissions) ? (int)$CFG->filepermissions : 0666;
    }

    /**
     * Count this plugin's record files that may hold records written between $from and
     * $until and are still on the host.
     *
     * Active (`.jsonl`) and shippable (`.jsonl.closed`) files, in the current buffer
     * directory and every previous one that still validates (the ones the shipper
     * drains). A file qualifies when its name-encoded creation time is at or before
     * $until — a process may append to a file it opened before $from — and it was
     * modified at or after $from. `.jsonl.pulled` is not counted: it has already been
     * handed to the pull reader, which is its own delivery path. Nor are the files the
     * shipper delivered but could not delete (its tracked undeletable set): their
     * records reached object storage.
     *
     * A parked file (see own_file_patterns()) is counted too: its records never
     * reached object storage, so a pass that wrote into it must not be confirmed.
     *
     * Answered from one read of the buffer directories per process rather than one
     * per call. The verification sweep asks this once for every entity whose pass it
     * begins, and each call used to read every directory and stat every file again,
     * so a run over a large registry with a large backlog cost entities x files
     * stats. The answer is exact all the same: the read is used only for a pass that
     * finished writing before the read began ($until below the read's start), and
     * every file that pass wrote into was already on disk, carrying at least that
     * modification time, when the read saw it. A file that has shipped since only
     * makes the answer err towards "not shipped yet": the caller waits, or, once
     * the pass is SHIP_WAIT_SEC (hours) old, clears that entity's fingerprints so
     * its next pass sends everything — a cost bounded by one sweep run's budget
     * (minutes) of staleness, never a lost record. A later pass takes a fresh read. forget_written_inventory() drops it at the
     * start of each sweep run, so a long cron process never answers from an old one.
     *
     * @param int $from Unix seconds.
     * @param int $until Unix seconds.
     * @return int
     */
    public static function files_written_between(int $from, int $until): int {
        if (self::$written === null || $until >= self::$written['at']) {
            self::$written = self::written_inventory();
        }
        $n = 0;
        foreach (self::$written['created'] as $i => $created) {
            if ($created <= $until && self::$written['mtime'][$i] >= $from) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @var array|null The read files_written_between() answers from: 'at' (when it
     *                 began), and the creation and modification times of each file.
     */
    private static $written = null;

    /**
     * Drop the read files_written_between() answers from.
     *
     * @return void
     */
    public static function forget_written_inventory(): void {
        self::$written = null;
    }

    /**
     * Read, once, what files_written_between() needs: for each record file still
     * waiting to leave the host, its name-encoded creation time and its modification
     * time. Two integers per file, not its name.
     *
     * @return array{at:int, created:int[], mtime:int[]}
     */
    private static function written_inventory(): array {
        $delivered = array_flip(array_filter(array_map(
            'trim',
            explode("\n", (string)config::get('last_undeletable_names', ''))
        )));
        $out = ['at' => time(), 'created' => [], 'mtime' => []];
        foreach (config::buffer_dirs() as $dir) {
            self::each_entry($dir, function (string $name, string $path) use ($delivered, &$out) {
                $kind = self::entry_kind($name);
                if (strpos($name, 'events-') !== 0 || !in_array($kind, ['active', 'closed', 'parked'], true)) {
                    return;
                }
                if (isset($delivered[$name])) {
                    return;
                }
                $st = self::safe_stat($path);
                if ($st === null) {
                    return;
                }
                $parsed = self::parse_name($name);
                $out['created'][] = $parsed !== null ? intdiv($parsed['created_us'], 1000000) : 0;
                $out['mtime'][] = (int)$st['mtime'];
            });
        }
        return $out;
    }

    /**
     * Call $fn for every entry of a directory, reading it one entry at a time.
     *
     * The scans in this class need one number, or a bounded selection, from each
     * entry, so there is no reason to hold every name in the directory in memory the
     * way glob() does. The empty-$dir guard is the same as safe_files()'s: '' . '/'
     * is the filesystem root.
     *
     * @param string $dir
     * @param callable $fn Receives the entry's name and its full path.
     * @return bool False when the directory could not be read.
     */
    public static function each_entry(string $dir, callable $fn): bool {
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        $dh = @opendir($dir);
        if ($dh === false) {
            return false;
        }
        try {
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $fn($name, $dir . '/' . $name);
            }
        } finally {
            closedir($dh);
        }
        return true;
    }

    /**
     * What an entry of a buffer directory is, from its name alone.
     *
     * One classifier for every scan here, so each of them answers from the same
     * rules the globs elsewhere in this class use:
     *
     *  - 'active', 'closed', 'pulled', 'parked': the record files' lifecycle names
     *    (own_file_patterns() when the name also starts `events-`; the shipper also
     *    ships a `.closed` file that does not);
     *  - 'residue': a rewrite or bookkeeping temporary (residue_patterns());
     *  - 'state', 'lock', 'pointer': the bookkeeping dot-files;
     *  - 'other': any other name containing `.jsonl`, which unsafe_files()'s wide
     *    `*.jsonl*` pattern reports when it is not a plain file;
     *  - null: nothing this plugin reads, writes or reports. That includes every
     *    other dot-file: glob()'s `*` never matches one either.
     *
     * @param string $name Entry name, no directory.
     * @return string|null
     */
    public static function entry_kind(string $name): ?string {
        if ($name === '' || $name[0] === '.') {
            if ($name === self::CAPACITY_FILE || $name === self::MARKS_FILE || $name === self::MEASURE_CLAIM) {
                return 'state';
            }
            if ($name === self::CAPACITY_LOCK) {
                return 'lock';
            }
            foreach (array_slice(self::residue_patterns(), 2) as $pattern) {
                if (fnmatch($pattern, $name)) {
                    return 'residue';
                }
            }
            return preg_match(self::POINTER_RE, $name) ? 'pointer' : null;
        }
        if (strpos($name, '.jsonl') === false) {
            return null;
        }
        foreach (array_slice(self::residue_patterns(), 0, 2) as $pattern) {
            if (fnmatch($pattern, $name)) {
                return 'residue';
            }
        }
        $suffixes = [
            '.jsonl' => 'active',
            '.jsonl.closed' => 'closed',
            '.jsonl.pulled' => 'pulled',
            '.jsonl.closed' . self::PARKED_SUFFIX => 'parked',
        ];
        foreach ($suffixes as $suffix => $kind) {
            if (substr($name, -strlen($suffix)) === $suffix) {
                return $kind;
            }
        }
        return 'other';
    }

    /**
     * Decompose a buffer filename into the identity it encodes.
     *
     * The single place that knows this grammar. Four near-identical patterns
     * used to be spread across this class and the shipper, each re-deriving one
     * field; a name change then had to be made in four places and any one of
     * them missed would silently stop matching (and a file that matches nothing
     * is a file nobody reclaims).
     *
     * Three forms, all of which must keep parsing — a file already on disk at
     * upgrade time is mid-flight data, and stranding it means losing it:
     *
     *   events-<pid>-<us>-<token>-h<hostid>.jsonl   current
     *   events-<pid>-<us>-<token>.jsonl             legacy: no host component
     *   events-<pid>-<us>.jsonl                     legacy: tokenless
     *
     * The host segment carries a literal `h` prefix rather than relying on its
     * width to set it apart. `<token>` is `dechex()` of the process start time,
     * which for current uptimes is ITSELF eight hex characters (`6ba907de`,
     * `303402d3`), so a width-only rule would read a legacy three-segment name
     * as a host-stamped one. `h` cannot occur in a hex token, so the forms stay
     * distinguishable whatever the token's length.
     *
     * Accepts the `.closed`, `.closed.parked` and `.pulled` suffixes so callers
     * can parse a file at any point in its lifecycle.
     *
     * @param string $path Full path or bare basename.
     * @return array|null null when the name is not one of ours. Keys:
     *                    pid (int), created_us (int), token (?string),
     *                    hostid (?string — null on a legacy name, meaning
     *                    "written by an unknown host", NOT "written here").
     */
    public static function parse_name(string $path): ?array {
        $re = '/^events-(\d+)-(\d+)(?:-([0-9a-z]+))?(?:-h([0-9a-f]+))?'
            . '\.jsonl(?:\.closed(?:\.parked)?|\.pulled)?$/';
        if (!preg_match($re, basename($path), $m)) {
            return null;
        }
        return [
            'pid'        => (int) $m[1],
            'created_us' => (int) $m[2],
            'token'      => ($m[3] ?? '') !== '' ? $m[3] : null,
            'hostid'     => ($m[4] ?? '') !== '' ? $m[4] : null,
        ];
    }

    /**
     * This host's identity: a short hash of its hostname.
     *
     * Hashed rather than used raw because a hostname may contain characters
     * that have no business in a filename, and its length is unbounded.
     *
     * Derived fresh at runtime on purpose, and deliberately NOT persisted in
     * plugin config: config lives in the site database, which every node in a
     * cluster shares, so a stored value would read back identical on all of
     * them and defeat the entire point.
     *
     * @return string HOSTID_LEN hex chars.
     */
    public static function host_id(): string {
        if (self::$hostid !== null) {
            return self::$hostid;
        }
        $name = gethostname();
        if ($name === false || $name === '') {
            $name = php_uname('n');
        }
        if ($name === '') {
            // Nothing identifies this host. Anything invented here would differ
            // per process and make every file look foreign to its own writer,
            // so use a fixed value: the host-scoped fast path is then no better
            // than the old behaviour on this host, but nothing regresses.
            $name = 'unknown-host';
        }
        self::$hostid = substr(hash('sha256', $name), 0, self::HOSTID_LEN);
        return self::$hostid;
    }

    /**
     * Whether a buffer file was written by THIS host.
     *
     * A legacy name (no host component) answers false. That is the safe
     * direction: such a file may have come from another node that had not yet
     * been upgraded, and the only cost of being wrong is that it waits for the
     * idle sweep instead of being reclaimed the moment its PID disappears.
     *
     * @param string $path
     * @return bool
     */
    public static function is_own_host(string $path): bool {
        $parsed = self::parse_name($path);
        return $parsed !== null
            && $parsed['hostid'] !== null
            && $parsed['hostid'] === self::host_id();
    }

    /**
     * Buffered records belonging to one user, for a subject-access request.
     *
     * Read-only. The window is normally short — files ship and are deleted within
     * about a minute — but a shipping backlog can leave personal data here, which
     * the privacy provider previously ignored entirely.
     *
     * @param int $userid
     * @return array[] Decoded records, in file order.
     */
    public static function user_records(int $userid): array {
        if ($userid <= 0) {
            return [];
        }
        $out = [];
        foreach (self::own_files() as $path) {
            $fh = self::safe_open($path);
            if (!$fh) {
                continue;
            }
            try {
                while (($line = fgets($fh)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $rec = json_decode($line, true);
                    if (!is_array($rec)) {
                        continue;
                    }
                    if (in_array($userid, self::record_user_ids($rec), true)) {
                        $out[] = $rec;
                    }
                }
            } finally {
                fclose($fh);
            }
        }
        return $out;
    }

    /**
     * Whether the buffer holds ANY record for one user — an existence test.
     *
     * Separate from user_records() and short-circuiting on the first match,
     * because the privacy provider only needs a yes/no to decide whether to add
     * the system context. It used to call user_records() and keep nothing but the
     * truthiness of the result, so answering that question decoded every line of
     * every buffer file and accumulated all of the subject's records into an
     * array first. With a shipping backlog the buffer can legitimately hold up to
     * config::max_buffer_bytes() (5 GB by default), so a single subject-access
     * request could materialise a very large array to produce one boolean. Same
     * class of mistake as get_records() where get_recordset() belongs, applied to
     * file reads.
     *
     * @param int $userid
     * @return bool
     */
    public static function has_user_records(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        foreach (self::own_files() as $path) {
            $fh = self::safe_open($path);
            if (!$fh) {
                continue;
            }
            try {
                while (($line = fgets($fh)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $rec = json_decode($line, true);
                    if (!is_array($rec)) {
                        continue;
                    }
                    if (in_array($userid, self::record_user_ids($rec), true)) {
                        return true;
                    }
                }
            } finally {
                fclose($fh);
            }
        }
        return false;
    }

    /**
     * Every distinct user id with a record currently staged in the buffer.
     *
     * Backs the privacy userlist provider, so an admin erasing "all data for users
     * in this context" also reaches a user whose ONLY personal data is a staged
     * record not yet shipped.
     *
     * @return int[]
     */
    public static function user_ids(): array {
        $ids = [];
        foreach (self::own_files() as $path) {
            $fh = self::safe_open($path);
            if (!$fh) {
                continue;
            }
            try {
                while (($line = fgets($fh)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $rec = json_decode($line, true);
                    if (!is_array($rec)) {
                        continue;
                    }
                    foreach (self::record_user_ids($rec) as $uid) {
                        $ids[$uid] = true;
                    }
                }
            } finally {
                fclose($fh);
            }
        }
        return array_keys($ids);
    }

    /**
     * Remove buffered records belonging to the given users, for an erasure request.
     *
     * Each eligible file is rewritten without the matching lines, via a temp file
     * and an atomic rename, so a reader never sees a half-written buffer.
     *
     * WHICH FILES ARE ELIGIBLE is the safety-critical part. `.closed` and `.pulled`
     * files have no writer by definition and are always safe. An active `.jsonl`
     * file is only rewritten when the PID in its name is no longer running — an
     * orphan from a recycled worker. A live writer holds an append handle at a byte
     * offset; rewriting underneath it would corrupt the file and lose the records
     * still in flight, so those are skipped and counted. They ship within about a
     * minute and their content leaves with them.
     *
     * That PID test can only be asked of the local process table, so an active
     * file written by ANOTHER node (moodledata is shared on a cluster) is skipped
     * outright rather than judged: off-host, a live PID is indistinguishable from
     * a dead one, and guessing wrong here means rewriting a file underneath a
     * live writer — exactly what the paragraph above forbids. Such a file is
     * reclaimed and shipped by its own node shortly, taking its content with it.
     *
     * @param int[] $userids
     * @return array{files:int,removed:int,kept:int,skipped:int}
     */
    public static function delete_user_records(array $userids): array {
        $targets = [];
        foreach ($userids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $targets[$id] = true;
            }
        }
        $result = ['files' => 0, 'removed' => 0, 'kept' => 0, 'skipped' => 0];
        if (!$targets) {
            return $result;
        }
        // Records the verification sweep counted as delivered may be about to vanish
        // from files not yet shipped, so no fingerprint may be trusted across this:
        // leave a marker first (a pass running now then cannot be confirmed) and, once
        // records were actually removed, drop them. An erasure that matched nothing puts
        // the marker back and keeps them. Only where fingerprints are kept.
        // Read fresh (config::refresh() drops this process's cached copy, which a long cron
        // process can hold stale), so a newer marker is never rolled back.
        $armed = change_ledger::armed();
        if ($armed) {
            config::refresh();
        }
        $priormarker = $armed ? get_config(config::COMPONENT, 'last_erasure_time') : false;
        $ourmarker = (string)time();
        if ($armed) {
            set_config('last_erasure_time', $ourmarker, config::COMPONENT);
        }

        // Drop stray rewrite temporaries first. One can hold a copy of the very
        // records this call is about to erase, and it is not in own_files(), so
        // without this an erasure could report success while a full copy of the
        // pre-erasure content stayed on disk. Deleting rather than rewriting is
        // correct because a temporary is never authoritative — its rename() never
        // happened, so the original is intact and the loop below erases from that.
        // Age-guarded so a rewrite still in flight in another process keeps its
        // temp and its rename() succeeds.
        foreach (config::tracked_buffer_dirs() as $dir) {
            self::purge_residue($dir, self::RESIDUE_STRAY_SEC);
        }

        foreach (self::own_files() as $path) {
            // Active file with a live writer — or one we cannot ask about
            // because its writer is on another host: leave it strictly alone.
            if (substr($path, -6) === '.jsonl') {
                if (!self::is_own_host($path)) {
                    $result['skipped']++;
                    continue;
                }
                $parsed = self::parse_name($path);
                $pid = $parsed !== null ? $parsed['pid'] : 0;
                if ($pid > 0 && shipper::pid_alive($pid)) {
                    $result['skipped']++;
                    continue;
                }
            }

            $fh = self::safe_open($path);
            if (!$fh) {
                $result['skipped']++;
                continue;
            }

            // Unpredictable name plus 'xb' (O_CREAT|O_EXCL): a temp path built
            // only from the pid can be pre-created as a symlink, which would send
            // this rewrite's output somewhere else entirely. O_EXCL refuses to open
            // anything that already exists — a symlink included — so there is no
            // check to lose a race on, and the random component keeps a temp left
            // by a crashed run from wedging the same path next time.
            $tmp = $path . '.privacy-' . getmypid() . '-' . self::temp_suffix() . '.tmp';
            $out = @fopen($tmp, 'xb');
            if (!$out) {
                fclose($fh);
                $result['skipped']++;
                continue;
            }

            $removed = 0;
            $kept = 0;
            $ok = true;
            try {
                while (($line = fgets($fh)) !== false) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }
                    $rec = json_decode($trimmed, true);
                    $drop = false;
                    if (is_array($rec)) {
                        foreach (self::record_user_ids($rec) as $uid) {
                            if (isset($targets[$uid])) {
                                $drop = true;
                                break;
                            }
                        }
                    }
                    if ($drop) {
                        $removed++;
                        continue;
                    }
                    // An undecodable line is kept verbatim: it cannot be shown to
                    // belong to the subject, and silently dropping buffered data we
                    // failed to parse would lose someone else's events.
                    if (fwrite($out, $trimmed . "\n") === false) {
                        $ok = false;
                        break;
                    }
                    $kept++;
                }
            } finally {
                fclose($fh);
                fclose($out);
            }

            if (!$ok) {
                @unlink($tmp);
                $result['skipped']++;
                continue;
            }

            if ($removed === 0) {
                // Nothing to do; do not churn the file or disturb its mtime, which
                // the shipper's idle detection reads.
                @unlink($tmp);
                $result['kept'] += $kept;
                continue;
            }

            if ($kept === 0 && substr($path, -6) !== '.jsonl') {
                // Every record in a writer-less file was the subject's: nothing is
                // owed from it any more, so remove it rather than leave an empty file
                // (a parked one would otherwise keep the status page red for nothing).
                @unlink($tmp);
                if (self::remove_file($path)) {
                    $result['files']++;
                    $result['removed'] += $removed;
                } else {
                    $result['skipped']++;
                }
                continue;
            }
            @chmod($tmp, self::file_permissions());
            if (@rename($tmp, $path)) {
                $result['files']++;
                $result['removed'] += $removed;
                $result['kept'] += $kept;
            } else {
                @unlink($tmp);
                $result['skipped']++;
            }
        }

        if ($armed && $result['removed'] > 0) {
            change_ledger::clear_all('a privacy erasure removed buffered records');
        } else if ($armed) {
            config::refresh();
            // Only while the marker is still ours: a concurrent erasure or purge may have
            // set a newer one that must stand.
            if ((string)get_config(config::COMPONENT, 'last_erasure_time') === $ourmarker) {
                if ($priormarker === false) {
                    unset_config('last_erasure_time', config::COMPONENT);
                } else {
                    set_config('last_erasure_time', $priormarker, config::COMPONENT);
                }
            }
        }
        return $result;
    }

    /**
     * Remove this plugin's buffer files and, if they leave nothing behind, the
     * directories that held them. Called from db/uninstall.php.
     *
     * Deliberately NOT a recursive delete. Core's fulldelete() erases whatever
     * directory it is handed, so pointing it at a configured path made the
     * blast radius a function of a config value — an admin (or the control
     * plane) could aim it at the dataroot or filedir and a routine uninstall
     * would destroy the site's file store. Instead:
     *
     *   - only files matching this plugin's own naming are unlinked, so a
     *     directory holding anything else keeps that content; and
     *   - the directories are removed with rmdir(), which fails on a non-empty
     *     directory, so a shared path can never be taken with them.
     *
     * The result is that no value of `bufferdir` can make this destructive.
     *
     * Every directory config::tracked_buffer_dirs() names is purged, not just the
     * one in use — and tracked_ rather than buffer_dirs() so a directory that no
     * longer validates (moodledata moved, or a rule tightened) is still cleaned:
     * uninstall is the site's last chance to remove this personal data, and a
     * validity check that hid it here would leave it on disk after the plugin was
     * gone, with nothing left installed that knew it was there.
     *
     * @return array{files:int,dirs:int,dir:string,previousdirs:int} Counts removed, the
     *         current dir, and how many PREVIOUS dirs were found on disk and purged.
     */
    public static function purge_all(): array {
        $dir = config::buffer_dir();
        $dirs = config::tracked_buffer_dirs();
        $removedfiles = 0;
        $removeddirs = 0;
        $previousdirs = 0;

        foreach ($dirs as $target) {
            if ($target === '' || !is_dir($target)) {
                continue;
            }
            if ($target !== $dir) {
                $previousdirs++;
            }
            // Rewrite temporaries are removed with no age guard: an uninstall has
            // nothing in flight, and leaving one behind would both keep records on
            // disk after the plugin is gone and make the rmdir() below decline,
            // stranding the directory for ever — the two outcomes this method
            // exists to prevent.
            $removedfiles += self::purge_residue($target);
            foreach (self::own_file_patterns() as $pattern) {
                foreach (glob($target . '/' . $pattern) ?: [] as $path) {
                    // Defensive: never follow a symlink out of the buffer dir.
                    // A symlink is removed HERE and only here. unlink() acts on
                    // the link itself, never on what it points at, so this cannot
                    // delete anything outside the buffer dir — and leaving one
                    // behind would defeat the rmdir() below and strand the
                    // directory forever on an uninstalled site. Anything else that
                    // is not a plain file (a directory, a FIFO) is left alone and
                    // rmdir() then declines, which is the existing rule.
                    if ((self::is_safe_file($path) || is_link($path)) && @unlink($path)) {
                        $removedfiles++;
                    }
                }
            }
            // The published measurement, its lock and claim, the lane marks and the
            // per-worker pointers are this plugin's too, and would otherwise hold the
            // directory non-empty.
            $removedfiles += self::remove_bookkeeping($target);
            // Succeeds only if nothing else lives here.
            if (@rmdir($target)) {
                $removeddirs++;
            }
        }

        // Tidy the plugin's own moodledata root when the buffer was the only
        // thing in it. Same rmdir semantics: a no-op if anything else remains.
        $root = config::buffer_root();
        if (is_dir($root) && @rmdir($root)) {
            $removeddirs++;
        }

        // Whatever was un-shipped is gone: no fingerprint can be trusted any more. The
        // marker first, so a pass running right now cannot be confirmed either.
        if (change_ledger::armed()) {
            set_config('last_erasure_time', time(), config::COMPONENT);
        }
        change_ledger::clear_all('the buffer was purged');

        return [
            'files' => $removedfiles,
            'dirs' => $removeddirs,
            'dir' => $dir,
            'previousdirs' => $previousdirs,
        ];
    }

    /**
     * Whether the buffer directory is under its configured byte cap.
     *
     * Accounts for every file this plugin owns — active, closed, drained and
     * parked — the same accounting the shipper measures and publishes, so the two
     * agree about what "full" means.
     *
     * Sizing the directory costs one stat per buffer file, and with tens of
     * thousands of files on network storage that is minutes. It used to happen on
     * every new buffer file, which on a web request means at the cap, on every
     * capturing request, because the cooldown below lives only as long as the
     * request. So a web request reads the size the shipper publishes on every run
     * instead (see publish_capacity()), and refuses only on a measured size at or
     * over the cap. While that measurement is fresh (the shipper is running) a web
     * request never sizes the directory; the buffer can grow past the cap by the
     * inflow until the measurement goes stale (capacity_stale(): two ship intervals,
     * at least SHIP_IDLE_SEC). Only when the measurement is stale, because the shipper
     * has stopped running, does a web request measure: one request at a time
     * (measure_exclusive(), through the lock or, without one, a claim file), and not
     * again within WEB_MEASURE_MIN_SEC of any measurement, while every other request
     * decides on the last one. That keeps
     * the cap enforced by a real measurement even when cron has stopped.
     *
     * A CLI process (cron exporters, the backfill) measures exactly, as it always
     * has, with one exception. When a fresh published measurement, projected
     * forward at the shipper's observed inflow, plus every byte this process has
     * appended since that measurement (own_bytes_since()), plus one more file's
     * worth (rotate_size_bytes() plus one record, MAX_EVENT_BYTES: a file rotates
     * only after the append that crosses the size) is still below the cap,
     * measuring cannot change the answer, so it is skipped. Only an exact
     * measurement ever refuses, so the skip can spare a directory read but can never
     * cost a record. What it does not see is another CLI writer's output since the
     * measurement beyond the projected inflow; with several running at once, each
     * can be admitted on the same headroom, so the buffer can pass the cap by up to
     * that headroom per extra writer until the next measurement. The long writers
     * (backfill, sweep) also stop at half the cap on their own backpressure, which
     * bounds that; it is the same soft-cap trade as a web request's projection.
     *
     * @param string $dir Buffer directory.
     * @return bool
     */
    private static function have_capacity(string $dir): bool {
        $cap = config::max_buffer_bytes();
        $state = self::fresh_capacity($dir);
        if (CLI_SCRIPT) {
            if ($state !== null) {
                $own = self::own_bytes_since($state['at']);
                $onefile = config::rotate_size_bytes() + self::MAX_EVENT_BYTES;
                if ($own !== null && self::projected_bytes($state) + $own + $onefile < $cap) {
                    return true;
                }
            }
            return self::capacity_verdict(self::measure($dir), $cap);
        }
        if (self::web_decides_on($state)) {
            return self::capacity_verdict($state['bytes'], $cap);
        }
        $total = self::measure_exclusive($dir);
        if ($total === null) {
            // Another request is measuring right now; decide on the last measurement.
            $last = self::read_capacity($dir);
            return $last === null || self::capacity_verdict($last['bytes'], $cap);
        }
        return self::capacity_verdict($total, $cap);
    }

    /**
     * A position a lane keeps beside the buffer rather than in plugin config, where
     * every write purges the plugin's config cache.
     *
     * @param string $key Lane name.
     * @return string|null The stored numeric string, or null when there is none.
     */
    public static function read_mark(string $key): ?string {
        $dir = config::buffer_dir();
        $raw = $dir === '' ? null : self::read_small_file($dir . '/' . self::MARKS_FILE, 4096);
        $marks = $raw === null ? null : json_decode($raw, true);
        $value = is_array($marks) ? ($marks[$key] ?? null) : null;
        return is_string($value) && is_numeric($value) ? $value : null;
    }

    /**
     * Store a position read_mark() returns.
     *
     * @param string $key Lane name.
     * @param string $value Numeric string.
     * @return bool Whether it was written.
     */
    public static function write_mark(string $key, string $value): bool {
        $dir = config::buffer_dir();
        if ($dir === '' || !is_dir($dir) || !is_numeric($value)) {
            return false;
        }
        $raw = self::read_small_file($dir . '/' . self::MARKS_FILE, 4096);
        $marks = $raw === null ? [] : (array)json_decode($raw, true);
        $marks[$key] = $value;
        $json = json_encode($marks, JSON_UNESCAPED_SLASHES);
        return $json !== false && self::replace_small_file($dir . '/' . self::MARKS_FILE, $json);
    }

    /**
     * Whether a web request decides on the published measurement rather than
     * sizing the directory itself: whenever that measurement is fresh, that is while
     * the shipper is running and republishing every run. Growth until it goes stale
     * is the documented soft-cap overshoot; a page load never sizes the directory
     * then; a long ship run republishes as it goes, so it stays fresh through the run.
     * A stale measurement is left to measure_exclusive(): one request at a time, through
     * the lock or a claim file, and not within WEB_MEASURE_MIN_SEC of the last one.
     *
     * @param array|null $fresh fresh_capacity(), or null when there is none.
     * @return bool
     */
    public static function web_decides_on(?array $fresh): bool {
        return $fresh !== null;
    }

    /**
     * Turn a measured size into the capacity answer, recording a refusal.
     *
     * @param int $bytes Measured buffer size.
     * @param int $cap Configured cap.
     * @return bool
     */
    private static function capacity_verdict(int $bytes, int $cap): bool {
        if ($bytes < $cap) {
            return true;
        }
        self::note_cap_refusal($bytes, $cap);
        return false;
    }

    /**
     * Bytes this plugin occupies in the buffer directory — active, closed, drained
     * (`.pulled`) and parked files.
     *
     * The same accounting as measure_dir(), which the capacity check, the shipper's
     * publish and the backfill backpressure all go through, so every answer to "how
     * full is the buffer" agrees; kept as the one-number form of it.
     *
     * @param string $dir Buffer directory.
     * @return int Total bytes.
     */
    public static function occupied_bytes(string $dir): int {
        return self::measure_dir($dir)['bytes'];
    }

    /**
     * Measure a buffer directory in one streaming read: the bytes the cap counts,
     * and how many files it holds at each stage.
     *
     * The bytes are those of this plugin's own files (own_file_patterns()), each
     * judged by safe_stat() — a plain, single-linked file — exactly as the glob this
     * replaces judged them through safe_files(), so a link or a hard link planted
     * under a buffer name adds nothing. The closed count covers every plain
     * `.closed` file, as the status page and get_status always counted them,
     * because the shipper ships those whatever their prefix.
     *
     * @param string $dir Buffer directory.
     * @return array{bytes:int, closed:int, closedbytes:int, active:int, pulled:int, parked:int}
     *         (the shipper's own publish counts parked files in previous
     *         directories too; this measures one directory)
     */
    public static function measure_dir(string $dir): array {
        $m = ['bytes' => 0, 'closed' => 0, 'closedbytes' => 0, 'active' => 0, 'pulled' => 0, 'parked' => 0];
        self::each_entry($dir, function (string $name, string $path) use (&$m) {
            $kind = self::entry_kind($name);
            if (!in_array($kind, ['active', 'closed', 'pulled', 'parked'], true)) {
                return;
            }
            $own = strpos($name, 'events-') === 0;
            if (!$own && $kind !== 'closed') {
                return;
            }
            $st = self::safe_stat($path);
            if ($st === null) {
                return;
            }
            $m[$kind]++;
            if ($own) {
                $m['bytes'] += (int)$st['size'];
                if ($kind === 'closed') {
                    $m['closedbytes'] += (int)$st['size'];
                }
            }
        });
        return $m;
    }

    /**
     * Measure the buffer exactly and publish the result.
     *
     * Public for the historical backfill's backpressure, which needs an exact answer
     * when the published one is stale or missing.
     *
     * @param string $dir Buffer directory.
     * @return int Measured bytes.
     */
    public static function measure(string $dir): int {
        $previous = self::read_capacity($dir);
        $m = self::measure_dir($dir);
        // Stamped when the walk ENDS: on slow storage a walk can take longer than a
        // measurement stays fresh, and a start-of-walk time would publish it stale.
        $at = time();
        // This directory only (measure_dir()). Parked files in previous directories
        // are counted by the shipper's scan; carry its count forward so an exact
        // measurement here does not drop them from the status banner until it runs.
        $m['parkedprev'] = $previous !== null ? $previous['parkedprev'] : 0;
        $m['parked'] += $m['parkedprev'];
        // The oldest parked file's time comes from the shipper's scan, like parkedprev.
        $m['parkedat'] = $m['parked'] > 0 && $previous !== null ? $previous['parkedat'] : 0;
        $m['truncated'] = $previous !== null ? $previous['truncated'] : 0;
        self::publish_capacity($dir, $m, $previous, $at);
        self::note_measured($at);
        return $m['bytes'];
    }

    /**
     * Measure the buffer and publish the result, unless another request is already
     * doing so.
     *
     * A non-blocking lock on a small file beside the measurement makes the measuring
     * request the only one: the others return null at once instead of each sizing
     * the directory. Where the lock cannot be opened (an unexpected file at the lock
     * path, a lock another web server user owns), measuring is single-flight through
     * a claim file instead (measure_claimed()), so the cap stays enforced by a real
     * measurement. Either way, a measurement published in the last
     * WEB_MEASURE_MIN_SEC is reused rather than walked again.
     *
     * @param string $dir Buffer directory.
     * @return int|null Measured or recently published bytes, or null when another
     *                  request is measuring (it holds the lock or the claim).
     */
    private static function measure_exclusive(string $dir): ?int {
        $lockpath = $dir . '/' . self::CAPACITY_LOCK;
        // Created, when missing, by create_lock() — which never replaces a lock that
        // is already there, so every request locks the same file — then opened without
        // create ('r+': a lock needs a writable handle on NFS). It is used only if the
        // handle is the plain file now at the path; anything swapped in meanwhile is
        // closed and ignored, and nothing is ever created or re-permissioned through
        // the path itself.
        if (@lstat($lockpath) === false) {
            self::create_lock($lockpath);
        }
        $lock = self::open_own_file($lockpath, 'r+', true, self::LOCK_MAX_LINKS);
        if ($lock === false) {
            // No lock to serialise on (the web user cannot open it, or something
            // else is at the path). The cap must still be enforced by a measurement,
            // so measure, unless one was published in the last WEB_MEASURE_MIN_SEC, and
            // only one request at a time (measure_claimed()).
            $recent = self::read_capacity($dir);
            if ($recent !== null && (time() - $recent['at']) < self::WEB_MEASURE_MIN_SEC) {
                return $recent['bytes'];
            }
            return self::measure_claimed($dir);
        }
        $wouldblock = 0;
        if (!@flock($lock, LOCK_EX | LOCK_NB, $wouldblock) && $wouldblock) {
            fclose($lock);
            return null;
        }
        // Holding it, make sure it is still THE lock: the file now at the path.
        // If it was replaced after this handle was opened, another request may
        // hold the new one, so this one leaves the measuring to it.
        clearstatcache();
        $held = fstat($lock);
        $now = @lstat($lockpath);
        if (
            $held !== false && ($now === false || $now['ino'] !== $held['ino'] || $now['dev'] !== $held['dev'])
        ) {
            @flock($lock, LOCK_UN);
            fclose($lock);
            return null;
        }
        // Another request may have measured while this one waited for the lock: at
        // most one measurement per WEB_MEASURE_MIN_SEC, whoever takes it.
        clearstatcache();
        $recent = self::read_capacity($dir);
        if ($recent !== null && (time() - $recent['at']) < self::WEB_MEASURE_MIN_SEC) {
            @flock($lock, LOCK_UN);
            fclose($lock);
            return $recent['bytes'];
        }
        try {
            return self::measure($dir);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Measure without the lock, one request at a time: the request that creates the
     * claim file measures, and every other one returns null (and decides on the last
     * measurement) until it is gone. Creating a file needs only write access to the
     * directory, which any request that can buffer has. A claim older than
     * measure_claim_stale_sec() is from a request that died mid-walk (killed by its
     * time limit, say, when its finally block never runs) and is taken over.
     *
     * @param string $dir Buffer directory.
     * @return int|null Measured bytes, or null when another request is measuring.
     */
    private static function measure_claimed(string $dir): ?int {
        $claim = $dir . '/' . self::MEASURE_CLAIM;
        $h = @fopen($claim, 'xb');
        if ($h === false) {
            $st = @lstat($claim);
            $abandoned = $st !== false && is_file($claim) && !is_link($claim)
                && time() - (int)$st['mtime'] >= self::measure_claim_stale_sec();
            if (!$abandoned) {
                return null;
            }
            @unlink($claim);
            $h = @fopen($claim, 'xb');
            if ($h === false) {
                return null;
            }
        }
        fclose($h);
        try {
            return self::measure($dir);
        } finally {
            @unlink($claim);
        }
    }

    /**
     * How old a measurement claim must be to count as abandoned: a little past this
     * request's own time limit, since a walk cannot outlive it, and at most
     * MEASURE_CLAIM_STALE_SEC when there is no limit (CLI) or it is very long.
     *
     * @return int Seconds.
     */
    private static function measure_claim_stale_sec(): int {
        $limit = (int)ini_get('max_execution_time');
        if ($limit <= 0) {
            return self::MEASURE_CLAIM_STALE_SEC;
        }
        return max(self::WEB_MEASURE_MIN_SEC, min(self::MEASURE_CLAIM_STALE_SEC, $limit + 30));
    }

    /**
     * Files, inside the buffer directory, that carry the last measured buffer size
     * and serialise measuring it. The leading dot keeps them outside every pattern
     * the buffer, the shipper and the pull service match (those start `events-` or
     * contain `.jsonl`), and glob()'s `*` never matches a leading dot either.
     *
     * The names are the ones an earlier build of this measurement (a patched 0.9.27,
     * not the stock release, which has none) used, on purpose:
     * a site upgrading from it has its lock and its pointers reused as they are, its
     * temporaries reclaimed by residue_patterns(), and its measurement replaced, not
     * misread (read_capacity() accepts only the current format).
     */
    const CAPACITY_FILE = '.capacity.json';

    /** Claim file that makes a lockless measurement single-flight (measure_claimed()). */
    public const MEASURE_CLAIM = '.capacity.measuring';

    /** Longest a measurement claim is waited on before it is taken over (seconds); see measure_claim_stale_sec(). */
    public const MEASURE_CLAIM_STALE_SEC = 600;

    /** Small per-lane positions kept beside the buffer (read_mark() / write_mark()). */
    public const MARKS_FILE = '.marks.json';

    /**
     * Shortest gap between a measurement and the next one a web request may take
     * (seconds), once the published measurement is stale: a request that gets the
     * measurement lock re-reads the published one first (measure_exclusive()).
     */
    public const WEB_MEASURE_MIN_SEC = 60;

    /** Lock file for measure_exclusive(). */
    const CAPACITY_LOCK = '.capacity.lock';

    /** Prefix of the per-worker active-file pointers (see writer_pointer()). */
    const POINTER_PREFIX = '.w-';

    /** A pointer's full name: `.w-<pid>-<token>-h<hostid>`. */
    const POINTER_RE = '/^\.w-(\d+)-([0-9a-f]+)-h([0-9a-f]+)$/';

    /**
     * Format of the published measurement.
     *
     * 2 adds the per-stage counts and the previous directories' entry counts, which
     * the status page and get_status read instead of listing the directory, and the
     * load-gate flag (see shipper::run()). A version-1 file (that earlier patched build) is
     * treated as no measurement at all: it has none of those fields, and a missing
     * count read as 0 would tell the status page the buffer is empty.
     */
    const CAPACITY_VERSION = 2;

    /** Integer fields of a measurement besides the rate, all non-negative. */
    const CAPACITY_INTS = ['at', 'bytes', 'interval', 'start', 'refbytes', 'refat',
        'closed', 'closedbytes', 'active', 'pulled', 'parked', 'parkedprev', 'bypass'];

    /**
     * The last published buffer measurement, or null when there is none usable.
     *
     * Public because the shipper derives the next inflow rate and run interval from
     * the previous measurement, and the status page, get_status and the backfill
     * read it.
     *
     * @param string $dir Buffer directory.
     * @return array|null Keys: CAPACITY_INTS, 'rate' (bytes per second), 'parkedat'
     *                    (modification time of the oldest parked file, 0 when none is
     *                    known), 'truncated' (1 when the shipper's last run stopped at its
     *                    time limit with backlog left) and 'prev' (previous buffer directory => files still
     *                    to ship from it).
     */
    public static function read_capacity(string $dir): ?array {
        $raw = self::read_small_file($dir . '/' . self::CAPACITY_FILE, 8192);
        $state = $raw === null ? null : json_decode($raw, true);
        if (
            !is_array($state) || ($state['v'] ?? null) !== self::CAPACITY_VERSION
                || ($state['dir'] ?? null) !== $dir
        ) {
            return null;
        }
        $out = [];
        foreach (array_merge(self::CAPACITY_INTS, ['rate']) as $key) {
            $value = $state[$key] ?? null;
            if (
                !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0
                    || (float)$value >= PHP_INT_MAX
            ) {
                return null;
            }
            $out[$key] = $key === 'rate' ? (float)$value : (int)$value;
        }
        // Optional, added after the format: a measurement without it reads as 0 (no
        // parked file known) rather than as unusable.
        $parkedat = $state['parkedat'] ?? 0;
        $out['parkedat'] = is_int($parkedat) && $parkedat > 0 ? $parkedat : 0;
        $out['truncated'] = ($state['truncated'] ?? 0) === 1 ? 1 : 0;
        $prev = $state['prev'] ?? null;
        if (!is_array($prev) || count($prev) > config::MAX_BUFFER_DIRS) {
            return null;
        }
        $out['prev'] = [];
        foreach ($prev as $pdir => $count) {
            if (!is_string($pdir) || $pdir === '' || !is_int($count) || $count < 0) {
                return null;
            }
            $out['prev'][$pdir] = $count;
        }
        // A measurement from the future, an inflow faster than the whole cap per
        // second, or a run interval longer than publish_capacity() ever records, is
        // not one this plugin wrote; ignore it rather than trust it.
        if (
            $out['at'] > time() + shipper::SHIP_IDLE_SEC
            || $out['rate'] > config::max_buffer_bytes()
            || $out['interval'] > self::RESIDUE_STRAY_SEC
            || $out['bypass'] > 1
        ) {
            return null;
        }
        return $out;
    }

    /**
     * The published measurement, only while it is recent enough to decide on.
     *
     * @param string $dir Buffer directory.
     * @return array|null As read_capacity().
     */
    public static function fresh_capacity(string $dir): ?array {
        $state = self::read_capacity($dir);
        return ($state !== null && !self::capacity_stale($state)) ? $state : null;
    }

    /**
     * Whether a published measurement is too old to decide on.
     *
     * The shipper republishes on every run, so a measurement older than two of its
     * observed run intervals means runs are being missed. The floor is the shipper's
     * own idle threshold, the shortest interval at which it looks at the directory.
     *
     * @param array $state From read_capacity().
     * @return bool
     */
    private static function capacity_stale(array $state): bool {
        $limit = max(shipper::SHIP_IDLE_SEC, 2 * $state['interval']);
        return (time() - $state['at']) > $limit;
    }

    /**
     * A measurement projected to now at the shipper's observed inflow.
     *
     * @param array $state From read_capacity().
     * @return int
     */
    public static function projected_bytes(array $state): int {
        $projected = $state['bytes'] + $state['rate'] * max(0, time() - $state['at']);
        return $projected >= PHP_INT_MAX ? PHP_INT_MAX : (int)$projected;
    }

    /**
     * Bytes this process has appended since a measurement taken at $at, or null when
     * that cannot be bounded.
     *
     * The projection in a published measurement is the shipper's view of the whole
     * site's inflow between its runs. A long CLI writer (the historical backfill,
     * the verification sweep) can add far more than that in between, on its own, so
     * the projection alone would let it write past the cap while every check said
     * there was room. Counting its own output closes that: the counter holds every
     * byte this process appended since $ownsince, which is either the start of the
     * process or the moment this process last saw an exact measurement
     * (note_measured()). It bounds the bytes since $at from above when $at is at or
     * after $ownsince. An older $at would need bytes the counter has already
     * forgotten, so that is reported as unknown and the caller measures.
     *
     * @param int $at Unix time of the measurement.
     * @return int|null
     */
    public static function own_bytes_since(int $at): ?int {
        if (self::$ownsince !== 0 && $at < self::$ownsince) {
            return null;
        }
        return self::$ownbytes;
    }

    /**
     * Record that an exact measurement taken at $at includes every byte this process
     * has appended so far.
     *
     * Public for the shipper, whose start-of-run inventory is such a measurement for
     * the process running it: a backfill that ships inline has flushed its file
     * first, and nothing appends while the shipper runs.
     *
     * @param int $at Unix time up to which the measurement covers this process's
     *        appends (measure() passes the end of its walk, the shipper its run start).
     * @return void
     */
    public static function note_measured(int $at): void {
        self::$ownsince = max(1, $at);
        self::$ownbytes = 0;
    }

    /** publish_capacity() mode: any measurement other than the shipper's. */
    const PUBLISH_PLAIN = 0;

    /** publish_capacity() mode: the shipper's start-of-run inventory. */
    const PUBLISH_RUN_START = 1;

    /** publish_capacity() mode: what the shipper left after shipping. */
    const PUBLISH_RUN_END = 2;

    /**
     * Publish a buffer measurement for the capture path to read.
     *
     * Written to a temporary with an unpredictable name and renamed into place, so a
     * reader sees either the old measurement or the new one. A failed write leaves
     * the previous measurement; once that is stale, capture measures for itself
     * again, so a failure here costs speed, never the bound.
     *
     * The inflow rate is derived only from the shipper's own measurements: from what
     * the previous run left (its reference point) to what this run finds. Other
     * processes publish too (a cron export sizes the buffer when it opens a file), so
     * deriving it from whatever was published last would measure a second of an
     * export instead of the growth between runs. The run interval is the latest gap
     * between run starts, clamped to RESIDUE_STRAY_SEC, so a cron outage cannot
     * stretch how long a measurement stays usable beyond that.
     *
     * @param string $dir Buffer directory.
     * @param array $fields 'bytes', and any of the counts measure_dir() returns,
     *        'prev', 'parkedprev', 'bypass', 'parkedat' and 'truncated'. A field left out keeps its
     *        previous value.
     * @param array|null $previous The measurement this replaces, from read_capacity().
     * @param int $at Unix time the measurement describes.
     * @param int $mode One of the PUBLISH_* constants.
     * @return void
     */
    public static function publish_capacity(
        string $dir,
        array $fields,
        ?array $previous,
        int $at,
        int $mode = self::PUBLISH_PLAIN
    ): void {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $state = $previous ?? ['rate' => 0.0, 'prev' => [], 'parkedat' => 0, 'truncated' => 0]
            + array_fill_keys(self::CAPACITY_INTS, 0);
        foreach ($fields as $key => $value) {
            if ($key === 'prev') {
                $state['prev'] = $value;
            } else if ($key === 'parkedat' || $key === 'truncated' || in_array($key, self::CAPACITY_INTS, true)) {
                $state[$key] = max(0, (int)$value);
            }
        }
        $bytes = $state['bytes'];
        $state['at'] = $at;
        if ($mode === self::PUBLISH_RUN_START) {
            if ($state['refat'] > 0 && $at > $state['refat']) {
                $state['rate'] = max(0, $bytes - $state['refbytes']) / ($at - $state['refat']);
            }
            if ($state['start'] > 0 && $at > $state['start']) {
                // The latest gap between run starts, so one extra run (a backfill
                // ships inline) moves it only until the next scheduled run. Clamped,
                // so an outage cannot stretch how long a measurement is trusted.
                $state['interval'] = min($at - $state['start'], self::RESIDUE_STRAY_SEC);
            }
            $state['start'] = $at;
        }
        if ($mode === self::PUBLISH_RUN_START || $mode === self::PUBLISH_RUN_END) {
            $state['refbytes'] = $bytes;
            $state['refat'] = $at;
        }
        self::write_capacity($dir, $state);
    }

    /**
     * Take what a pull drain removed off the published measurement.
     *
     * A measurement taken after the drain started may already leave out what it
     * removed, and taking it off again would under-report the buffer, so such a
     * measurement is left as it is.
     *
     * @param string $dir Buffer directory.
     * @param int $freed Bytes removed.
     * @param int $files Closed files removed outright.
     * @param int $since When the drain started.
     * @return void
     */
    public static function publish_drain(string $dir, int $freed, int $files, int $since): void {
        $state = self::read_capacity($dir);
        if ($state === null || ($freed <= 0 && $files <= 0) || $state['at'] >= $since) {
            return;
        }
        $state['bytes'] = max(0, $state['bytes'] - $freed);
        $state['refbytes'] = max(0, $state['refbytes'] - $freed);
        $state['closedbytes'] = max(0, $state['closedbytes'] - $freed);
        $state['closed'] = max(0, $state['closed'] - $files);
        self::write_capacity($dir, $state);
    }

    /**
     * Write a measurement to the capacity file.
     *
     * @param string $dir Buffer directory.
     * @param array $state Keys as read_capacity() returns.
     * @return void
     */
    private static function write_capacity(string $dir, array $state): void {
        $out = ['v' => self::CAPACITY_VERSION, 'dir' => $dir];
        foreach (self::CAPACITY_INTS as $key) {
            $out[$key] = (int)$state[$key];
        }
        $out['rate'] = (float)$state['rate'];
        $out['parkedat'] = max(0, (int)($state['parkedat'] ?? 0));
        $out['truncated'] = (int)($state['truncated'] ?? 0) === 1 ? 1 : 0;
        $prev = [];
        foreach (array_slice((array)$state['prev'], 0, config::MAX_BUFFER_DIRS, true) as $pdir => $count) {
            $prev[(string)$pdir] = max(0, (int)$count);
        }
        // An object even when empty, so it reads back as the same shape.
        $out['prev'] = (object)$prev;
        $json = json_encode($out, JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            self::replace_small_file($dir . '/' . self::CAPACITY_FILE, $json);
        }
    }

    /**
     * Read a small file this plugin wrote, or null.
     *
     * A plain file only: never a link, a FIFO or a device, which could redirect the
     * read or block the request; and never more than $max bytes.
     *
     * @param string $path
     * @param int $max
     * @return string|null
     */
    private static function read_small_file(string $path, int $max): ?string {
        $fh = self::open_own_file($path, 'rb', false);
        if ($fh === false) {
            return null;
        }
        // One byte more than allowed tells an oversized file from a full-size one
        // without another fstat().
        $raw = stream_get_contents($fh, $max + 1);
        fclose($fh);
        return ($raw === false || strlen($raw) > $max) ? null : $raw;
    }

    /**
     * Open a bookkeeping file only if it is, and stays, a plain file.
     *
     * Checked before opening (never open a link, a FIFO or a device by name) and
     * again after (the handle must be the same file the path names), so a link or
     * special file swapped in between the two is closed without being read from or
     * written to. The open itself is non-blocking (fopen's `n` flag, O_NONBLOCK),
     * so a FIFO swapped in between the two cannot hold the request either.
     *
     * The check after opening that the path still names the handle matters only for
     * the lock, which every process must take on the SAME file; a reader already
     * holds the file it verified, whatever the name points at by then, so it skips
     * that stat ($atpath false).
     *
     * @param string $path
     * @param string $mode fopen() mode; never one that creates.
     * @param bool $atpath Also require the path to still name the handle.
     * @param int $maxlinks Most hard links accepted: 1, except for the lock
     *        (LOCK_MAX_LINKS), which carries no data.
     * @return resource|false
     */
    private static function open_own_file(string $path, string $mode, bool $atpath = true, int $maxlinks = 1) {
        // A writing open also drops the path's realpath entry; see safe_open().
        clearstatcache(strpbrk($mode, 'waxc+') !== false, $path);
        $before = @lstat($path);
        if (
            $before === false || ($before['mode'] & self::STAT_TYPE_MASK) !== self::STAT_TYPE_REGULAR
                || (int)$before['nlink'] < 1 || (int)$before['nlink'] > $maxlinks
        ) {
            return false;
        }
        $fh = @fopen($path, $mode . 'n');
        if ($fh === false) {
            return false;
        }
        $opened = fstat($fh);
        if ($opened === false || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) {
            fclose($fh);
            return false;
        }
        if ($atpath) {
            clearstatcache();
            $after = @lstat($path);
            if (
                $after === false || ($after['mode'] & self::STAT_TYPE_MASK) !== self::STAT_TYPE_REGULAR
                || $after['ino'] !== $before['ino'] || $after['dev'] !== $before['dev']
            ) {
                fclose($fh);
                return false;
            }
        }
        return $fh;
    }

    /**
     * Atomically replace a small file this plugin owns.
     *
     * The temporary gets a name no other process can predict (host, pid and random
     * bytes), so nothing can be planted at it in advance; it is then renamed over
     * the target, and a rename replaces a planted link rather than following it.
     * Deliberately not temp_suffix(): that falls back to a hash of the pid and the
     * time where no CSPRNG exists, and here an unpredictable name is the protection
     * itself (fopen's 'x' does not refuse a dangling link planted at a known name),
     * so without one nothing is written.
     *
     * @param string $path
     * @param string $content
     * @return bool Whether the file now holds $content.
     */
    private static function replace_small_file(string $path, string $content): bool {
        $tmp = self::write_temp($path, $content);
        if ($tmp === null) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Write $content to a new temporary beside $path, with the site's file mode.
     *
     * The name no other process can predict (host, pid and random bytes) is the
     * protection here: fopen('x') does not refuse a dangling link planted at a known
     * name, so without a CSPRNG nothing is written at all.
     *
     * @param string $path The file the temporary stands in for.
     * @param string $content
     * @return string|null The temporary's path, or null when it could not be written.
     */
    private static function write_temp(string $path, string $content): ?string {
        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (\Exception $e) {
            return null;
        }
        $tmp = $path . '.tmp-' . self::host_id() . '-' . getmypid() . '-' . $suffix;
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            return null;
        }
        $ok = @fwrite($fh, $content) === strlen($content);
        $st = fstat($fh);
        fclose($fh);
        if (!$ok) {
            @unlink($tmp);
            return null;
        }
        // A chmod() works by name, and the temporary's name is visible once it
        // exists; only reach for it when the file does not already carry the
        // site's mode, which it normally does straight from creation.
        if ($st === false || ($st['mode'] & 07777) !== self::file_permissions()) {
            @chmod($tmp, self::file_permissions());
        }
        return $tmp;
    }

    /**
     * Create the measurement lock if nothing is at its name yet.
     *
     * It must never REPLACE one: requests that each put their own file at the name
     * (as a rename does) lock different files, and then several of them measure at
     * once — which is what the lock exists to stop, and what happened the first time
     * the lock was created under concurrent requests. So the lock is written as a
     * temporary (write_temp()) and hard-linked to its name: link() fails when
     * anything is already there, a link included, and never follows one. The loser
     * simply opens the lock the winner made. Between the link and the temporary's
     * removal the lock has two links; it carries no data, so it is opened with up to
     * two (LOCK_MAX_LINKS) — every request still locks the same inode — and a
     * creator killed in between leaves a usable lock whose temporary the shipper
     * reclaims as residue.
     *
     * Where the filesystem has no hard links, or link() is disabled, link() fails
     * with nothing at the name, and the rename this used before is the fallback:
     * correct, just not single-flight the first time.
     *
     * Named limitation: the lock is created by whichever process gets there first. If
     * that is a cron running as root on a host without the posix extension (so it
     * cannot tell it is root), or the web server and cron users are split so that one
     * cannot open the other's lock for writing, the other side measures without the
     * lock (open_own_file() fails): one request at a time through a claim file
     * (measure_claimed()), and not within WEB_MEASURE_MIN_SEC of the last published
     * measurement. Correct, only a little more work.
     *
     * Public for the shipper, which creates it on its run so that web requests rarely
     * have to.
     *
     * @param string $lockpath
     * @return void
     */
    public static function create_lock(string $lockpath): void {
        $tmp = self::write_temp($lockpath, '');
        if ($tmp === null) {
            return;
        }
        // The link() function may be listed in disable_functions, which throws an Error on PHP 8
        // rather than returning false: ask first, and fall back to the rename.
        if (!function_exists('link') || !@link($tmp, $lockpath)) {
            clearstatcache(true, $lockpath);
            if (@lstat($lockpath) === false && @rename($tmp, $lockpath)) {
                return;
            }
        }
        @unlink($tmp);
    }

    /**
     * Most links the lock file may have and still be used: its own name, plus the
     * creation temporary for the instant between create_lock()'s link and unlink
     * (or for good, until residue reclaim, if the creator was killed then). Every
     * other bookkeeping and record file must have exactly one.
     */
    const LOCK_MAX_LINKS = 2;

    /**
     * Whether a path is a plain file with at most LOCK_MAX_LINKS links: the rule the
     * lock is opened by.
     *
     * @param string $path
     * @return bool
     */
    public static function open_ok_lock(string $path): bool {
        clearstatcache();
        $st = @lstat($path);
        return $st !== false && ($st['mode'] & self::STAT_TYPE_MASK) === self::STAT_TYPE_REGULAR
            && (int)$st['nlink'] <= self::LOCK_MAX_LINKS;
    }

    /**
     * Remove the bookkeeping dot-files from a directory: the published measurement,
     * its lock and measurement claim, the lane marks and the per-worker pointers.
     *
     * For the uninstall purge and for a previous buffer directory the shipper stops
     * tracking; left behind, they would hold either directory non-empty. They carry a
     * byte count, a mark and buffer file names only, never a record. A plain file or a link
     * (removed by the link, never its target) goes; anything else is left, and the
     * rmdir() that follows declines, which is the rule purge_all() applies to every
     * other entry. Their temporaries are residue (residue_patterns()).
     *
     * @param string $dir
     * @return int Files removed.
     */
    public static function remove_bookkeeping(string $dir): int {
        $removed = 0;
        self::each_entry($dir, function (string $name, string $path) use (&$removed) {
            if (!in_array(self::entry_kind($name), ['state', 'lock', 'pointer'], true)) {
                return;
            }
            $lock = $name === self::CAPACITY_LOCK && self::open_ok_lock($path);
            if ((self::is_safe_file($path) || is_link($path) || $lock) && @unlink($path)) {
                $removed++;
            }
        });
        return $removed;
    }

    /**
     * Remove one active-file pointer if its worker is gone, from the shipper's scan.
     *
     * A pointer is kept while its worker may still be alive, including one that
     * names a file that has since rotated or one that records "no active file": that
     * is what keeps a live worker from reading the directory. A worker on this host
     * is judged by its process; one on another node (shared moodledata) cannot be,
     * so its pointer goes once it has not been rewritten for RESIDUE_STRAY_SEC. A
     * live worker that loses its pointer that way reads the directory once and
     * writes it again. Pointers therefore stay bounded by the live workers plus the
     * ones that stopped within that interval. Anything at a pointer name that is not
     * a plain file goes too (by the link, never its target).
     *
     * @param string $path Entry whose name entry_kind() classed as 'pointer'.
     * @return bool Whether it was removed.
     */
    public static function reclaim_pointer(string $path): bool {
        if (!preg_match(self::POINTER_RE, basename($path), $m)) {
            return false;
        }
        clearstatcache(true, $path);
        $st = @lstat($path);
        if ($st === false) {
            return false;
        }
        if (($st['mode'] & self::STAT_TYPE_MASK) !== self::STAT_TYPE_REGULAR) {
            $gone = true;
        } else if ($m[3] === self::host_id()) {
            $gone = !shipper::pid_alive((int)$m[1]);
        } else {
            $gone = (time() - (int)$st['mtime']) >= self::RESIDUE_STRAY_SEC;
        }
        return $gone && @unlink($path);
    }

    /**
     * Keep, in $window, the $n oldest of the files offered to it.
     *
     * The shipper and the pull service each need the oldest few thousand files of a
     * directory that can hold tens of thousands. Holding every name and sorting them
     * all is what made both cost memory in proportion to the backlog; this holds at
     * most 2 x $n, pruning back to $n whenever it doubles, so the cost stays bounded
     * by what the caller will actually use. Oldest means modification time, then
     * path, so the order is total and repeatable.
     *
     * @param array $window Updated in place; [mtime, path, size] entries.
     * @param array $item [mtime, path, size].
     * @param int $n How many to keep.
     * @return void
     */
    public static function keep_oldest(array &$window, array $item, int $n): void {
        $window[] = $item;
        if (count($window) > 2 * max(1, $n)) {
            $window = self::oldest_first($window, $n);
        }
    }

    /**
     * The $n oldest entries of a keep_oldest() window, oldest first.
     *
     * @param array $window
     * @param int $n
     * @return array
     */
    public static function oldest_first(array $window, int $n): array {
        usort($window, function ($a, $b) {
            return ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]);
        });
        return array_slice($window, 0, max(1, $n));
    }

    /**
     * Whether capture was refused for a full buffer recently enough that it may
     * still be refusing.
     *
     * note_cap_refusal() rewrites its marker at most once per REFUSAL_NOTE_SEC while
     * refusals continue, so a buffer that is still refusing has a marker younger than
     * that window plus the gap to the next refusal. Two windows leaves one window of
     * slack; a marker older than that means a whole window passed with nothing
     * refused. The status page and get_status use this, so a green banner can no
     * longer sit beside capture that is being refused.
     *
     * @return bool
     */
    public static function capture_refused_recently(): bool {
        $last = (int)config::get('last_capfail_time', 0);
        return $last > 0 && (time() - $last) <= 2 * self::REFUSAL_NOTE_SEC;
    }

    /**
     * How many entries under this plugin's naming are still present in a directory.
     *
     * Counts the unsafe ones too, so this answers "is there anything of ours left
     * here" rather than "is there anything left we would ship". The difference
     * decides whether a previous buffer directory can be forgotten: forgetting one
     * that still holds an entry we declined to read would also drop it from the
     * uninstall purge and the privacy erasure paths, which is how it would become
     * PII nothing knows about.
     *
     * Rewrite temporaries are counted for exactly that reason, and they were the
     * hole: one matches no own_file_patterns() entry, and it is a plain single-link
     * file so unsafe_files() does not report it either. A directory holding nothing
     * but a leftover temp therefore counted 0, was reported drained, and was
     * forgotten — taking a file full of records out of the erasure and uninstall
     * paths for good. Any age guard would be wrong here: this asks whether anything
     * is present, not whether it is safe to delete yet.
     *
     * Counted, not measured in bytes: a zero-length file is still a file, and
     * occupied_bytes() cannot tell one from an empty directory.
     *
     * The bookkeeping dot-files (CAPACITY_FILE, its lock and claim, MARKS_FILE, the
     * pointers) are not counted: they hold no record, and the shipper removes them
     * (remove_bookkeeping()) when it stops tracking the directory.
     *
     * The three lists OVERLAP, so they are keyed into a set rather than summed.
     * A residue name contains `.jsonl`, so it also matches the wide `*.jsonl*`
     * glob unsafe_files() uses; a residue-named SYMLINK is consequently returned
     * by both (residue_files() admits links, unsafe_files() reports anything that
     * is not a plain single-link file) and summing counted it twice. The lists
     * also disagree on shape — unsafe_files() returns basenames, the other two
     * return full paths — so the set is keyed on the basename.
     *
     * @param string $dir Directory to inspect.
     * @return int
     */
    public static function entry_count(string $dir): int {
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }
        $seen = [];
        foreach (self::residue_files($dir) as $path) {
            $seen[basename($path)] = true;
        }
        foreach (self::safe_files($dir, self::own_file_patterns()) as $path) {
            $seen[basename($path)] = true;
        }
        foreach (self::unsafe_files($dir) as $name) {
            $seen[$name] = true;
        }
        return count($seen);
    }

    /**
     * Record that capture is being refused because the buffer is full.
     *
     * A cap that silently drops records is worse than one that fills up, so this
     * leaves both a cron-visible line and a durable marker the status page reads.
     *
     * Rate-limited to one write per REFUSAL_NOTE_SEC: every set_config() on this
     * plugin purges the plugin config cache that config::enabled() reads on every
     * page render, so an unthrottled marker would turn a full buffer into a
     * site-wide performance problem on top of a capture outage.
     *
     * @param int $bytes Measured buffer size.
     * @param int $cap Configured cap.
     * @return void
     */
    private static function note_cap_refusal(int $bytes, int $cap): void {
        $now = time();
        $last = (int)config::get('last_capfail_time', 0);
        if (($now - $last) < self::REFUSAL_NOTE_SEC) {
            return;
        }
        set_config('last_capfail_time', $now, config::COMPONENT);
        set_config('last_capfail_bytes', $bytes, config::COMPONENT);
        self::trace('local_intellistream: ALERT buffer is at its cap ('
            . $bytes . ' of ' . $cap . ' bytes) — refusing new records until the '
            . 'shipper drains it. Check that object storage is configured and that '
            . 'the host load gate is not holding shipping closed.');
    }

    /**
     * Get (opening if needed) the active file handle for this process.
     *
     * @return resource|false
     */
    private static function handle() {
        if (self::$handle !== null) {
            return self::$handle;
        }

        $dir = config::buffer_dir();
        // Moodle's make_writable_directory() applies the site's `$CFG->directorypermissions`, the
        // same policy the File API uses: on a split web/cron OS-user install a
        // web-user-only mode would leave the cron shipper unable to rename or remove
        // the files in it.
        error_clear_last();
        if (!is_dir($dir) && !@make_writable_directory($dir, false) && !is_dir($dir)) {
            // Do not fail silently: open_basedir, permission, or disk-full. The
            // '@' hides the warning from page output, but error_get_last() still
            // carries it — surface it so the cause is diagnosable. A plain file at
            // the path raises no warning at all, so name that case explicitly.
            $err = error_get_last();
            debugging('local_intellistream: cannot create buffer dir "' . $dir . '"'
                . (file_exists($dir) ? ' — a file occupies this path' : '')
                . ($err ? ' — ' . $err['message'] : '')
                . ' (check open_basedir and web-user write permission)', DEBUG_NORMAL);
            self::$handle = false;
            return false;
        }

        $pid = getmypid();
        $token = self::process_token();
        $hostid = self::host_id();
        $path = null;
        $reusestat = null;

        // Reuse this process's existing active file if it is still fresh.
        // Its name is keyed on this pid, this process's stable token AND this
        // host, so a worker re-uses its own file across requests, a recycled
        // PID can never adopt a dead worker's active file, and — on a cluster
        // sharing moodledata — a worker on another node can never adopt this
        // one's, which would give a single file two appending writers.
        //
        // The name is found through a small per-worker pointer rather than by
        // globbing the buffer directory: a glob reads every entry in the directory,
        // and this runs on the first append of every request, so its cost grew with
        // the backlog. Only when the pointer is missing (first request of a worker,
        // or after its pointer was removed) is the directory read, once.
        $pointer = self::writer_pointer($dir, $pid, $token, $hostid);
        $existing = self::find_own_active($dir, $pointer, $pid, $token, $hostid);
        if (!empty($existing)) {
            $candidate = (string)key($existing);
            $reusestat = current($existing);
            $created = self::created_from_name($candidate);
            $size = (int)$reusestat['size'];
            $tooold = (time() - $created) >= config::rotate_age_sec();
            $toobig = $size >= config::rotate_size_bytes();
            if ($tooold || $toobig) {
                self::mark_closed($candidate);
            } else {
                $path = $candidate;
                self::$bytes = $size;
                self::$created = $created;
            }
        }

        // Which branch we are in decides how the handle may be opened: an append
        // to an entry that was already on disk has to be verified, a create must
        // not race at all. See below.
        $reusing = $path !== null;

        if ($path === null) {
            // Backpressure, and the ONLY enforcement of max_buffer_bytes(): the
            // shipper never deletes an undelivered file to make room. When object
            // storage is unconfigured, the host load gate is closed (the scheduled
            // ship task does ship through it once the backlog passes half the cap,
            // see shipper::backlog_over_gate()), or the endpoint is failing,
            // nothing drains, so without this the buffer would grow without any
            // bound at all.
            //
            // Refusing a NEW file is the bound, and it is the right kind: it stops
            // accepting more rather than deleting what is already captured but not
            // yet shipped. An append to a file this process already holds is never
            // refused — that file is bounded by rotate_size_bytes() and cutting a
            // writer off mid-file would lose the records in flight. That is also
            // why the cap is soft: each writer that was admitted can finish the
            // file it holds, and admission rests on the last published measurement,
            // or on a measurement taken when that has gone stale (see have_capacity()).
            //
            // Decided once per new file, not per append, so the cost lands at a
            // rotation boundary rather than on every event.
            if (self::$capretryat > time()) {
                // Still inside the cooldown from a recent refusal. Returning
                // without re-measuring keeps the directory scan off the per-record
                // path while the buffer stays full.
                return false;
            }
            if (!self::have_capacity($dir)) {
                // Deliberately NOT self::$handle = false. That value means "opening
                // failed permanently for this process", and handle() short-circuits
                // on it forever after — which would make a long-lived worker or a
                // minutes-long cron export keep refusing for the rest of its run
                // even after the shipper drained the buffer. A capacity refusal is
                // transient by definition, so it is re-checked on a cooldown.
                self::$capretryat = time() + self::CAP_RECHECK_SEC;
                return false;
            }
            self::$capretryat = 0;
            $us = self::created_us();
            $path = $dir . '/events-' . $pid . '-' . $us . '-' . $token . '-h' . $hostid . '.jsonl';
            self::$bytes = 0;
            self::$created = intdiv($us, 1000000);
        }

        if ($reusing) {
            // Appending to an entry that was already on disk. is_safe_file() passed
            // at glob time, but that judged a NAME and this is an append handle:
            // writing through a planted symlink is an arbitrary-file-write with
            // attacker-influenced JSON as its content, and every component of the
            // name is derivable (pid, this process's start-time token, a hash of
            // the hostname). safe_open() hands back a descriptor it has verified is
            // the same inode it inspected, so there is no window between the two.
            // It checks against the stat find_own_active() just took rather than
            // taking another.
            $h = self::safe_open($path, 'ab', $reusestat);
        } else {
            // Creating. 'xb' is O_CREAT|O_EXCL: it makes the file or it fails, and
            // it fails if ANYTHING already occupies the name, symlink included.
            // There is no check here, so there is no race to lose — which is why
            // this branch does not go through safe_open(). O_APPEND is unnecessary:
            // the name carries this pid, this process's token, this host and the
            // creating microsecond, so this descriptor is the only writer that will
            // ever exist for it.
            $h = @fopen($path, 'xb');
            if ($h !== false) {
                // The site's file mode, as the File API applies it, rather than the
                // writing process's umask: on a split web/cron OS-user install a
                // web-user-only mode leaves the cron shipper unable to read the file,
                // which it would skip as a benign race, and the file would never ship.
                @chmod($path, self::file_permissions());
            }
            if ($h === false && @lstat($path) !== false) {
                // Distinguish "something is already there" from a permission or
                // disk error, because only the first is suspicious. Capture is
                // refused for this process rather than written through an entry
                // this plugin did not create; the next shipper run names it on the
                // status page.
                debugging('local_intellistream: refusing to create buffer file "' . $path
                    . '" because something already exists at that name and this process did not '
                    . 'write it. Nothing has been written through it.', DEBUG_NORMAL);
            }
        }
        if ($h === false) {
            self::$handle = false;
            return false;
        }
        if (!$reusing) {
            self::write_pointer($pointer, basename($path));
        }
        // Record the directory now that a file is definitely open in it, so that if
        // the buffer is pointed somewhere else later, the shipper, the privacy
        // provider and the uninstall purge still know to come back here. After the
        // open rather than before, so a directory that could not be written to is
        // never recorded; on both branches rather than only on create, so a lost
        // record repairs itself on the next rotation. A no-op when already on
        // record, which is every call but the first.
        config::note_buffer_dir($dir);
        self::$handle = $h;
        self::$path = $path;
        return $h;
    }

    /**
     * Path of this worker's active-file pointer, or null when it cannot have one.
     *
     * A dot-file in the buffer directory, keyed like the buffer file itself (pid,
     * process token, host), so no two workers or nodes share one. It holds only a
     * buffer file NAME, or nothing when the worker has no active file. The shipper
     * removes the pointers of workers that are gone (reclaim_pointer()), so they
     * stay bounded by the live workers plus those that stopped within
     * RESIDUE_STRAY_SEC.
     *
     * Only a worker with a stable process token gets one: where /proc is unreadable
     * the token is random per request, a pointer could never be found again, and
     * each request would leave one behind.
     *
     * @param string $dir Buffer directory.
     * @param int $pid
     * @param string $token
     * @param string $hostid
     * @return string|null
     */
    private static function writer_pointer(string $dir, int $pid, string $token, string $hostid): ?string {
        if (!self::$tokenstable) {
            return null;
        }
        return $dir . '/' . self::POINTER_PREFIX . $pid . '-' . $token . '-h' . $hostid;
    }

    /**
     * Point this worker's pointer at a buffer file; remove it if that fails, so a
     * stale pointer can never outlive the file it names.
     *
     * @param string|null $pointer From writer_pointer().
     * @param string $name Buffer file basename, or '' for "no active file".
     * @return void
     */
    private static function write_pointer(?string $pointer, string $name): void {
        if ($pointer !== null && !self::replace_small_file($pointer, $name)) {
            @unlink($pointer);
        }
    }

    /**
     * This worker's active buffer file, as a zero- or one-element list.
     *
     * The directory is never read. With a pointer, the named file is checked
     * directly: a pointer to a file that is gone (rotated, swept, shipped) means this
     * worker has no active file, which is exactly what a glob would have found. With
     * none (the worker's first request, a pointer the shipper reclaimed, or no
     * stable process token, where a glob could never match anyway because the token
     * is new on every request), the worker is treated as having no active file and
     * opens a new one, whose name handle() then records. A file it did have is not
     * lost: no handle is held on it between requests, so the shipper's idle sweep
     * promotes it within SHIP_IDLE_SEC like any other writer-less file. The only
     * cost is one extra buffer file, against a directory read on the request path.
     * The named file is judged by safe_stat(), as the glob's safe_files() judged
     * it, and its size comes from that same stat.
     *
     * @param string $dir Buffer directory.
     * @param string|null $pointer From writer_pointer().
     * @param int $pid
     * @param string $token
     * @param string $hostid
     * @return array Empty, or [path => its safe_stat()].
     */
    private static function find_own_active(string $dir, ?string $pointer, int $pid, string $token, string $hostid): array {
        $raw = $pointer !== null ? self::read_pointer($pointer) : null;
        if ($raw !== null) {
            $name = trim($raw);
            if ($name === '') {
                // Recorded "no active file": nothing to look for.
                return [];
            }
            // Only a name this worker could have written: the same pid, token and
            // host, in this directory. Anything else is ignored, never opened.
            $re = '/^events-' . $pid . '-\d+-' . preg_quote($token, '/') . '-h' . preg_quote($hostid, '/') . '\.jsonl$/';
            if (preg_match($re, $name)) {
                $path = $dir . '/' . $name;
                $st = self::safe_stat($path);
                return $st !== null ? [$path => $st] : [];
            }
        }
        return [];
    }

    /**
     * Read a worker pointer's content: one non-blocking open and one bounded read.
     *
     * Deliberately without read_small_file()'s type and identity checks, because on
     * this path they are paid on every request and protect nothing: the content is
     * only ever used as a name, and only after it matches this worker's own buffer
     * file name exactly (find_own_active()), and that file is then judged and
     * opened through safe_stat()/safe_open() like any buffer file. Whatever else sits
     * at the pointer's name can yield no more than that: the open is non-blocking
     * (fopen's `n`), so a FIFO reads empty instead of holding the request; a device or
     * a link to a large file reads more than a name can be and is ignored; a link to
     * another file can only supply a string, which either matches the one name this
     * worker may use or is ignored. Nothing is written or created through it.
     *
     * @param string $pointer From writer_pointer().
     * @return string|null
     */
    private static function read_pointer(string $pointer): ?string {
        $fh = @fopen($pointer, 'rbn');
        if ($fh === false) {
            return null;
        }
        $raw = @fread($fh, 257);
        fclose($fh);
        return ($raw === false || strlen($raw) > 256) ? null : $raw;
    }

    /**
     * Close the active file and mark it shippable, then reset state so the
     * next append opens a fresh file.
     */
    private static function rotate(): void {
        if (is_resource(self::$handle)) {
            @fclose(self::$handle);
        }
        if (self::$path !== null) {
            self::mark_closed(self::$path);
        }
        self::$handle = null;
        self::$path = null;
        self::$bytes = 0;
        self::$created = 0;
    }

    /**
     * Rename an active `.jsonl` file to `.jsonl.closed`.
     *
     * @param string $path
     */
    private static function mark_closed(string $path): void {
        // The previous guard was is_file(), which FOLLOWS a symlink — so it was
        // satisfied by exactly the case it appeared to exclude, and rename() then
        // gave a link a `.closed` name: a shippable name pointing anywhere on disk.
        if (self::is_safe_file($path)) {
            @rename($path, $path . '.closed');
        }
    }

    /**
     * Extract the created-time (unix seconds) encoded in a buffer filename.
     *
     * Filenames embed creation time as integer microseconds since the epoch
     * so a single process that rotates twice within the same wall-clock
     * second still produces distinct names (no .closed rename collision).
     *
     * @param string $path
     * @return int unix seconds
     */
    private static function created_from_name(string $path): int {
        $parsed = self::parse_name($path);
        if ($parsed !== null) {
            return intdiv($parsed['created_us'], 1000000);
        }
        return time();
    }

    /**
     * Current time as integer microseconds since the epoch.
     *
     * @return int
     */
    private static function created_us(): int {
        return (int)(microtime(true) * 1000000);
    }

    /**
     * This process's identity token: the OS process start time, hex-encoded.
     *
     * Stable for the whole life of the process — so a PHP-FPM worker re-uses
     * one buffer file across all of its requests — yet distinct for a
     * recycled PID (a later-starting process has a later start time). Cached
     * in a static for the request, so the underlying /proc read happens at
     * most once per request.
     *
     * Where /proc is unavailable (non-Linux) it falls back to a per-process hex
     * token: there cross-request batching degrades to the previous
     * one-file-per-request behaviour, but correctness is unaffected.
     *
     * @return string hex token
     */
    private static function process_token(): string {
        if (self::$token !== null) {
            return self::$token;
        }
        $starttime = self::proc_starttime();
        if ($starttime !== null) {
            self::$token = dechex($starttime);
            self::$tokenstable = true;
            return self::$token;
        }
        try {
            self::$token = bin2hex(random_bytes(6));
        } catch (\Exception $e) {
            // A random_bytes() failure is only possible with no CSPRNG available.
            // What this token needs is UNIQUENESS among the concurrent writers on
            // one host, not unpredictability — it is a filename component, never a
            // secret — so fall back to values that are inherently distinct per
            // process rather than to a weaker random source. Two live processes
            // cannot share a pid at the same nanosecond.
            //
            // hrtime() is PHP 7.3+, and this branch runs on the capture path of a
            // host with no /proc AND no CSPRNG. Moodle 3.9's floor is PHP 7.2.0
            // (admin/environment.xml), so calling it unguarded would be a fatal
            // there rather than the graceful degradation this fallback exists to
            // provide. microtime() is coarser but distinct enough for the job.
            $nanos = function_exists('hrtime')
                ? hrtime(true)
                : (int) (microtime(true) * 1000000000);
            self::$token = substr(hash('sha256', getmypid() . '|' . $nanos), 0, 12);
        }
        return self::$token;
    }

    /**
     * This process's start time (clock ticks since boot) from
     * `/proc/self/stat`, or null where /proc is unavailable.
     *
     * `/proc/self/stat` field 22 is starttime. Field 2 (comm) may itself
     * contain spaces and parentheses, so everything up to the final ')' is
     * skipped and the remaining whitespace-separated fields are counted from
     * there — starttime is index 19 (0-based) of that remainder.
     *
     * @return int|null
     */
    private static function proc_starttime(): ?int {
        $stat = @file_get_contents('/proc/self/stat');
        if ($stat === false || $stat === '') {
            return null;
        }
        $rparen = strrpos($stat, ')');
        if ($rparen === false) {
            return null;
        }
        $rest = preg_split('/\s+/', trim(substr($stat, $rparen + 1)));
        if ($rest === false || !isset($rest[19]) || $rest[19] === '') {
            return null;
        }
        return (int)$rest[19];
    }
}
