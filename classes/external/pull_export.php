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
 * Pull-style export web-service for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\external;

defined('MOODLE_INTERNAL') || die();

use local_intellistream\buffer;
use local_intellistream\config;
use local_intellistream\services\encryption_service;

// Moodle 4.2+ moved the external classes into the \core_external namespace and
// kept top-level shims for the old class names. Resolve both at load time so
// this plugin works on Moodle 4.1 (the plugin's declared minimum) through
// current.
if (!class_exists('\\local_intellistream\\external\\external_api_compat')) {
    if (class_exists('\\core_external\\external_api')) {
        class_alias('\\core_external\\external_api', '\\local_intellistream\\external\\external_api_compat');
        class_alias(
            '\\core_external\\external_function_parameters',
            '\\local_intellistream\\external\\external_function_parameters_compat'
        );
        class_alias('\\core_external\\external_value', '\\local_intellistream\\external\\external_value_compat');
        class_alias(
            '\\core_external\\external_single_structure',
            '\\local_intellistream\\external\\external_single_structure_compat'
        );
        class_alias(
            '\\core_external\\external_multiple_structure',
            '\\local_intellistream\\external\\external_multiple_structure_compat'
        );
    } else {
        require_once($GLOBALS['CFG']->libdir . '/externallib.php');
        class_alias('\\external_api', '\\local_intellistream\\external\\external_api_compat');
        class_alias(
            '\\external_function_parameters',
            '\\local_intellistream\\external\\external_function_parameters_compat'
        );
        class_alias('\\external_value', '\\local_intellistream\\external\\external_value_compat');
        class_alias(
            '\\external_single_structure',
            '\\local_intellistream\\external\\external_single_structure_compat'
        );
        class_alias(
            '\\external_multiple_structure',
            '\\local_intellistream\\external\\external_multiple_structure_compat'
        );
    }
}

/**
 * `local_intellistream_pull_export` external function.
 *
 * Returns a batch of canonical IntelliStream records straight from the local
 * buffer directory, so a customer who cannot or will not accept push-to-S3
 * can poll for the same payload over Moodle's standard REST web-service
 * surface.
 *
 * Lifecycle:
 *   1. buffer.php writes per-process `events-*.jsonl` files.
 *   2. buffer/shipper rotates them to `*.jsonl.closed`.
 *   3. EITHER the shipper PUTs each closed file to S3 and deletes it,
 *      OR this WS function reads closed files and returns their records.
 *      A closed file is removed only once every record in it has actually
 *      been returned; if some were filtered out of the response, the file is
 *      rewritten with just those. Removing drained files (rather than parking
 *      them under another name) keeps buffer disk use bounded by the same cap
 *      that governs the push path, and keeps personal data from accumulating
 *      on disk after it has been handed to the puller.
 *   4. Both paths can run side-by-side: closed files race for whoever
 *      reaches them first.
 */
class pull_export extends external_api_compat {
    /** Default cap on records per call. */
    const DEFAULT_LIMIT = 1000;

    /** Hard ceiling — clamp anything larger. */
    const MAX_LIMIT = 10000;

    /** Minimum seconds between two malformed-line markers. */
    const MALFORMED_NOTE_SEC = 900;

    /**
     * Allowed record types.
     *
     * This must list EVERY record type the plugin writes to the buffer, or the
     * missing ones are undeliverable on a pull-only site: the drain below walks
     * past a line it cannot emit and keeps it as a survivor, so the records are
     * never handed to the puller and their file is never removed.
     *
     * `exception` (exceptions_datatype::RECORD_TYPE, from the exceptions
     * observer) and `media_segment` (dwell.php, when trackmedia is on) were
     * absent, so a customer who will not accept push-to-S3 received neither —
     * while the plugin captured, stored and retained both.
     */
    const ALLOWED_TYPES = ['event', 'entity_snapshot', 'page_dwell', 'exception', 'media_segment'];

