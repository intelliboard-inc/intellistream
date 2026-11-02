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
 * Scheduled task: per-user login counts for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

/**
 * Every 15 minutes: counts the logins in the standard log since its last run, in short
 * id blocks, and sends the totals that changed (see \local_intellistream\userlogins).
 * Its time per run is bounded the way the verification sweep's is, under its own
 * interval. Disabling it stops the counts where they are; they never go down.
 */
class count_userlogins extends \core\task\scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_count_userlogins', 'local_intellistream');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        \local_intellistream\config::refresh();
        \local_intellistream\exporter::reset_registry_cache();
        [$budget] = \local_intellistream\sweep::budget(0.0, \local_intellistream\sweep::gap_of(
            \local_intellistream\sweep::starts_of($this)
        ));
        \local_intellistream\userlogins::run($budget);
    }
}
