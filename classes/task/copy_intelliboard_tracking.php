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
 * One-time legacy IntelliBoard tracking migration for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

/**
 * One-time scheduled task that copies legacy IntelliBoard tracking rows
 * into the IntelliStream buffer as canonical entity_snapshot records.
 *
 * Intellidata parity: the legacy `local_intelliboard` plugin wrote per-page
 * tracking to `local_intelliboard_tracking`, `_logs`, `_details`, `_trns_c`
 * and `_trns_m`. A site swapping to IntelliStream would otherwise lose that
 * history. This task reads those legacy tables (if they exist) and pushes
 * each row through `buffer::append_record()` as an `entity_snapshot`,
 * tagged with the original table name so the warehouse can land them into
 * their own raw feeds.
 *
 * Behaviour:
 *  - REGISTERED DISABLED by default (db/tasks.php). A site without legacy
 *    IntelliBoard tables would just no-op every cycle, so we make an operator
 *    opt in for the one-time migration and disable the task again afterwards.
 *  - Idempotent and resumable: keeps per-table watermarks in
 *    `local_intellistream` config (`legacy_migration_<table>_max_id`). On every
 *    run it picks up rows with `id > watermark`, in batches of
 *    `legacy_migration_batch_size` (default = `exportbatchsize` * 10), and
 *    advances the watermark only after the batch has been buffered.
 *  - Read-only against legacy tables. Never writes / never truncates them.
 *  - On completion (no new rows across any legacy table for a full sweep)
 *    writes a "complete" row to `local_intellistream_logs` and self-disables.
 *
 * Output is plain `entity_snapshot` records — they ride the existing buffer
 * + shipper pipeline. No new schema, no new raw feed plumbing.
 */
