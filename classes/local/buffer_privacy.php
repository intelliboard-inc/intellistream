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
 * Privacy API access to the records staged in the buffer.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\local;

use local_intellistream\buffer;
use local_intellistream\change_ledger;
use local_intellistream\config;
use local_intellistream\shipper;

/**
 * Finds, exports and erases one user's records in the on-disk buffer.
 *
 * Used by the privacy provider. A file that another process is still writing is
 * left alone; its records ship within minutes.
 */
class buffer_privacy {
    /**
     * Every buffer file currently on disk, oldest name first.
     *
     * Spans every directory config::tracked_buffer_dirs() names, not just the one
     * in use: the callers are the privacy provider's export, erasure and
     * user-listing paths, and a record stays personal data after the buffer has
     * been pointed somewhere else. tracked_buffer_dirs() rather than buffer_dirs(),
     * because a directory that fails buffer_dir_problem() can still hold our records
     * and erasure must reach them; safe_files() limits this to our own file names.
     *
     * @return string[] Absolute paths.
     */
    private static function own_files(): array {
        $paths = [];
        foreach (config::tracked_buffer_dirs() as $dir) {
            foreach (buffer::safe_files($dir, buffer::own_file_patterns()) as $path) {
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
        // Recovery window rows (window_tool): the related user (graded, messaged, viewed) too.
        'window_logstore' => ['relateduserid'],
    ];

    /**
     * The Moodle user ids a buffered record is about.
     *
     * The single definition of "belongs to a data subject" for the buffer, used by
     * every privacy path; a new record type must be added here too.
     *
     *   - `event`: `userid` / `relateduserid` inside the core event payload.
     *   - `page_dwell` / `media_segment`: `dwell.userid`.
     *   - `entity_snapshot`: one Moodle table row. Its `userid` column when present,
     *     plus the columns ENTITY_SUBJECT_FIELDS names. A column naming who acted on
     *     the row (`usermodified`, `grader`) is not a subject. This is a heuristic:
     *     core's own erasure is authoritative for the Moodle tables, and these rows
     *     are a transient copy on their way to the reporting service.
     *   - `exception`: a top-level `userid`.
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
        } else if ($type === \local_intellistream\datatypes\exceptions_datatype::RECORD_TYPE) {
            $take($rec['userid'] ?? 0);
        }

        return array_keys($ids);
    }

    /**
     * Buffered records belonging to one user, for a subject-access request.
     *
     * Read-only. Files normally ship and are deleted soon after they close, but a
     * shipping backlog can leave personal data here.
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
            $fh = buffer::safe_open($path);
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
     * Whether the buffer holds any record for one user — an existence test.
     *
     * Separate from user_records() and stops at the first match, because the
     * privacy provider only needs a yes/no to decide whether to add the system
     * context. With a backlog the buffer can hold up to config::max_buffer_bytes(),
     * so collecting every record just to answer yes/no would build a very large
     * array.
     *
     * @param int $userid
     * @return bool
     */
    public static function has_user_records(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        foreach (self::own_files() as $path) {
            $fh = buffer::safe_open($path);
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
     * in this context" also reaches a user whose only personal data is a staged
     * record not yet shipped.
     *
     * @return int[]
     */
    public static function user_ids(): array {
        $ids = [];
        foreach (self::own_files() as $path) {
            $fh = buffer::safe_open($path);
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
     * Which files are eligible is the safety-critical part. `.closed` and `.pulled`
     * files have no writer and are always eligible. An active `.jsonl` file is
     * rewritten only when the PID in its name is not running and it has not been
     * written to for shipper::SHIP_IDLE_SEC (a PID can be invisible while its writer
     * lives: another PID namespace, /proc hidepid). Rewriting under a live writer
     * would corrupt the file, so such files are skipped and counted; they ship
     * soon and their content leaves with them. An active file written by another
     * node is always skipped, because the PID test only works on the local process
     * table; its own node ships it.
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

        // Drop stray rewrite temporaries first: one can hold a copy of the records
        // being erased and is not in own_files(). Deleting is correct because a
        // temporary is never authoritative (the original is intact and the loop
        // below erases from it). Age-guarded so a rewrite still in flight in another
        // process keeps its temp.
        foreach (config::tracked_buffer_dirs() as $dir) {
            buffer::purge_residue($dir, buffer::RESIDUE_STRAY_SEC);
        }

        foreach (self::own_files() as $path) {
            // Active file with a live writer, or one whose writer is on another
            // host and cannot be checked: leave it alone.
            if (substr($path, -6) === '.jsonl') {
                if (!buffer::is_own_host($path)) {
                    $result['skipped']++;
                    continue;
                }
                $parsed = buffer::parse_name($path);
                $pid = $parsed !== null ? $parsed['pid'] : 0;
                if ($pid > 0 && shipper::pid_alive($pid)) {
                    $result['skipped']++;
                    continue;
                }
                // A PID can be invisible while its writer is alive (another PID
                // namespace, /proc hidepid): a file written to within
                // shipper::SHIP_IDLE_SEC may still have its writer, so leave it too.
                $st = @lstat($path);
                $idle = $st !== false ? time() - (int)$st['mtime'] : 0;
                if ($st === false || ($idle < shipper::SHIP_IDLE_SEC && $idle > -shipper::SHIP_IDLE_SEC)) {
                    $result['skipped']++;
                    continue;
                }
            }

            $fh = buffer::safe_open($path);
            if (!$fh) {
                $result['skipped']++;
                continue;
            }

            // Unpredictable name plus 'xb' (O_CREAT|O_EXCL): O_EXCL refuses anything
            // that already exists, a planted symlink included, and the random part
            // keeps a temp left by a crashed run from blocking the name.
            $tmp = $path . '.privacy-' . getmypid() . '-' . buffer::temp_suffix() . '.tmp';
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
                // left to ship from it, so remove it rather than leave an empty file
                // (a parked one would otherwise keep the status page red for nothing).
                @unlink($tmp);
                if (buffer::remove_file($path)) {
                    $result['files']++;
                    $result['removed'] += $removed;
                } else {
                    $result['skipped']++;
                }
                continue;
            }
            @chmod($tmp, buffer::file_permissions());
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
}
