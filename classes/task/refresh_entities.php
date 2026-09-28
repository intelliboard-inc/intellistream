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
 * Scheduled task: periodic entity snapshot refresh for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

/**
 * The plugin's one recurring export task, every 15 minutes. Each run:
 *
 *  1. the timestamp lane: rows whose change timestamp moved since the last run
 *     (exporter::export_incremental()), which keeps the warehouse near-real-time;
 *  2. the datatype configuration catalogue;
 *  3. on a new install still held for the historical backfill, makes sure the
 *     backfill is queued (backfill::ensure_queued());
 *  4. the verification sweep (sweep::run()): a slice of every table, so each is read
 *     once per 24 h — tables with no change timestamp, changes that do not move one,
 *     login counts, and the census that lets the server reconcile deletes. This is
 *     the work the removed daily full snapshot did in one burst, spread evenly.
 */
class refresh_entities extends \core\task\scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_refresh_entities', 'local_intellistream');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        if (!\local_intellistream\config::enabled()) {
            mtrace('local_intellistream: disabled — entity refresh skipped.');
            return;
        }
        // Scheduled, "Run now" and CLI runs all come through here: with nowhere for the
        // records to go, read nothing and move no watermark.
        if (!\local_intellistream\config::destination_ready()) {
            mtrace('local_intellistream: site id or destination not set — entity refresh skipped, '
                . 'nothing read, no watermark moved.');
            return;
        }

        $started = microtime(true);
        $result = \local_intellistream\exporter::export_incremental();
        mtrace(sprintf(
            'local_intellistream: incremental entity refresh complete — %d changed row(s) across %d entit%s (batch %s).',
            $result['rows'],
            $result['entities'],
            $result['entities'] === 1 ? 'y' : 'ies',
            $result['batch']
        ));

        // Ship the per-datatype config catalog (static metadata) so the control
        // plane's Datatypes-configuration tab populates. Isolated: a failure here
        // must not fail the entity refresh above.
        try {
            \local_intellistream\exporter::export_datatype_config($result['batch']);
        } catch (\Throwable $e) {
            mtrace('local_intellistream: datatype_config export failed (non-fatal): ' . $e->getMessage());
        }

        try {
            \local_intellistream\backfill::ensure_queued();
        } catch (\Throwable $e) {
            mtrace('local_intellistream: could not queue the historical backfill (retried next run): '
                . $e->getMessage());
        }

        \local_intellistream\sweep::run(microtime(true) - $started);
    }
}
