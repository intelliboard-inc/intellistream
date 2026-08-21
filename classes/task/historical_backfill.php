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
 * Scheduled wrapper around cli/backfill.php for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

/**
 * Scheduled-task wrapper for the one-shot historical backfill.
 *
 * `cli/backfill.php` already runs the full historical snapshot — but a CLI
 * one-shot requires shell access to the host. Many sites only have the
 * Moodle UI / cron daemon available. This task lets an operator turn on the
 * backfill from Site administration > Server > Scheduled tasks instead.
 *
 * Behaviour:
 *  - REGISTERED DISABLED by default; there is NO recurring schedule. Operators
 *    trigger it once via "Run now" on the Scheduled tasks page (the shell-less
 *    equivalent of `cli/backfill.php`).
 *  - RESUMABLE, run-once: delegates to `backfill::run()`, which streams the
 *    whole registry past a per-entity keyset watermark. If a run is interrupted
 *    (timeout / OOM / reboot) it does NOT restart from zero — the operator
 *    re-runs and it resumes from the watermark. It never re-snapshots completed
 *    entities, so leaving it enabled is safe (unlike the old export_all() path).
 *  - Self-disables on completion (courtesy, mirroring
 *    `copy_intelliboard_tracking`) so an accidentally-enabled task turns itself
 *    off once the campaign finishes; `backfill::is_complete()` also short-
 *    circuits any later run.
 */
class historical_backfill extends \core\task\scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_historical_backfill', 'local_intellistream');
    }

    /**
     * Run (or resume) the historical backfill. Self-disables only once the
     * whole campaign is complete.
     */
    public function execute(): void {
        global $DB;

        $started = microtime(true);
        $result = \local_intellistream\backfill::run();
        $elapsed = round(microtime(true) - $started, 1);

        mtrace(sprintf(
            'local_intellistream: historical backfill run — %d row(s) buffered, '
                . '%d/%d entit%s done, %s (%ss, batch %s).',
            $result['rows'],
            $result['entities_done'],
            $result['entities_total'],
            $result['entities_total'] === 1 ? 'y' : 'ies',
            $result['complete'] ? 'COMPLETE' : ('not complete — ' . ($result['reason'] ?? 'resume next run')),
            $elapsed,
            $result['batch']
        ));

        // Terminal log row (progress or completion).
        try {
            $DB->insert_record('local_intellistream_logs', (object)[
                'type'        => 'historical_backfill',
                'datatype'    => null,
                'action'      => $result['complete'] ? 'complete' : 'progress',
                'details'     => json_encode([
                    'batch'          => $result['batch'],
                    'rows'           => $result['rows'],
                    'entities_done'  => $result['entities_done'],
                    'entities_total' => $result['entities_total'],
                    'reason'         => $result['reason'] ?? null,
                    'elapsed'        => $elapsed,
                ], JSON_UNESCAPED_SLASHES),
                'timecreated' => time(),
            ]);
        } catch (\Throwable $e) {
            // Best-effort: a missing audit row must not fail a completed backfill.
            // Traced rather than swallowed, so the run is still accounted for
            // somewhere an operator can see it.
            mtrace('local_intellistream: backfill result could not be logged: '
                . $e->getMessage());
        }

        // Only disable once the whole campaign is complete. An interrupted run
        // leaves the task as-is so a re-trigger (or a re-enabled schedule)
        // resumes from the watermark.
        if (!empty($result['complete'])) {
            self::disable_self();
        }
    }

    /**
     * Set the `disabled` flag on this task back to 1.
     */
    private static function disable_self(): void {
        global $DB;
        try {
            $record = $DB->get_record(
                'task_scheduled',
                ['classname' => '\\local_intellistream\\task\\historical_backfill']
            );
            if ($record) {
                $task = \core\task\manager::scheduled_task_from_record($record);
                $task->set_disabled(true);
                \core\task\manager::configure_scheduled_task($task);
            }
        } catch (\Throwable $e) {
            // As above: a failure to self-disable leaves the backfill scheduled, so
            // it re-runs the whole campaign on the next pass. Trace it rather than
            // leaving a repeating full re-export with no explanation.
            mtrace('local_intellistream: WARNING — could not disable the historical '
                . 'backfill task, so it will run again on the next cron pass: '
                . $e->getMessage());
        }
    }
}
