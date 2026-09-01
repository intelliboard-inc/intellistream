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
 * Adhoc task that runs (or resumes) the historical backfill on demand.
 *
 * Queued by the control webhook's reset_migration / reset_datatype handlers
 * (see {@see \local_intellistream\webhook_commands}) so the multi-minute
 * backfill runs in Moodle's adhoc runner rather than inline in the HTTP
 * request. Mirrors {@see set_lti_role_adhoc_task}. Custom data
 * `{only:[entity,...]}` restricts the run to those entities (empty = full run).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\task;

/**
 * Run the IntelliStream historical backfill (control-triggered).
 */
class run_backfill_adhoc_task extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_run_backfill', 'local_intellistream');
    }

    /**
     * Do the job. Exceptions bubble up so the adhoc runner retries.
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        $only = (!empty($data->only) && is_array($data->only)) ? array_values($data->only) : [];

        $result = \local_intellistream\backfill::run($only);
        mtrace('local_intellistream: webhook-triggered backfill run — ' . json_encode($result));
    }
}
