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
 * Adhoc task that (re)assigns the IntelliBoard LTI role.
 *
 * Queued by the control webhook's `set_lti_role` action with custom data
 * `{ids:[...], roles:[...]}`. Reassigns the configured LTI role to that set of
 * users (delete-then-add); a no-op when the LTI role is not configured.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\task;

/**
 * Reassign users to the IntelliBoard LTI role.
 */
class set_lti_role_adhoc_task extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_set_lti_role', 'local_intellistream');
    }

    /**
     * Do the job. Exceptions bubble up so the adhoc runner retries.
     *
     * @return void
     */
    public function execute() {
        mtrace('local_intellistream: reassigning LTI-role users — start');

        if (!\local_intellistream\services\lti_service::get_lti_role()) {
            mtrace('local_intellistream: LTI role not configured — nothing to do');
            return;
        }

        $data = $this->get_custom_data();
        $ids = (!empty($data->ids) && is_array($data->ids)) ? $data->ids : [];
        $roles = (!empty($data->roles) && is_array($data->roles)) ? $data->roles : [];

        (new \local_intellistream\services\lti_service())->set_lti_role($ids, $roles);

        mtrace('local_intellistream: reassigning LTI-role users — done');
    }
}