class copy_intelliboard_tracking extends \core\task\scheduled_task {
    /** Map: legacy table name => canonical entity name on the snapshot. */
    private const LEGACY_TABLES = [
        'local_intelliboard_tracking' => 'local_intelliboard_tracking',
        'local_intelliboard_logs'     => 'local_intelliboard_logs',
        'local_intelliboard_details'  => 'local_intelliboard_details',
        'local_intelliboard_trns_c'   => 'local_intelliboard_trns_c',
        'local_intelliboard_trns_m'   => 'local_intelliboard_trns_m',
    ];

    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_copy_intelliboard_tracking', 'local_intellistream');
    }

    /**
     * Run one migration pass: read up to N new rows from every present legacy
     * table, push them through the buffer, advance watermarks. Returns
     * silently if the master switch is off or if no legacy table exists.
     */
    public function execute(): void {
        global $DB, $CFG;

        if (!\local_intellistream\config::enabled()) {
            mtrace('local_intellistream: disabled — legacy migration skipped.');
            return;
        }

        $batchsize = (int)get_config('local_intellistream', 'legacy_migration_batch_size');
        if ($batchsize <= 0) {
            $batchsize = \local_intellistream\config::export_batch_size() * 10;
        }

        $batch = \core\uuid::generate();
        $siteid = \local_intellistream\config::site_id();
        // As backfill::run(): every append is refused while unpaired, so a run here
        // would read every legacy table only to be refused on every row. Bail before
        // the first read so no watermark moves.
        if ($siteid === '') {
            mtrace('local_intellistream: site id not set (unpaired) — legacy migration skipped, '
                . 'no watermark moved.');
            return;
        }
        $pluginversion = (int)\local_intellistream\config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;

        $manager = $DB->get_manager();
        $totalcopied = 0;
        $tablespresent = 0;
        $tablesexhausted = 0;

        foreach (self::LEGACY_TABLES as $table => $entity) {
            try {
                $xmltable = new \xmldb_table($table);
                $exists = $manager->table_exists($xmltable);
            } catch (\Throwable $e) {
                $exists = false;
            }
            if (!$exists) {
                mtrace("local_intellistream: legacy table '{$table}' absent — skipped.");
                continue;
            }
            $tablespresent++;

            $watermarkkey = 'legacy_migration_' . $table . '_max_id';
            $watermark = (int)get_config('local_intellistream', $watermarkkey);

            $rows = 0;
            $refused = 0;
            $lastid = $watermark;
            $rs = null;
            try {
                $rs = $DB->get_recordset_select(
                    $table,
                    'id > :wm',
                    ['wm' => $watermark],
                    'id ASC',
                    '*',
                    0,
                    $batchsize
                );
                foreach ($rs as $row) {
                    $payload = [
                        'id'             => \core\uuid::generate(),
                        'site_id'        => $siteid,
                        'captured_at'    => \local_intellistream\clock::now(),
                        'plugin_version' => $pluginversion,
                        'moodle_version' => $moodleversion,
                        'record_type'    => 'entity_snapshot',
                        'entity'         => $entity,
                        'snapshot_batch' => $batch,
                        'legacy_source'  => $table,
                        // Whole-row read: apply the same credential-column
                        // filter every registry export path gets, so this
                        // lane cannot ship a forbidden column the funnel
                        // would have stripped.
                        'entity_data'    => \local_intellistream\exporter::strip_forbidden_row_keys(
                            (array)$row,
                            $table
                        ),
                    ];
                    // Honour the refusal, with the same transient/permanent split as
                    // exporter::export_entity_window(). Discarding the return value
                    // counted the row as buffered and let the watermark below advance
                    // past a row that was never written.
                    //
                    // At capacity: transient, so stop. $rows and $lastid are both
                    // updated only AFTER a successful append, so breaking here leaves
                    // the watermark at the last row actually buffered and the next
                    // run resumes at this one.
                    //
                    // Otherwise the row itself is unshippable (over-size, or it will
                    // not encode) and no number of retries changes that, so carry on
                    // past it rather than pinning this table's watermark forever.
                    if (!\local_intellistream\buffer::append_record($payload)) {
                        $rowid = isset($row->id) ? (int)$row->id : '?';
                        // Transient first: at capacity, or unpaired. Both are states
                        // of the site that get fixed, so neither may let the
                        // watermark past. PERMANENT is reached only by elimination.
                        if (\local_intellistream\buffer::at_capacity() || $siteid === '') {
                            mtrace("local_intellistream: legacy '{$table}' — buffer at its disk cap at "
                                . "id {$rowid}; stopping short of that row so the watermark cannot pass "
                                . 'it. The next run resumes from the watermark.');
                            break;
                        }
                        $refused++;
                        mtrace("local_intellistream: legacy '{$table}' — row id {$rowid} REFUSED by the "
                            . 'buffer and NOT exported (over-size, or it would not encode). Permanent '
                            . 'for this row, so the copy continues past it.');
                        // Advance past it, or the next run re-reads and re-refuses the
                        // same row forever. Correct only because the line above makes
                        // the skip visible.
                        if (isset($row->id) && (int)$row->id > $lastid) {
                            $lastid = (int)$row->id;
                        }
                        continue;
                    }
                    $rows++;
                    if (isset($row->id) && (int)$row->id > $lastid) {
                        $lastid = (int)$row->id;
                    }
                }
            } catch (\Throwable $e) {
                mtrace("local_intellistream: legacy '{$table}' read error: " . $e->getMessage());
                continue;
            } finally {
                // Close on EVERY exit, not just the happy path.
                // Several of these loops throw by design, so a close() placed after
                // the foreach leaked the cursor on the paths that matter most.
                if ($rs instanceof \moodle_recordset) {
                    $rs->close();
                }
            }

            // Testing $refused as well matters: a batch in which EVERY row was
            // permanently refused buffers nothing, and on a `$rows > 0` test alone
            // the watermark would not move and the next run would re-read exactly
            // the same rows.
            if ($rows > 0 || $refused > 0) {
                set_config($watermarkkey, $lastid, 'local_intellistream');
                if ($refused > 0) {
                    mtrace("local_intellistream: legacy '{$table}' — {$refused} row(s) permanently "
                        . 'refused and skipped; the watermark has moved past them.');
                }
                mtrace("local_intellistream: legacy '{$table}' — {$rows} rows buffered "
                    . "(watermark advanced to id {$lastid}).");
                $totalcopied += $rows;
            } else {
                $tablesexhausted++;
                mtrace("local_intellistream: legacy '{$table}' — no new rows past watermark id {$watermark}.");
            }
        }

        // Log this run.
        try {
            $DB->insert_record('local_intellistream_logs', (object)[
                'type'        => 'legacy_migration',
                'datatype'    => 'local_intelliboard_tracking',
                'action'      => $totalcopied > 0 ? 'progress' : 'idle',
                'details'     => json_encode([
                    'batch'             => $batch,
                    'rows_buffered'     => $totalcopied,
                    'tables_present'    => $tablespresent,
                    'tables_exhausted'  => $tablesexhausted,
                ], JSON_UNESCAPED_SLASHES),
                'timecreated' => time(),
            ]);
        } catch (\Throwable $e) {
            // Logging is best-effort.
            mtrace('local_intellistream: legacy migration log insert failed: ' . $e->getMessage());
        }

        // If every present legacy table is exhausted AND at least one was
        // present, the migration is complete: write a terminal log row, and
        // disable this scheduled task so it stops running on this site.
        if ($tablespresent > 0 && $tablesexhausted === $tablespresent) {
            try {
                $DB->insert_record('local_intellistream_logs', (object)[
                    'type'        => 'legacy_migration',
                    'datatype'    => 'local_intelliboard_tracking',
                    'action'      => 'complete',
                    'details'     => 'Legacy IntelliBoard tracking migration complete.',
                    'timecreated' => time(),
                ]);
            } catch (\Throwable $e) {
                // Best-effort: a missing audit row must not abort a completed
                // migration. Traced, not swallowed — the admin Logs page reads this
                // table, so silently losing the row would tell an operator the
                // migration never finished.
                mtrace('local_intellistream: legacy migration completion could not be '
                    . 'logged: ' . $e->getMessage());
            }
            self::disable_self();
            mtrace('local_intellistream: legacy migration complete — task disabled.');
        }
    }

    /**
     * Self-disable this scheduled task once the one-time migration finishes,
     * so it does not keep polling forever.
     */
    private static function disable_self(): void {
        global $DB;
        try {
            $record = $DB->get_record(
                'task_scheduled',
                ['classname' => '\\local_intellistream\\task\\copy_intelliboard_tracking']
            );
            if ($record) {
                $task = \core\task\manager::scheduled_task_from_record($record);
                $task->set_disabled(true);
                \core\task\manager::configure_scheduled_task($task);
            }
        } catch (\Throwable $e) {
            // Swallowing this would be worse than it looks: if the task cannot
            // disable itself it stays scheduled and re-runs the whole legacy
            // migration on the next cron pass, and every pass after that. Traced
            // loudly so a repeating migration has a visible cause.
            mtrace('local_intellistream: WARNING — could not disable the legacy '
                . 'migration task, so it will run again on the next cron pass: '
                . $e->getMessage());
        }
    }
}