    /**
     * Parameter signature.
     *
     * @return \external_function_parameters|\core_external\external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters_compat([
            'from_ingested_at' => new external_value_compat(
                PARAM_RAW,
                'Optional RFC 3339 / ISO 8601 lower bound on a record\'s captured_at '
                . '(inclusive). Records older than this are skipped. Empty string = no bound.',
                VALUE_DEFAULT,
                ''
            ),
            'limit' => new external_value_compat(
                PARAM_INT,
                'Maximum records to return (clamped to ' . self::MAX_LIMIT . ').',
                VALUE_DEFAULT,
                self::DEFAULT_LIMIT
            ),
            'record_types' => new external_multiple_structure_compat(
                new external_value_compat(PARAM_ALPHANUMEXT, 'Record type filter.'),
                'Optional record-type filter; default is all of: '
                . implode(', ', self::ALLOWED_TYPES),
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Return-shape signature.
     *
     * @return \external_multiple_structure|\core_external\external_multiple_structure
     */
    public static function execute_returns() {
        return new external_multiple_structure_compat(
            new external_single_structure_compat([
                'id'          => new external_value_compat(PARAM_RAW, 'Record UUID.'),
                'captured_at' => new external_value_compat(PARAM_RAW, 'RFC 3339 UTC capture timestamp.'),
                'record_type' => new external_value_compat(PARAM_ALPHANUMEXT, 'One of: '
                    . implode(', ', self::ALLOWED_TYPES) . '.'),
                'entity'      => new external_value_compat(
                    PARAM_RAW,
                    'For entity_snapshot, the Moodle entity name (for example user or course). '
                    . 'For events, the fully-qualified event class. For page_dwell, empty.'
                ),
                'data'        => new external_value_compat(
                    PARAM_RAW,
                    'JSON-encoded record body (event_data / entity_data / dwell payload).'
                ),
            ])
        );
    }

    /**
     * Read closed buffer files, return up to `limit` matching records, delete
     * fully-drained files (their records have been handed to the caller) and
     * shrink partially-drained ones to just the records that were not handed
     * over, so the next pull genuinely resumes after them rather than rescanning
     * from the start. A file is never left unchanged after records were taken
     * from it: that is what a file holding more deliverable records than the
     * caller's limit needs in order to drain at all.
     *
     * @param string $fromingestedat
     * @param int    $limit
     * @param array  $recordtypes
     * @return array
     */
    public static function execute($fromingestedat = '', $limit = self::DEFAULT_LIMIT, $recordtypes = []) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'from_ingested_at' => $fromingestedat,
            'limit'            => $limit,
            'record_types'     => $recordtypes,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/intellistream:pullexport', $context);
        self::note_pull_seen();

        $fromiso = (string)$params['from_ingested_at'];
        $limit   = max(1, min(self::MAX_LIMIT, (int)$params['limit']));
        $types   = $params['record_types'] ?: self::ALLOWED_TYPES;
        // Filter to the allowed set: anything else is silently dropped.
        $types = array_values(array_intersect($types, self::ALLOWED_TYPES));
        if (!$types) {
            $types = self::ALLOWED_TYPES;
        }
        $typeset = array_flip($types);

        // Every directory that may hold our records, not just the one in use.
        // `bufferdir` is admin-editable, so records can be sitting in a directory
        // the buffer used to be pointed at — and on a PULL-ONLY site this method is
        // the only thing that ever delivers them. shipper::run() drains previous
        // directories too, but its ship loop is below the "object storage not
        // configured" early return, which is exactly where a pull-only site stops.
        // Reading only the current directory therefore left those records with no
        // delivery path at all, while get_status kept reporting them as present.
        //
        // They were not at risk of deletion — nothing in this plugin deletes an
        // undelivered record — so this is undelivered data, not lost data. Records
        // still owed, with nothing coming to collect them.
        // Retry the removal of anything this path previously failed to remove, and
        // clear the fault when it is gone. It has to happen HERE, above the
        // "nothing to pull" return below, because the state that needs clearing is
        // precisely the one where the stuck file has just been freed and there may
        // be nothing left to hand over — put it after the drain loop and the clear
        // never runs on the quiet site it exists for.
        //
        // Nothing else can do this job for a PULL-ONLY site: with no object storage
        // configured shipper::run() stops at its `unconfigured` gate, which sits
        // above both the reap and the clear, so a fault recorded here was permanent
        // by construction — a red status page and get_status reporting stuck files
        // for ever, long after the administrator had fixed the permission. Cheap on
        // a healthy site: the reap only stats names still on record (none), and
        // clear_undeletable() writes on change only.
        \local_intellistream\shipper::reap_undeletable_fault();

