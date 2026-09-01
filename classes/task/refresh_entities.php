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
 * Every-15-min INCREMENTAL entity refresh: ships only rows changed since the
 * last run (per-entity watermark on the change timestamp — see
 * exporter::export_incremental()). This keeps the warehouse near-real-time at a
 * tiny fraction of the volume of a full re-snapshot.
 *
 * The complementary daily_snapshot task does the periodic FULL reconciliation
 * (timestamp-less tables, drift correction, deletes). One-time history still
 * comes from cli/backfill.php.
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
    }
}
