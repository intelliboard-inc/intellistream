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
 * `events-<pid>-<created_us>-<proctoken>-<hostid>.jsonl`: one writer per file,
 * no cross-process locking. `<proctoken>` is the OS process start time (hex):
 * stable for the life of the process, so a PHP-FPM worker re-uses its file across
 * requests, and different for a recycled PID. `<hostid>` names the writing host,
 * because on a cluster the buffer dir in moodledata is shared and PID and token
 * are only unique per host; it also tells the shipper whether its own /proc can
 * answer for the writer.
 *
 * Files rotate (rename to `.jsonl.closed`) on size or age; the shipper task
 * also sweeps stale active files belonging to idle/dead workers.
 */
class buffer {
    /** Hard cap on a single serialized event, in bytes. */
    const MAX_EVENT_BYTES = 1048576;

    /** json_encode() flags of a buffered record, so a caller can measure it the same way. */
    const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

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
     * A writer never appends to a file it has left idle this long: it rotates first.
     *
     * The shipper promotes an own-host file only after shipper::SHIP_IDLE_SEC without a
     * write (another host's after more). A writer that refuses to write into a file idle
     * for WRITER_IDLE_SEC therefore only ever writes into a file the shipper cannot yet
     * have taken; the gap between the two is the margin for a process that pauses
     * between this check and its write (see append()).
     *
     * The writer measures idle time on this node's clock; the shipper compares its own
     * clock with the file's mtime, which the file server sets when moodledata is on a
     * network file system. The same 30 s margin must therefore also cover clock skew
     * between a node and the file server: keep them in sync (NTP).
     */
    const WRITER_IDLE_SEC = shipper::SHIP_IDLE_SEC - 30;

    /** @var int Unix time of the last write (or keepalive) on the active file, 0 if none. */
    private static $lastwrite = 0;

    /**
     * @var bool The active file passed the capacity admission (or was this process's own
     *           file re-opened). Only such a file may be replaced without admission, so a
     *           chain of replacements cannot outgrow the cap.
     */
    private static $admitted = false;

    /**
     * @var bool The next new file replaces one this process already held (a rotation
     *           forced by idle time), so the capacity admission does not apply.
     */
    private static $replacing = false;

    /**
     * Append one already-serialized line. The line must include its own
     * trailing newline.
     *
     * @param string $line
     * @return bool True when the line was written; false when the capacity check
     *              refused it or the write failed or was short.
     */
    public static function append(string $line): bool {
        // Never buffer while unpaired. An empty Site ID means "not paired yet"; a
        // record captured now would carry an empty site_id (observer.php / dwell.php
        // stamp it at capture time), and the ingest service rejects such a record.
        // Refusing here, the one point every writer shares, means no such record is
        // ever created. History from before pairing is recovered by the historical
        // backfill, which runs once paired.
        if (config::site_id() === '') {
            return false;
        }
        // Rotate before the write when the held file has been idle for WRITER_IDLE_SEC:
        // the shipper takes an own-host file idle for shipper::SHIP_IDLE_SEC (another
        // host's later still), and a write into a file it has already renamed and
        // unlinked is lost with no error. Idle time is the only thing that makes a file
        // takeable while its writer lives, so it is the only check needed here; age and
        // size rotation stay after the write.
        if (self::idle_too_long()) {
            self::replace();
        }
        $h = self::handle();
        if ($h === false || $h === null) {
            return false;
        }
        // The shipper takes an own-host file shipper::SHIP_IDLE_SEC after its previous
        // write, so the pause that matters is from that write (or the open: handle()
        // seeds this from a reused file's mtime) to this one.
        $prev = self::$lastwrite;
        if (!self::write_line($h, $line)) {
            return false;
        }
        $after = time();
        self::$lastwrite = $after;
        // The file went shipper::SHIP_IDLE_SEC without a write before this one landed
        // (the process paused after the idle check, or the clock stepped): the shipper
        // may have taken it meanwhile. Write the line again into a fresh file. A
        // duplicate is harmless (every record carries a UUID and the pipeline
        // de-duplicates); a loss is not.
        if (abs($after - $prev) >= shipper::SHIP_IDLE_SEC) {
            self::replace();
            $h = self::handle();
            if ($h === false || $h === null || !self::write_line($h, $line)) {
                return false;
            }
            self::$lastwrite = $after;
        }

        // Rotate on size or age after the write: the next file goes through
        // the capacity admission. Age also bounds an FPM worker's shipping latency.
        $agedout = self::$created > 0
            && ($after - self::$created) >= config::rotate_age_sec();
        if (self::$bytes >= config::rotate_size_bytes() || $agedout) {
            self::rotate();
        }
        return true;
    }

    /**
     * Write one line to the active handle and account for it.
     *
     * @param resource $h Active handle.
     * @param string $line Serialized record with its newline.
     * @return bool Whether the whole line landed.
     */
    private static function write_line($h, string $line): bool {
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
        return true;
    }

    /**
     * Whether the held file has gone WRITER_IDLE_SEC without a write. Measured both
     * ways: a clock stepped backwards by that much is treated the same, because the
     * shipper treats a file whose mtime is that far in the future as idle too.
     *
     * @return bool
     */
    private static function idle_too_long(): bool {
        return is_resource(self::$handle) && self::$lastwrite > 0
            && abs(time() - self::$lastwrite) >= self::WRITER_IDLE_SEC;
    }

    /**
     * Rotate the held file for idle time before a write. The record would have landed
     * in the old file, so its replacement is not refused by the capacity admission —
     * but only when the old file was itself admitted: a replacement is never
     * replaced again without admission, so the cap still bounds the buffer.
     */
    private static function replace(): void {
        $admitted = self::$admitted;
        self::rotate();
        self::$replacing = $admitted;
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
     * Refusal reason: the buffer could not take the record now — the site is not
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
        $json = json_encode($payload, self::JSON_FLAGS);
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

    /** @var int Records this process has had refused for a transient reason. */
    private static $transientrefusals = 0;

    /**
     * How many records this process has had refused for a transient reason so far.
     *
     * For the lanes that cannot carry a refusal reason back from every append they
     * make (the census, a whole-table re-fetch, the verification sweep): they read this
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
        // A replacement right (replace()) belongs to the record that would have landed in
        // the file it closed. Closing now ends that moment: the next file is admitted.
        self::$replacing = false;
        if (is_resource(self::$handle) || self::$path !== null) {
            self::rotate();
        }
    }

    /**
     * Tell the shipper's sweeper that this process is still writing.
     *
     * The sweeper takes an active file untouched for shipper::SHIP_IDLE_SEC. A
     * long-lived writer (a full export holds one handle across every entity) calls
     * this between units of work to keep its file's mtime fresh, so the file keeps
     * collecting records instead of being shipped at every gap. Correctness does not
     * depend on it: append() never writes into a file idle for WRITER_IDLE_SEC.
     *
     * A file already idle WRITER_IDLE_SEC may have been taken by the shipper, so it
     * is rotated instead of touched (touch() would re-create a renamed path). Age
     * rotation uses the creation time in the filename, so a fresh mtime cannot
     * defeat it. A no-op when no file is open. Prefer this over flush() in a loop,
     * which would emit one object per unit of work.
     */
    public static function keepalive(): void {
        if (!is_resource(self::$handle) || self::$path === null) {
            return;
        }
        if (self::idle_too_long()) {
            // As in append(): the next write goes to a replacement, admitted only as
            // far as this file was, so a long export is not refused at the cap here.
            self::replace();
            return;
        }
        // Touch only the file this handle still holds: the path must name the open
        // inode (a renamed or replaced entry is left alone, never re-created).
        $st = self::safe_stat(self::$path);
        $fh = @fstat(self::$handle);
        if (
            $st !== null && $fh !== false && (int)$st['dev'] === (int)$fh['dev']
                && (int)$st['ino'] === (int)$fh['ino'] && @touch(self::$path)
        ) {
            self::$lastwrite = time();
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
     * Glob patterns covering the record-bearing files of the buffer lifecycle.
     *
     * The lifecycle is `events-<pid>-<us>-<token>-h<hostid>.jsonl` (active)
     * -> `.jsonl.closed` (shippable) -> `.jsonl.pulled` (drained by the pull web
     * service). The host-less and tokenless name forms match the same prefix. A
     * closed file that object storage keeps rejecting is renamed
     * `.jsonl.closed.parked` (see shipper::park()): the shipper and pull service
     * skip it, but it still counts against the cap and is still reached by the
     * privacy and uninstall paths through this list.
     *
     * These are the files a caller may read, ship or rewrite. They are not the only
     * files this plugin writes here (see {@see residue_patterns} and the bookkeeping
     * dot-files CAPACITY_FILE and MARKS_FILE), so do not use this list to answer
     * "is anything of ours left in this directory".
     *
     * @return string[]
     */
    public static function own_file_patterns(): array {
        return ['events-*.jsonl', 'events-*.jsonl.closed', 'events-*.jsonl.pulled', 'events-*.jsonl.closed' . self::PARKED_SUFFIX];
    }

    /** Suffix a closed file gets when object storage rejects it on its own. */
    const PARKED_SUFFIX = '.parked';

    /**
     * Glob patterns covering the rewrite temporaries this plugin writes.
     *
     * Two call sites write a sibling temp and rename() it over the original:
     * the privacy rewrite in
     * {@see \local_intellistream\local\buffer_privacy::delete_user_records()}
     * (`.privacy-<pid>-<rand>.tmp`) and the pull-export rewrite in
     * `pull_export::drain_file()` (`.rewrite-<pid>-<rand>`). A process killed between
     * the create and the rename leaves one behind; this list makes it visible to
     * entry_count(), buffer_purge::purge_all() and the privacy paths. A temporary
     * is never authoritative (the original file still holds the records), so a
     * stray one is deleted, never read or shipped.
     * The remaining patterns are the temporaries of the bookkeeping files written
     * through replace_small_file(); they hold no record.
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
                // Mirrors buffer_purge::purge_all()'s rule: a plain file, or a symlink, which
                // unlink() removes by the link and never by its target.
                $islink = is_link($path);
                if (!self::is_safe_file($path) && !$islink && !self::is_lock_temp($path)) {
                    continue;
                }
                // The age guard protects one case: a rewrite still in flight in
                // another process, whose temp must survive so its rename() lands.
                // Such a temp is always a plain file (fopen('xb') cannot open or
                // create a symlink), so a symlink is never in flight. It is not
                // age-checked: filemtime() follows the link and reports the target's
                // mtime, so a link to a recently touched file would never age out and
                // would keep its directory counted by entry_count() for ever.
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
     * Anything that can write to moodledata as the web user can leave a
     * buffer-shaped name that is not a plain file, so this is checked, not assumed.
     * `lstat()` describes a link itself, so every symlink fails without its target
     * being resolved; the type check rejects directories, sockets, devices and FIFOs
     * (a FIFO read blocks for ever); `nlink === 1` rejects a hardlink, which no
     * is_*() function can tell apart. The stat cache is cleared first (see
     * safe_stat()) so a long cron run does not answer from an old stat.
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
        // An out-of-date realpath entry can make fopen() open a different file (the
        // old target of a link that has since been replaced): for a read, the device
        // and inode check against this stat then refuses the handle before anything
        // is read; a writing open cannot rely on that, because O_CREAT may already
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
     * The empty-$dir guard matters: `glob('' . '/*')` globs the filesystem root,
     * and config::buffer_dir() returns '' when
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
     * The single place a buffer file is removed. The result matters: a removal that
     * failed but was treated as done would make the shipper re-ship the same batch
     * on every run without reaching newer files. Typical causes are host-side (a
     * sticky bit with files owned by another uid, an immutable flag, a read-only
     * remount); rename() fails under all of them too, so the caller must be told.
     *
     * A vanished file is a success. pull_export, the shipper, buffer_privacy::delete_user_records()
     * and, on shared moodledata, another node's shipper all remove the same closed
     * files, so ENOENT here is normal and must not be reported as a failure.
     *
     * @param string $path
     * @return bool True when the file is no longer on disk, whether this call
     *              removed it or something else already had.
     */
    public static function remove_file(string $path): bool {
        clearstatcache(true, $path);
        // No directory entry at all: nothing to remove, so the caller's goal is
        // already met. Checked first, because is_safe_file() also fails on an absent
        // path and would report a vanished file as a removal failure. lstat() rather
        // than file_exists(), which follows symlinks and would report a dangling
        // link as gone while its entry stays on disk.
        if (@lstat($path) === false) {
            return true;
        }
        if (!self::is_safe_file($path)) {
            // Present, but not a plain single-linked file: refuse to unlink through
            // it at all, and do not claim it is gone. Something replaced the entry.
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
     * A check-then-open pair resolves the same name twice, and an entry swapped in
     * between would be opened on the strength of a check made against another file.
     * fstat() describes the open descriptor, which no later rename() or symlink()
     * can change, so the same device and inode as the approving lstat() means "this
     * handle is that file". PHP has no O_NOFOLLOW; this is the portable equivalent.
     *
     * The inode comparison is skipped when lstat() reports 0 (no inode numbers).
     *
     * @param string $path
     * @param string $mode fopen() mode. Callers open for read, append or 'r+b' (append
     *                     at the end of an existing file without creating it) —
     *                     a create must use 'xb' instead, see handle().
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
     * Buffer-shaped entries that are not safe to touch, for reporting.
     *
     * Detection only: reads, renames and removes nothing. Used by entry_count();
     * the shipper's scan reports unsafe entries itself (shipper::scan_entry()).
     * Globs one wide `*.jsonl*` pattern rather than own_file_patterns(), so it sees
     * everything any call site could pick up, including `.rewrite-` and `.privacy-`
     * temp siblings.
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
     * A temp path built only from the pid is guessable and could be pre-created as a
     * symlink. Randomness removes the guess; O_EXCL at the call site removes the
     * race. It also stops a temp left by a crashed run, or one from another cluster
     * node with the same pid, from blocking the name.
     *
     * Falls back to a hash when no CSPRNG is available (see {@see process_token()}):
     * uniqueness here is a robustness property, not a secret. Public because
     * pull_export builds a sibling temp beside a `.closed` file the same way.
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
     * Active, closed and parked files in every buffer directory the shipper drains,
     * whose name-encoded creation time is at or before $until and whose mtime is at
     * or after $from. Not counted: `.jsonl.pulled` (handed to the pull reader) and
     * files the shipper delivered but could not delete.
     *
     * Answered from one directory read per process, used only for a pass that
     * finished writing before the read began, so every file it wrote was already on
     * disk; a file shipped since only errs towards "not shipped yet".
     * forget_written_inventory() drops the read at the start of each sweep run.
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
     * One classifier for every scan, using the same rules as the globs here:
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
     * The single place that knows this grammar. Files in the two older forms can
     * still be on disk after an upgrade and must keep parsing:
     *
     *   events-<pid>-<us>-<token>-h<hostid>.jsonl   current
     *   events-<pid>-<us>-<token>.jsonl             older: no host component
     *   events-<pid>-<us>.jsonl                     older: tokenless
     *
     * The host segment has a literal `h` prefix because `<token>` (`dechex()` of
     * the process start time) can itself be eight hex characters; `h` never occurs
     * in a hex token. Accepts the `.closed`, `.closed.parked` and `.pulled` suffixes.
     *
     * @param string $path Full path or bare basename.
     * @return array|null null when the name is not one of ours. Keys:
     *                    pid (int), created_us (int), token (?string),
     *                    hostid (?string — null on an older name form, meaning
     *                    "written by an unknown host", not "written here").
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
     * Derived at runtime, not stored in plugin config: config lives in the site
     * database, which every node in a cluster shares, so a stored value would be
     * identical on all of them.
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
            // so use a fixed value.
            $name = 'unknown-host';
        }
        self::$hostid = substr(hash('sha256', $name), 0, self::HOSTID_LEN);
        return self::$hostid;
    }

    /**
     * Whether a buffer file was written by this host.
     *
     * A name without a host component answers false. That is the safe direction:
     * the file may come from another node, and the only cost of being wrong is that
     * it waits for the idle sweep instead of being reclaimed when its PID is gone.
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
     * Whether the buffer directory is under its configured byte cap.
     *
     * Sizing the directory costs one stat per file, so a web request decides on the
     * size the shipper publishes every run (publish_capacity()). While that is fresh
     * the buffer can grow past the cap by the inflow (a soft cap); once it is stale,
     * one request at a time measures (measure_exclusive()), so the cap stays enforced
     * when cron has stopped.
     *
     * A CLI process measures exactly, unless the fresh measurement projected at the
     * observed inflow, plus its own bytes since (own_bytes_since()), plus one more
     * file is still below the cap. Only an exact measurement refuses. The long CLI
     * writers (backfill, sweep) also stop at half the cap on their own backpressure.
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
     * is the soft-cap overshoot (see have_capacity()). A stale measurement is left to
     * measure_exclusive(): one request at a time, through the lock or a claim file,
     * and not within WEB_MEASURE_MIN_SEC of the last one.
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
     * judged by safe_stat() — a plain, single-linked file — so a link or a hard link
     * planted under a buffer name adds nothing. The closed count covers every plain
     * `.closed` file, because the shipper ships those whatever their prefix.
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
        // Stamped when the walk ends: on slow storage a walk can take longer than a
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
        // Holding it, make sure it is still the lock: the file now at the path.
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
     * Keep these names stable: on a site that already has these files, the lock and
     * pointers are reused as they are, the temporaries are reclaimed by
     * residue_patterns(), and a measurement in another format is replaced, not
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
     * load-gate flag (see shipper::run()). A version-1 file is treated as no
     * measurement at all: it has none of those fields, and a missing
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
        // Optional: a measurement without it reads as 0 (no parked file known)
        // rather than as unusable.
        $parkedat = $state['parkedat'] ?? 0;
        $out['parkedat'] = is_int($parkedat) && $parkedat > 0 ? $parkedat : 0;
        $out['truncated'] = ($state['truncated'] ?? 0) === 1 ? 1 : 0;
        // Optional too: a measurement without a publish time reads as 0, and one in
        // the future is not one this plugin wrote.
        $pubat = $state['pubat'] ?? 0;
        $out['pubat'] = is_int($pubat) && $pubat > 0 && $pubat <= time() + shipper::SHIP_IDLE_SEC ? $pubat : 0;
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
     * Age runs from when the measurement was last written, not from `at`. The shipper's
     * end-of-run measurement describes the directory at the start of its run (`at` is
     * the run start, which is right for the projection), so judged on `at` a run longer
     * than the limit would publish a measurement that is stale as soon as it is
     * written.
     *
     * @param array $state From read_capacity().
     * @return bool
     */
    private static function capacity_stale(array $state): bool {
        $limit = max(shipper::SHIP_IDLE_SEC, 2 * $state['interval']);
        return (time() - max($state['at'], (int)($state['pubat'] ?? 0))) > $limit;
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
     * The projection in a published measurement is the shipper's view of the site's
     * inflow between runs. A long CLI writer (the historical backfill, the
     * verification sweep) can add far more on its own, so its own output is counted
     * too: every byte appended since $ownsince, which is the process start or the
     * last exact measurement this process saw (note_measured()). That bounds the
     * bytes since $at when $at is at or after $ownsince; an older $at is reported as
     * unknown and the caller measures.
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
     * The inflow rate is derived only from the shipper's own measurements, from what
     * the previous run left to what this run finds; other processes publish too, and
     * their measurements would not reflect growth between runs. The run interval is
     * the latest gap between run starts, clamped to RESIDUE_STRAY_SEC, so a cron
     * outage cannot stretch how long a measurement stays usable.
     *
     * @param string $dir Buffer directory.
     * @param array $fields 'bytes', and any of the counts measure_dir() returns,
     *        'prev', 'bypass', 'parkedat' and 'truncated'. A field left out keeps its
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
        // When it was written, apart from `at`, the time the counts describe: see
        // capacity_stale(). Only here, where the counts are measured: publish_drain()
        // keeps the publish time it read, so a pull does not make an old measurement fresh.
        $state['pubat'] = time();
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
        $out['pubat'] = max(0, (int)($state['pubat'] ?? 0));
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
     * Checked before opening and again after (the handle must be the file the path
     * names), so a link or special file swapped in between is closed unused. The
     * open is non-blocking (fopen's `n`), so a FIFO cannot hold the request. The
     * path check after opening matters only for the lock, which every process must
     * take on the same file; readers skip it ($atpath false).
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
     * It must never replace one: requests that each put their own file at the name
     * (as a rename does) would lock different files and measure at once. So the lock
     * is written as a temporary (write_temp()) and hard-linked to its name: link()
     * fails when anything is already there and never follows a link; the loser opens
     * the winner's lock. Until the temporary is removed the lock has two links, so it
     * is opened with up to LOCK_MAX_LINKS; a creator killed in between leaves a usable
     * lock whose temporary the shipper reclaims as residue. Without hard links a
     * rename is the fallback. A process that cannot open the lock measures through a
     * claim file instead (measure_claimed()). Public for the shipper.
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
     * rmdir() that follows declines, which is the rule buffer_purge::purge_all() applies to every
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
     * A pointer is kept while its worker may still be alive. A worker on this host
     * is judged by its process; one on another node cannot be, so its pointer goes
     * once it has not been rewritten for RESIDUE_STRAY_SEC (a live worker then simply
     * writes a new one). Anything at a pointer name that is not a plain file goes too
     * (by the link, never its target).
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
     * directory that can hold tens of thousands. Instead of holding and sorting every
     * name, this holds at most 2 x $n, pruning back to $n whenever it doubles, so
     * memory stays bounded by what the caller will use. Oldest means modification time, then
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
     * refused. The status page and get_status use this, so they do not show a healthy
     * state while capture is being refused.
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
     * here" rather than "is there anything left we would ship". That decides whether
     * a previous buffer directory can be forgotten: forgetting one that still holds
     * an entry would also drop it from the uninstall purge and privacy erasure.
     * Rewrite temporaries are counted for the same reason, with no age guard: this
     * asks whether anything is present, not whether it may be deleted yet. Counted,
     * not measured in bytes, because a zero-length file is still a file.
     *
     * Bookkeeping dot-files hold no record and are not counted. The three lists
     * overlap (a residue-named symlink is in both residue_files() and
     * unsafe_files()), so they are keyed into a set by basename rather than summed.
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
        // Its name is keyed on this pid, this process's stable token and this
        // host, so a worker re-uses its own file across requests, a recycled
        // PID can never adopt a dead worker's active file, and on a cluster
        // sharing moodledata a worker on another node can never adopt this one's.
        //
        // The name is found through a small per-worker pointer rather than by
        // globbing the buffer directory, whose cost grows with the backlog and
        // this runs on the first append of every request. Only when the pointer is
        // missing (first request of a worker) is the directory read, once.
        $pointer = self::writer_pointer($dir, $pid, $token, $hostid);
        $existing = self::find_own_active($dir, $pointer, $pid, $token, $hostid);
        if (!empty($existing)) {
            $candidate = (string)key($existing);
            $reusestat = current($existing);
            $created = self::created_from_name($candidate);
            $size = (int)$reusestat['size'];
            $tooold = (time() - $created) >= config::rotate_age_sec();
            $toobig = $size >= config::rotate_size_bytes();
            // Idle for WRITER_IDLE_SEC: the shipper may be about to take it (or be taking
            // it now). Close it for shipping and start a new file instead of appending.
            // Both ways, as idle_too_long() and the shipper measure it: an mtime that far in
            // the future (a clock stepped back) makes the file takeable too.
            $idle = abs(time() - (int)$reusestat['mtime']) >= self::WRITER_IDLE_SEC;
            if ($tooold || $toobig || $idle) {
                self::mark_closed($candidate);
            } else {
                $path = $candidate;
                self::$bytes = $size;
                self::$created = $created;
            }
        }

        $h = false;
        if ($path !== null) {
            // Appending to an entry that was already on disk. The lookup judged a
            // name, and this is a write handle: writing through a planted symlink
            // would be an arbitrary file write, and every part of the name can be
            // derived. safe_open() returns a descriptor verified to be the inode
            // find_own_active() just stat'ed. Opened without create ('r+b', then to
            // the end): if the shipper renamed the file since the lookup, the open
            // fails instead of re-creating it, and a new file is made below. Only
            // this process writes a file with this name, so no O_APPEND is needed.
            $h = self::safe_open($path, 'r+b', $reusestat);
            if ($h !== false && @fseek($h, 0, SEEK_END) !== 0) {
                @fclose($h);
                $h = false;
            }
            if ($h === false) {
                $path = null;
                self::$bytes = 0;
                self::$created = 0;
            }
        }
        $reusing = $path !== null;
        // This process's own file re-opened counts as admitted.
        $admitted = $reusing;

        if ($path === null) {
            // Backpressure, and the only enforcement of max_buffer_bytes(): the
            // shipper never deletes an undelivered file to make room, so when nothing
            // drains (storage unconfigured, load gate closed, endpoint failing) this
            // is what bounds the buffer. It refuses a new file rather than deleting
            // captured records. An append to a file this process already holds is
            // never refused (that file is bounded by rotate_size_bytes()), which is
            // why the cap is soft. Decided once per new file, not per append.
            //
            // A file that replaces an admitted one this process held (rotated for
            // idle time just before a write, see replace()) is not a new admission:
            // the record would have landed in the old file. A chain of replacements
            // gets at most one such pass, so the cap still bounds the buffer.
            $replacing = self::$replacing;
            self::$replacing = false;
            $admitted = !$replacing;
            if (!$replacing && self::$capretryat > time()) {
                // Still inside the cooldown from a recent refusal. Returning
                // without re-measuring keeps the directory scan off the per-record
                // path while the buffer stays full.
                return false;
            }
            if (!$replacing && !self::have_capacity($dir)) {
                // Not self::$handle = false: that means "opening failed permanently
                // for this process" and handle() short-circuits on it for the rest of
                // the process. A capacity refusal is transient, so it is re-checked
                // on a cooldown.
                self::$capretryat = time() + self::CAP_RECHECK_SEC;
                return false;
            }
            if (!$replacing) {
                // Only a real measurement ends the cooldown; a replacement measured nothing.
                self::$capretryat = 0;
            }
            $us = self::created_us();
            $path = $dir . '/events-' . $pid . '-' . $us . '-' . $token . '-h' . $hostid . '.jsonl';
            self::$bytes = 0;
            self::$created = intdiv($us, 1000000);
        }

        if (!$reusing) {
            // Creating. 'xb' is O_CREAT|O_EXCL: it fails if anything already
            // occupies the name, symlink included, so there is no check-then-open
            // race and no need for safe_open(). O_APPEND is unnecessary: the name
            // carries this pid, token, host and the creating microsecond, so this
            // descriptor is its only writer.
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
        // A reused file was last written at its mtime, not now: append() measures the
        // pause before its write from here, as the shipper measures idle time.
        self::$lastwrite = $reusing ? (int)$reusestat['mtime'] : time();
        self::$admitted = $admitted;
        return $h;
    }

    /**
     * Path of this worker's active-file pointer, or null when it cannot have one.
     *
     * A dot-file in the buffer directory, keyed like the buffer file itself (pid,
     * process token, host). It holds a buffer file name, or nothing when the worker
     * has no active file; the shipper removes those of gone workers
     * (reclaim_pointer()). Only a worker with a stable process token gets one: a
     * random per-request token would leave an unfindable pointer per request.
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
     * directly (judged by safe_stat(), which also gives its size); a pointer to a
     * file that is gone means this worker has no active file. With no pointer (first
     * request, a pointer the shipper reclaimed, or no stable process token) the
     * worker opens a new file, whose name handle() then records; a file it did have
     * is promoted by the shipper's idle sweep like any other writer-less file.
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
     * Without read_small_file()'s type and identity checks, which would be paid on
     * every request and protect nothing here: the content is used only as a name,
     * only after it matches this worker's own buffer file name exactly
     * (find_own_active()), and that file is then judged through safe_stat() and
     * safe_open(). The open is non-blocking (fopen's `n`), so a FIFO reads empty; an
     * oversized read is ignored. Nothing is written or created through it.
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
        self::$lastwrite = 0;
        self::$admitted = false;
    }

    /**
     * Rename an active `.jsonl` file to `.jsonl.closed`.
     *
     * @param string $path
     */
    private static function mark_closed(string $path): void {
        // Not is_file(), which follows a symlink: renaming a link to `.closed` would
        // give a shippable name to a link pointing anywhere on disk.
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
     * Where /proc is unavailable (non-Linux) it falls back to a random hex token:
     * a worker then opens a new file per request instead of re-using one, but
     * correctness is unaffected.
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
            // This token needs uniqueness among the concurrent writers on one host,
            // not unpredictability (it is a filename component, never a secret), so
            // fall back to values distinct per process: two live processes cannot
            // share a pid at the same nanosecond.
            self::$token = substr(hash('sha256', getmypid() . '|' . hrtime(true)), 0, 12);
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