        // Oldest first, and never more files in hand than this call can hand over
        // records from. Each file that yields anything yields at least one record,
        // so a call never needs more than its limit's worth of files — unless
        // whole files are filtered out (record_types, from_ingested_at), in which
        // case the next window of files after the last one looked at is read,
        // twice as large each time, so even a pull that has to walk past every file
        // reads the directory about log2(N / limit) times rather than N / limit.
        // The previous code globbed every closed file, then sorted them all with
        // two filemtime() calls per comparison: about 2·N·log N stats on every
        // pull, inside a web request, however few records were asked for.
        $started = time();
        $window = $limit;
        [$files, $more] = self::oldest_closed($window, null);
        if (!$files) {
            return [];
        }

        $encsvc = new encryption_service();
        $out = [];
        $remaining = $limit;
        $malformedtotal = 0;
        // Basenames of drained files this pull could not remove. Reported through
        // the shipper's state because it is the same fault and the same remedy, and
        // because the shipper has to stop shipping while any of them are present.
        $undeletable = [];
        $current = config::buffer_dir();
        $freed = 0;
        $removed = 0;

        while ($files) {
            foreach ($files as $entry) {
                if ($remaining <= 0) {
                    break 2;
                }
                [, $path, $size] = $entry;
                $changed = false;
                self::drain_file($path, $encsvc, $typeset, $fromiso, $remaining, $out, $malformedtotal, $undeletable, $changed);
                // Only what this call itself removed or shrank: a file another process
                // took meanwhile is that process's to account for.
                if ($changed && dirname($path) === $current && strpos(basename($path), 'events-') === 0) {
                    $after = buffer::safe_stat($path);
                    if ($after !== null) {
                        $freed += max(0, $size - (int)$after['size']);
                    } else if (@lstat($path) === false) {
                        $freed += $size;
                        $removed++;
                    }
                }
            }
            if ($remaining <= 0 || !$more) {
                break;
            }
            $window *= 2;
            [$files, $more] = self::oldest_closed($window, end($files));
        }

        // The capture path decides on the size the shipper last published. Take off
        // what this drain removed, so a buffer that was at its cap stops refusing
        // capture now rather than at the next shipper run.
        buffer::publish_drain($current, $freed, $removed, $started);

