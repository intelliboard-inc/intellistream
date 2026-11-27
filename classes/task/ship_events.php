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
 * Scheduled task: ship closed buffer files to object storage.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

/**
 * Reads closed buffer files, compresses them, and ships them to S3.
 *
 * Delegates to local_intellistream\shipper::run(), which sweeps stale buffer
 * files, publishes the buffer measurement the capture path decides on, and ships
 * closed files to object storage.
 *
 * Also folds in the (filtered, cheap) task-table export — Moodle's own
 * task_log/task_scheduled/task_adhoc rows for this plugin — so the IntelliBoard
 * service's task views stay near-real-time. It runs with the 1-minute shipper
 * rather than the 15-minute refresh so a finished run surfaces within one or two
 * buffer rotations.
 */
class ship_events extends \core\task\scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_ship_events', 'local_intellistream');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        // Buffer this plugin's task-table state before shipping, but isolate it:
        // a task-export failure must not block the file-ship step below (the
        // reason this task exists). The buffered rows ride the normal rotation
        // (about 2 minutes); there is no forced flush, so event shipping is not affected.
        if (\local_intellistream\config::enabled() && \local_intellistream\config::destination_ready()) {
            try {
                $batch = \core\uuid::generate();
                $n = \local_intellistream\exporter::export_task_state($batch);
                if ($n > 0) {
                    mtrace("local_intellistream: task-state export — {$n} row(s) buffered (batch {$batch}).");
                }
            } catch (\Throwable $e) {
                mtrace('local_intellistream: task-state export failed (non-fatal): ' . $e->getMessage());
            }
        }

        // True: the scheduled run is the one that may ship through a closed load
        // gate while the backlog is large (see shipper::backlog_over_gate()).
        \local_intellistream\shipper::run(true);
    }
}
