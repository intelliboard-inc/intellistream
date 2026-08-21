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
        // They were not at risk of deletion — enforce_disk_cap() sits below that
        // same early return and only ever measures the current directory — so this
        // is undelivered data, not lost data. Records still owed, with nothing
        // coming to collect them.
        $files = [];
        foreach (config::buffer_dirs() as $dir) {
            foreach (\local_intellistream\buffer::safe_files($dir, ['*.jsonl.closed']) as $path) {
                $files[] = $path;
            }
        }
        if (!$files) {
            return [];
        }
        usort($files, function ($a, $b) {
            return (int)@filemtime($a) <=> (int)@filemtime($b);
        });

        $encsvc = new encryption_service();
        $out = [];
        $remaining = $limit;
        $malformedtotal = 0;

        foreach ($files as $path) {
            if ($remaining <= 0) {
                break;
            }
            $body = \local_intellistream\buffer::safe_contents($path);
            if ($body === false) {
                continue;
            }
            // Buffer files are NOT encrypted on disk — append() always writes
            // plaintext, and no release ever wrote them any other way: the only
            // encrypt() call site the plugin has ever had is in shipper::run(),
            // on the in-memory batch after the file has been read (see
            // classes/services/encryption_service.php).
            //
            // decrypt() is nevertheless called UNCONDITIONALLY, and must stay
            // that way. It dispatches on the wire-format prefix and returns a
            // non-matching blob unchanged, so it is already a no-op on the
            // plaintext this path actually sees. Gating it on is_enabled()
            // instead would make reading correct only while a mutable admin
            // setting happened to agree with what is on disk: with the setting
            // off, a prefixed blob would reach json_decode(), fail on every
            // line, and be dropped as malformed — the file then unlinked with
            // its records never delivered. Nothing can produce such a file
            // today, which is exactly why the coupling is not worth keeping:
            // it buys nothing and it makes a decode path depend on a switch.
            //
            // On a decrypt failure (auth-tag mismatch, missing or rotated key)
            // skip the file rather than fail the whole call. That leaves it
            // `.closed` for a later pull instead of dropping it, which is the
            // safe direction for data — undecryptable is not undeliverable, and
            // a restored key recovers it. The cost is that such a file holds
            // disk until then; have_capacity()/note_cap_refusal() surface that.
            $decrypted = $encsvc->decrypt($body);
            if ($decrypted === false) {
                continue;
            }
            $body = $decrypted;

            $lines = preg_split('/\R/u', $body, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($lines)) {
                // The /u modifier makes preg_split() return FALSE on invalid UTF-8,
                // and count(false) is a fatal TypeError on PHP 8 — which would kill
                // this web service call, and every retry of it, until the file was
                // removed by hand. Reachable without any tampering: append() notes
                // that a short fwrite leaves partial bytes on disk, so a genuine
                // file can end mid-multibyte-sequence.
                //
                // Fall back to the byte-safe split append() actually writes with —
                // a plain "\n", exactly as the shipper's filter_site_id() does —
                // rather than skipping the whole file. Skipping cost nothing on an
                // S3 site, where the shipper drains the file with the same split;
                // but on a PULL-ONLY site this web service is the only drain, so a
                // single bad byte pinned every OTHER record in that file for ever,
                // invisibly (a safe regular file raises no unsafe/unreadable alert).
                // Each line below still goes through json_decode(), so the line
                // carrying the bad bytes — or a partial final line — is dropped as
                // malformed on its own, and the deliverable records get out.
                $lines = array_values(array_filter(
                    explode("\n", $body),
                    static function ($line) {
                        return $line !== '';
                    }
                ));
            }
            $consumed = 0;
            $emittedhere = 0;
            $malformed = 0;
            $survivors = [];
            $totalines = count($lines);
            foreach ($lines as $line) {
                if ($remaining <= 0) {
                    break;
                }
                $consumed++;
                $rec = json_decode($line, true);
                if (!is_array($rec)) {
                    // Deliberately NOT a survivor. A line that will not parse can
                    // never be emitted, so keeping it would pin its file forever:
                    // the file would reach every later pull with $emittedhere === 0
                    // and take neither the unlink nor the rewrite branch below,
                    // growing the buffer monotonically until have_capacity() refused
                    // all capture. Dropping it is not the same as discarding
                    // deliverable data — it is discarding a record no caller could
                    // ever receive — but it is still a loss, so it is counted and
                    // reported rather than swallowed.
                    $malformed++;
                    continue;
                }
                $rt = $rec['record_type'] ?? '';
                if (!isset($typeset[$rt])) {
                    $survivors[] = $line;
                    continue;
                }
                if ($fromiso !== '') {
                    $capturedat = $rec['captured_at'] ?? '';
                    // Lexicographic compare on RFC 3339 is correct since the
                    // format is fixed-width and uses 'Z' for UTC.
                    if ($capturedat !== '' && strcmp($capturedat, $fromiso) < 0) {
                        $survivors[] = $line;
                        continue;
                    }
                }
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

            // Commit. A file is only ever removed once every record in it has
            // actually been handed to a caller — deletion is driven by what was
            // EMITTED, never by what was merely walked past.
            //
            // The distinction matters because a line can be walked and not
            // returned: its record_type can be outside the caller's
            // `record_types` filter, or it can predate `from_ingested_at`.
            // Counting those as consumed and unlinking the file destroys records
            // the caller never received, and a caller using the documented
            // `record_types` parameter would silently lose every other type in
            // the file. (A line that will not PARSE is handled differently — see
            // the $malformed branch above.)
            //
            /* Three cases:
                 - stopped early on the per-call limit: shrink the file to the
                   records that were NOT handed over, so the next pull resumes
                   after them. See the block below for why leaving it untouched
                   could not work.
                 - walked end-to-end with nothing left over: delete. This is the
                   normal drain, and it is what keeps a pull-integration site from
                   accumulating buffer files forever.
                 - walked end-to-end with records left over: rewrite the file with
                   just those, so they stay available to a later pull. Residue is
                   now genuinely transient: every record type the plugin writes is
                   in ALLOWED_TYPES, so a survivor is only ever a `record_types`
                   or `from_ingested_at` exclusion, both of which a later pull
                   with different arguments will collect. */

            // Counted before the split because BOTH commits below drop the
            // malformed lines they walked past.
            $malformedtotal += $malformed;

            if ($consumed !== $totalines) {
                // The per-call limit stopped the walk mid-file. This branch used
                // to leave the file untouched "so the next pull rescans it", on
                // the assumption that a rescan makes progress. It does not: the
                // rescan starts at line 1 with a full budget and stops at exactly
                // the same line, so a file holding MORE deliverable records than
                // the caller's limit could never drain. Every pull returned the
                // same first `limit` records, the record after them was never
                // delivered, and because the budget was spent here the loop broke
                // before reaching any other file — so the whole pull integration
                // stalled permanently while the buffer grew to the cap and
                // capture stopped. Reachable on any ordinary site: files rotate
                // at 64 MB or 120 s, and a bulk entity backfill or roughly nine
                // events a second fills one past the 1000-record default limit.
                //
                // So commit the prefix instead: keep the records this call did
                // NOT hand over — the ones filtered out, plus everything after
                // the point the walk stopped — and drop the rest. Order is
                // preserved because every survivor comes from the walked prefix.
                // This makes the partial path consistent with the full-walk path,
                // which already deletes emitted records before the caller can
                // confirm receipt; rewrite_with() is atomic and leaves the
                // original in place on failure, so a failed rewrite re-serves
                // those records rather than losing them.
                $keep = array_merge($survivors, array_slice($lines, $consumed));
                if (!$keep) {
                    // Not reachable — an empty remainder means the walk reached
                    // the last line, which is the full-walk case. Handled anyway
                    // so this branch can never leave a stale empty file behind.
                    @unlink($path);
                } else {
                    self::rewrite_with($path, $keep);
                }
                continue;
            }
            if (!$survivors) {
                @unlink($path);
            } else if ($emittedhere > 0 || $malformed > 0) {
                // The malformed count matters here: without it, a file whose only
                // change was dropping an unparseable line would keep it on disk.
                self::rewrite_with($path, $survivors);
            }
        }

        if ($malformedtotal > 0) {
            self::note_malformed($malformedtotal);
        }

        return $out;
    }

    /**
     * Replace a closed buffer file with the subset of its lines that were not
     * handed to the caller.
     *
     * Written to a sibling temp file and moved into place with rename(), which is
     * atomic within a filesystem: a reader either sees the whole old file or the
     * whole new one, never a half-written mix. If any step fails the original is
     * left exactly as it was, which is the safe direction — a file that is not
     * shrunk is re-scanned on the next pull and its records are returned again
     * (the caller dedupes on `id`), whereas a truncated one loses them.
     *
     * Only ever called for `.closed` files, which have no live writer.
     *
     * @param string   $path  the .closed file to shrink
     * @param string[] $lines the lines to keep, in their original order
     * @return void
     */
    private static function rewrite_with(string $path, array $lines): void {
        // Unpredictable name plus O_EXCL below: a temp path built only from the
        // pid can be pre-created as a symlink, and this method is reachable from a
        // web service, so that would be an arbitrary file write. The random part
        // also stops a temp left behind by a crashed rewrite from wedging this
        // path for every later pull that computes the same name.
        $tmp = $path . '.rewrite-' . getmypid() . '-' . \local_intellistream\buffer::temp_suffix();
        $payload = implode("\n", $lines) . "\n";
        // Mode 'xb' is O_CREAT|O_EXCL: it creates the file or fails, and it fails if
        // anything already occupies the name — a symlink included — so there is no
        // check here to lose a race on. LOCK_EX is gone with file_put_contents: it
        // locked a path only this process could name, so it never guarded anything.
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            return;
        }
        $ok = @fwrite($fh, $payload);
        fclose($fh);
        if ($ok === false || $ok < strlen($payload)) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
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