        if ($malformedtotal > 0) {
            self::note_malformed($malformedtotal);
        }
        if ($out !== []) {
            self::note_pull();
        }
        // A pull-only new install has no destination to wait for: its puller working is the
        // signal that backfilled records will be collected, so start the backfill now.
        try {
            \local_intellistream\backfill::ensure_queued(true);
        } catch (\Throwable $e) {
            debugging(
                'local_intellistream: could not queue the historical backfill: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
        if ($undeletable !== []) {
            \local_intellistream\shipper::record_undeletable($undeletable);
        }

        return $out;
    }

    /**
     * The oldest closed buffer files after a given one, across every directory the
     * pull serves, in one streaming read of each directory.
     *
     * @param int $limit How many to return at most.
     * @param array|null $after [mtime, path, size] of the last file already looked
     *        at; only files that sort after it are returned. Null for the start.
     * @return array{0: array, 1: bool} [mtime, path, size] entries, oldest first, and
     *         whether more files sort after the last one returned.
     */
    private static function oldest_closed(int $limit, ?array $after): array {
        $window = [];
        $seen = 0;
        foreach (config::buffer_dirs() as $dir) {
            buffer::each_entry($dir, function (string $name, string $path) use (&$window, &$seen, $limit, $after) {
                if (buffer::entry_kind($name) !== 'closed') {
                    return;
                }
                $st = buffer::safe_stat($path);
                if ($st === null) {
                    return;
                }
                $key = [(int)$st['mtime'], $path, (int)$st['size']];
                if ($after !== null && (($key[0] <=> $after[0]) ?: strcmp($key[1], $after[1])) <= 0) {
                    return;
                }
                $seen++;
                buffer::keep_oldest($window, $key, $limit);
            });
        }
        if (!$window) {
            return [[], false];
        }
        $window = buffer::oldest_first($window, $limit);
        return [$window, $seen > count($window)];
    }

    /**
     * Hand over the records of one closed buffer file, up to the call's limit, and
     * commit: remove the file when everything in it was delivered, or shrink it to
     * what was not.
     *
     * Streams the file a line at a time and stops at the limit: reading a whole
     * file first meant a 64 MB file became a 64 MB string plus an array of every
     * line — enough to exhaust a web request's memory with limit=1, on every retry,
     * on a pull-only site where this is the only drain. Lines that are kept
     * (filtered out by type or time, or not reached before the limit) are written
     * straight into the replacement file, never held — and that file is only
     * started once a rewrite is certain (the first line handed over or dropped, or
     * the limit reached), so a pull that filters out an entire file writes nothing.
     *
     * The three commit outcomes are unchanged:
     *  - stopped at the limit with lines left: the file is replaced by the kept lines
     *    plus everything after the stop point, so the next pull resumes after the
     *    records handed over (removed if nothing is left);
     *  - walked to the end with nothing kept: removed, the normal drain;
     *  - walked to the end with lines kept: replaced by them if anything was handed
     *    over or dropped as malformed, otherwise left untouched.
     * A replacement that cannot be written leaves the original in place, so its
     * records are served again rather than lost (the caller dedupes on `id`).
     *
     * Buffer files are NOT encrypted on disk — append() always writes
     * plaintext, and no release ever wrote them any other way: the only
     * encrypt() call site the plugin has ever had is in shipper::run(),
     * on the in-memory batch after the file has been read (see
     * classes/services/encryption_service.php).
     *
     * Whether a file is decrypted is decided by its wire-format prefix —
     * the same test decrypt() dispatches on — and never by is_enabled().
     * Gating it on the setting would make reading correct only while a
     * mutable admin setting happened to agree with what is on disk: with
     * the setting off, a prefixed blob would reach json_decode(), fail on
     * every line, and be dropped as malformed — the file then unlinked
     * with its records never delivered. Nothing can produce such a file
     * today, which is exactly why the coupling is not worth keeping. A
     * plaintext file (every file in practice) is streamed; an encrypted
     * blob can only be decrypted whole.
     *
     * On a decrypt failure (auth-tag mismatch, missing or rotated key)
     * skip the file rather than fail the whole call. That leaves it
     * `.closed` for a later pull instead of dropping it, which is the
     * safe direction for data — undecryptable is not undeliverable, and
     * a restored key recovers it. The cost is that such a file holds
     * disk until then; have_capacity()/note_cap_refusal() surface that.
     *
     * @param string $path Closed buffer file.
     * @param encryption_service $encsvc
     * @param array $typeset Allowed record types (as keys).
     * @param string $fromiso Lower bound on captured_at, or ''.
     * @param int $remaining Records still wanted by this call; decremented.
     * @param array $out Records handed over; appended to.
     * @param int $malformedtotal Unparseable lines dropped; incremented.
     * @param string[] $undeletable Drained files that could not be removed; appended to.
     * @param bool|null $changed Set to whether this call replaced or removed the file.
     * @return void
     */
    private static function drain_file(
        string $path,
        encryption_service $encsvc,
        array $typeset,
        string $fromiso,
        int &$remaining,
        array &$out,
        int &$malformedtotal,
        array &$undeletable,
        ?bool &$changed = null
    ): void {
        $changed = false;
        $fh = \local_intellistream\buffer::safe_open($path, 'rb');
        if ($fh === false) {
            return;
        }
        $lines = null;          // Set only for a whole-file encrypted blob.
        $li = 0;
        $tmp = $path . '.rewrite-' . getmypid() . '-' . \local_intellistream\buffer::temp_suffix();
        $tfh = null;
        $kept = 0;
        $writefailed = false;
        $emittedhere = 0;
        $malformed = 0;
        $survivors = 0;
        $stoppedearly = false;
        // Until a rewrite is certain, kept lines are only counted: every line read so
        // far was kept, so they are exactly the bytes before the current line.
        $active = false;
        $deferred = 0;
        $linestart = 0;
        try {
            $head = (string)fread($fh, 64);
            if (
                strncmp($head, encryption_service::PREFIX_SODIUM, strlen(encryption_service::PREFIX_SODIUM)) === 0
                || strncmp($head, encryption_service::PREFIX_AES, strlen(encryption_service::PREFIX_AES)) === 0
            ) {
                // One encrypted blob: it can only be decrypted whole.
                $decrypted = $encsvc->decrypt($head . (string)stream_get_contents($fh));
                if ($decrypted === false) {
                    return;
                }
                $lines = self::split_lines($decrypted);
            } else {
                rewind($fh);
            }
            $next = function () use (&$lines, &$li, $fh): ?string {
                if ($lines !== null) {
                    return $li < count($lines) ? $lines[$li++] : null;
                }
                while (($raw = fgets($fh)) !== false) {
                    $line = rtrim($raw, "\r\n");
                    if ($line !== '') {
                        return $line;
                    }
                }
                return null;
            };
            $open = function () use (&$tfh, $tmp, &$writefailed): bool {
                if ($tfh !== null) {
                    return true;
                }
                // Unpredictable name plus O_EXCL: a temp path built only from the pid
                // could be pre-created as a symlink, and this is a web service.
                $tfh = @fopen($tmp, 'xb');
                if ($tfh === false) {
                    $tfh = null;
                    $writefailed = true;
                    return false;
                }
                return true;
            };
            $activate = function () use (
                &$active,
                &$deferred,
                &$kept,
                &$writefailed,
                &$tfh,
                &$lines,
                &$li,
                &$linestart,
                $fh,
                $open
            ): void {
                if ($active) {
                    return;
                }
                $active = true;
                if ($deferred === 0 || !$open()) {
                    return;
                }
                if ($lines !== null) {
                    for ($i = 0; $i < $li - 1; $i++) {
                        $data = $lines[$i] . "\n";
                        $ok = @fwrite($tfh, $data);
                        if ($ok === false || $ok < strlen($data)) {
                            $writefailed = true;
                            return;
                        }
                    }
                } else {
                    // Re-read the kept prefix line by line (normalised exactly as a kept
                    // line is written below), then carry on from where the walk was.
                    $resume = ftell($fh);
                    rewind($fh);
                    while (ftell($fh) < $linestart && ($raw = fgets($fh)) !== false) {
                        $line = rtrim($raw, "\r\n");
                        if ($line === '') {
                            continue;
                        }
                        $data = $line . "\n";
                        $ok = @fwrite($tfh, $data);
                        if ($ok === false || $ok < strlen($data)) {
                            $writefailed = true;
                            break;
                        }
                    }
                    fseek($fh, $resume);
                }
                $kept += $deferred;
            };
            $keep = function (string $line) use (&$tfh, &$kept, &$writefailed, &$active, &$deferred, $open): void {
                if ($writefailed) {
                    return;
                }
                if (!$active) {
                    $deferred++;
                    return;
                }
                if (!$open()) {
                    return;
                }
                $data = $line . "\n";
                $ok = @fwrite($tfh, $data);
                if ($ok === false || $ok < strlen($data)) {
                    $writefailed = true;
                    return;
                }
                $kept++;
            };

            while (true) {
                if ($lines === null) {
                    $linestart = (int)ftell($fh);
                }
                $line = $next();
                if ($line === null) {
                    break;
                }
                if ($remaining <= 0) {
                    // Limit reached with lines left: keep this one and all the rest.
                    $stoppedearly = true;
                    $activate();
                    $keep($line);
                    if ($lines === null) {
                        while (!$writefailed && ($raw = fgets($fh)) !== false) {
                            $rest = rtrim($raw, "\r\n");
                            if ($rest !== '') {
                                $keep($rest);
                            }
                        }
                    } else {
                        while (($rest = $next()) !== null) {
                            $keep($rest);
                        }
                    }
                    break;
                }
                $rec = json_decode($line, true);
                if (!is_array($rec)) {
                    $activate();
                    $malformed++;
                    continue;
                }
                $rt = $rec['record_type'] ?? '';
                if (!isset($typeset[$rt])) {
                    $survivors++;
                    $keep($line);
                    continue;
                }
                if ($fromiso !== '') {
                    $capturedat = $rec['captured_at'] ?? '';
                    if ($capturedat !== '' && strcmp($capturedat, $fromiso) < 0) {
                        $survivors++;
                        $keep($line);
                        continue;
                    }
                }
                $activate();
                $entity = '';
                if ($rt === 'entity_snapshot') {
                    $entity = (string)($rec['entity'] ?? '');
                } else if ($rt === 'event') {
                    $entity = (string)($rec['event_name'] ?? '');
                }
                $out[] = [
                    'id'          => (string)($rec['id'] ?? ''),
                    'captured_at' => (string)($rec['captured_at'] ?? ''),
                    'record_type' => $rt,
                    'entity'      => $entity,
                    'data'        => json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
                $remaining--;
                $emittedhere++;
            }
        } finally {
            fclose($fh);
            // The fclose() call flushes, so it is the last place a write into the replacement
            // can fail. An unchecked close would hide exactly the truncation the
            // short-write guards above exist to catch, and the truncated file would
            // then be renamed over the original.
            if ($tfh !== null && fclose($tfh) === false) {
                $writefailed = true;
            }
        }
        $malformedtotal += $malformed;

        $replace = $stoppedearly
            ? $kept > 0
            : ($survivors > 0 && ($emittedhere > 0 || $malformed > 0));
        $remove = ($stoppedearly && $kept === 0) || (!$stoppedearly && $survivors === 0);
        if ($writefailed || !$replace) {
            @unlink($tmp);
        }
        if ($writefailed) {
            return;   // Original left in place: served again, never lost.
        }
        if ($replace) {
            @chmod($tmp, \local_intellistream\buffer::file_permissions());
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
            } else {
                $changed = true;
            }
        } else if ($remove) {
            if (!buffer::remove_file($path)) {
                $undeletable[] = basename($path);
            } else {
                $changed = true;
            }
        }
    }

    /**
     * Split a decrypted buffer body into its non-empty lines.
     *
     * The /u modifier makes preg_split() return FALSE on invalid UTF-8 (a short
     * write can leave a file ending mid-sequence), so fall back to the byte-safe
     * "\n" split append() writes with; each line still goes through json_decode(),
     * so a bad line is dropped as malformed on its own.
     *
     * @param string $body
     * @return string[]
     */
    private static function split_lines(string $body): array {
        $lines = preg_split('/\R/u', $body, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($lines)) {
            return $lines;
        }
        return array_values(array_filter(explode("\n", $body), static function ($line) {
            return $line !== '';
        }));
    }

    /**
     * Record that this call handed records over.
     *
     * Records returned here are removed from the buffer before the caller confirms
     * receipt, so the verification sweep cannot count them as delivered: a pull since
     * an entity's pass began keeps that pass's fingerprints from being confirmed.
     * Rate-limited, because every set_config() purges the plugin's config cache, and
     * written only while fingerprints are kept and the site also ships to object
     * storage (a pull-only site trusts its fingerprints).
     *
     * @return void
     */
    private static function note_pull(): void {
        // Only a site that also ships to object storage treats a pull as a possible loss;
        // a pull-only site trusts its fingerprints (change_ledger::loss_since()).
        if (!\local_intellistream\change_ledger::armed() || !\local_intellistream\s3_client::from_config()->is_configured()) {
            return;
        }
        $now = time();
        if (($now - (int)config::get('last_pull_time', 0)) < \local_intellistream\change_ledger::PULL_NOTE_SEC) {
            return;
        }
        set_config('last_pull_time', $now, config::COMPONENT);
    }

    /**
     * Record that the puller called, records or not: on a pull-only site this is what
     * tells the plugin its buffer is being collected (config::destination_ready()).
     * Written at most once an hour, because every set_config() purges the config cache.
     *
     * @return void
     */
    private static function note_pull_seen(): void {
        $now = time();
        if (($now - (int)config::get('last_pull_seen', 0)) < HOURSECS) {
            return;
        }
        set_config('last_pull_seen', $now, config::COMPONENT);
    }

    /**
     * Record that unparseable buffer lines were dropped during a pull.
     *
     * Dropping them is what stops a corrupt line pinning its file forever, but a
     * silent drop would be the same class of defect this whole function exists to
     * avoid, so it leaves a durable marker the status page can read.
     *
     * Throttled to one write per MALFORMED_NOTE_SEC for the reason documented on
     * buffer::note_cap_refusal(): every set_config() on this plugin purges the
     * plugin config cache that config::enabled() reads on every page render, so
     * an unthrottled marker would turn corrupt buffer data into a site-wide
     * performance problem.
     *
     * @param int $count Lines dropped in this call.
     * @return void
     */
    private static function note_malformed(int $count): void {
        debugging(
            'local_intellistream: pull_export dropped ' . $count . ' unparseable buffer '
            . 'line(s). They could not be delivered to any caller and would otherwise '
            . 'have held their buffer file on disk indefinitely.',
            DEBUG_NORMAL
        );

        $now = time();
        $last = (int)config::get('last_pullmalformed_time', 0);
        if (($now - $last) < self::MALFORMED_NOTE_SEC) {
            return;
        }
        set_config('last_pullmalformed_time', $now, config::COMPONENT);
        set_config('last_pullmalformed_count', $count, config::COMPONENT);
    }
}
