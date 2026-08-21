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
            mtrace('local_intellistream: ALERT buffer write failed/short ('
                . var_export($written, true) . ' of ' . strlen($line)
                . ' bytes) — record dropped (disk full?).');
            return false;
        }
        self::$bytes += $written;

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
     * Serialize a record payload and append it as one JSONL line.
     *
     * Shared by every writer (event observer, page-dwell endpoint, entity
     * exporter) so the on-disk envelope stays consistent. Oversized payloads
     * are reported back to the caller (which decides whether to shrink and
     * retry) rather than silently dropped.
     *
     * @param array $payload Decoded record. Must be JSON-encodable.
     * @return bool True if appended, false if it could not be serialized or
     *              exceeded MAX_EVENT_BYTES.
     */
    public static function append_record(array $payload): bool {
        // Guard here too (see append()), so an unpaired site is rejected before
        // the payload is even serialised.
        if (config::site_id() === '') {
            return false;
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($payload, $flags);
        if ($json === false) {
            return false;
        }
        if (strlen($json) > self::MAX_EVENT_BYTES) {
            return false;
        }
        return self::append($json . "\n");
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
     * prefix.
     *
     * These are the files a caller may READ, SHIP or REWRITE. They are not the
     * only things this plugin writes here — see {@see residue_patterns} for the
     * rewrite temporaries — so do not use this list to answer "is anything of
     * ours left in this directory". That distinction is load-bearing; the
     * docblock used to claim nothing else was ever written here, and that claim
     * is what let a leftover temporary sit outside the purge and the erasure
     * paths entirely.
     *
     * @return string[]
     */
    private static function own_file_patterns(): array {
        return ['events-*.jsonl', 'events-*.jsonl.closed', 'events-*.jsonl.pulled'];
    }

    /**
     * Glob patterns covering the rewrite temporaries this plugin writes.
     *
     * Two call sites write a sibling temp and rename() it over the original:
     * the privacy rewrite in {@see delete_user_records} (`.privacy-<pid>-<rand>.tmp`)
     * and the pull-export rewrite in `pull_export::rewrite_with()`
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
     * @return string[]
     */
    private static function residue_patterns(): array {
        return ['events-*.jsonl*.privacy-*.tmp', 'events-*.jsonl*.rewrite-*'];
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
                if (!self::is_safe_file($path) && !$islink) {
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
        clearstatcache(true, $path);
        $st = @lstat($path);
        if ($st === false) {
            return false;
        }
        if (($st['mode'] & self::STAT_TYPE_MASK) !== self::STAT_TYPE_REGULAR) {
            return false;
        }
        return (int)$st['nlink'] === 1;
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
     * @return resource|false
     */
    public static function safe_open(string $path, string $mode = 'rb') {
        clearstatcache(true, $path);
        $before = @lstat($path);
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
     * Detection only: reads nothing, renames nothing, removes nothing. The
     * consumers of this are the status page and the cron trace, because an entry
     * of this kind is either operator error or someone probing, and silently
     * skipping it forever is how it stays invisible.
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
     *     completions, dwell-adjacent tables). This is deliberately a heuristic:
     *     the authoritative erasure for the underlying Moodle tables is core's own,
     *     and these rows are a transient copy en route to the warehouse.
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
            if (is_array($data) && isset($data['userid'])) {
                $take($data['userid']);
            }
        } else if ($type === datatypes\exceptions_datatype::RECORD_TYPE) {
            $take($rec['userid'] ?? 0);
        }

        return array_keys($ids);
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
     * Accepts the `.closed` and `.pulled` suffixes so callers can parse a file
     * at any point in its lifecycle.
     *
     * @param string $path Full path or bare basename.
     * @return array|null null when the name is not one of ours. Keys:
     *                    pid (int), created_us (int), token (?string),
     *                    hostid (?string — null on a legacy name, meaning
     *                    "written by an unknown host", NOT "written here").
     */
    public static function parse_name(string $path): ?array {
        $re = '/^events-(\d+)-(\d+)(?:-([0-9a-z]+))?(?:-h([0-9a-f]+))?'
            . '\.jsonl(?:\.closed|\.pulled)?$/';
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

            @chmod($tmp, 0640);
            if (@rename($tmp, $path)) {
                $result['files']++;
                $result['removed'] += $removed;
                $result['kept'] += $kept;
            } else {
                @unlink($tmp);
                $result['skipped']++;
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
     * Measures every file this plugin owns — active, closed and drained — the same
     * accounting shipper::enforce_disk_cap() uses, so the two agree about what
     * "full" means.
     *
     * @param string $dir Buffer directory.
     * @return bool
     */
    private static function have_capacity(string $dir): bool {
        $cap = config::max_buffer_bytes();
        $total = self::occupied_bytes($dir);
        if ($total < $cap) {
            return true;
        }
        self::note_cap_refusal($total, $cap);
        return false;
    }

    /**
     * Whether the buffer is at or over its byte cap right now.
     *
     * Lets a caller tell a TRANSIENT refusal from a PERMANENT one. append_record()
     * returns a bare false for both "the buffer is full" (which drains, so
     * retrying works) and "this single record is over MAX_EVENT_BYTES or will not
     * encode" (which never works, however many times it is retried). A resumable
     * caller has to treat those differently: pausing on the first is correct, and
     * pausing on the second wedges it forever on one row.
     *
     * Measured through occupied_bytes(), so it agrees with have_capacity() by
     * construction.
     *
     * @return bool
     */
    public static function at_capacity(): bool {
        $dir = config::buffer_dir();
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        return self::occupied_bytes($dir) >= config::max_buffer_bytes();
    }

    /**
     * Bytes this plugin occupies in the buffer directory — active, closed AND
     * drained (`.pulled`) files.
     *
     * Public and extracted from have_capacity() so every caller that asks "how
     * full is the buffer" gets the SAME answer. exporter's backfill backpressure
     * used to measure only `*.jsonl.closed`, which let the two disagree: a site
     * carrying a large `.pulled` backlog (never evicted while object storage is
     * unconfigured, and invisible to a closed-only count) could sit in a window
     * where backpressure reported headroom while have_capacity() refused every
     * append. That window is what made a discarded append-refusal reachable.
     *
     * @param string $dir Buffer directory.
     * @return int Total bytes.
     */
    public static function occupied_bytes(string $dir): int {
        $total = 0;
        foreach (self::safe_files($dir, self::own_file_patterns()) as $path) {
            $total += (int)@filesize($path);
        }
        return $total;
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
        mtrace('local_intellistream: ALERT buffer is at its cap ('
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
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            // Do not fail silently: open_basedir, permission, or disk-full. The
            // '@' hides the warning from page output, but error_get_last() still
            // carries it — surface it so the cause is diagnosable.
            $err = error_get_last();
            debugging('local_intellistream: cannot create buffer dir "' . $dir . '"'
                . ($err ? ' — ' . $err['message'] : '')
                . ' (check open_basedir and web-user write permission)', DEBUG_NORMAL);
            self::$handle = false;
            return false;
        }

        $pid = getmypid();
        $token = self::process_token();
        $hostid = self::host_id();
        $path = null;

        // Reuse this process's existing active file if it is still fresh.
        // The glob is keyed on this pid, this process's stable token AND this
        // host, so a worker re-uses its own file across requests, a recycled
        // PID can never adopt a dead worker's active file, and — on a cluster
        // sharing moodledata — a worker on another node can never adopt this
        // one's, which would give a single file two appending writers.
        $existing = self::safe_files($dir, ['events-' . $pid . '-*-' . $token . '-h' . $hostid . '.jsonl']);
        if (!empty($existing)) {
            $candidate = $existing[0];
            $created = self::created_from_name($candidate);
            $size = (int)@filesize($candidate);
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
            // Backpressure. The shipper's enforce_disk_cap() is the only other
            // consumer of max_buffer_bytes(), and it sits behind that method's
            // early returns: when object storage is unconfigured, or the host load
            // gate is closed, nothing ships and nothing enforced the cap either,
            // while capture kept opening new files. The buffer grew without any
            // bound at all.
            //
            // Refusing a NEW file is the bound, and it is the right kind: it stops
            // accepting more rather than deleting what is already captured but not
            // yet shipped. An append to a file this process already holds is never
            // refused — that file is bounded by rotate_size_bytes() and cutting a
            // writer off mid-file would lose the records in flight.
            //
            // Measured once per new file, not per append, so the cost lands at a
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
            $h = self::safe_open($path, 'ab');
        } else {
            // Creating. 'xb' is O_CREAT|O_EXCL: it makes the file or it fails, and
            // it fails if ANYTHING already occupies the name, symlink included.
            // There is no check here, so there is no race to lose — which is why
            // this branch does not go through safe_open(). O_APPEND is unnecessary:
            // the name carries this pid, this process's token, this host and the
            // creating microsecond, so this descriptor is the only writer that will
            // ever exist for it.
            $h = @fopen($path, 'xb');
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
