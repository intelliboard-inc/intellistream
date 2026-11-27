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
 * Scheduled task: delete plugin log entries older than the configured lifetime.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

/**
 * Deletes rows of local_intellistream_logs older than the loglifetime setting.
 *
 * Works like the standard log store's cleanup task: it deletes one day of rows at
 * a time, oldest first, and stops after five minutes. The next run continues.
 */
class cleanup_logs extends \core\task\scheduled_task {
    /** @var int Maximum run time in seconds. */
    const MAX_RUN_SEC = 300;

    /**
     * Name shown to administrators.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanup_logs', 'local_intellistream');
    }

    /**
     * Delete the old log rows.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $lifetime = (int)\local_intellistream\config::get('loglifetime', 90);
        if ($lifetime <= 0) {
            mtrace('Log lifetime is not set: no log entries deleted.');
            return;
        }

        $cutoff = time() - ($lifetime * DAYSECS);
        $start = time();
        $deleted = 0;
        // MIN() returns null when no row is older than the cutoff. A row with
        // timecreated 0 returns "0", which must still be deleted.
        while (true) {
            $min = $DB->get_field_select('local_intellistream_logs', 'MIN(timecreated)', 'timecreated < ?', [$cutoff]);
            if ($min === false || $min === null) {
                break;
            }
            $before = min((int)$min + DAYSECS, $cutoff);
            $deleted += $DB->count_records_select('local_intellistream_logs', 'timecreated < ?', [$before]);
            $DB->delete_records_select('local_intellistream_logs', 'timecreated < ?', [$before]);
            if (time() > $start + self::MAX_RUN_SEC) {
                break;
            }
        }
        mtrace("Deleted {$deleted} log entries older than {$lifetime} days.");
    }
}
