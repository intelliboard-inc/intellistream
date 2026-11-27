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
 * Syslogs datatype marker for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\datatypes;

/**
 * Marker class for the `syslogs` datatype.
 *
 * `syslogs` is not a separate shipped record type. The event observer
 * (`\local_intellistream\observer::capture`, registered on `\core\event\base`)
 * already captures every Moodle event, including the core admin-action events,
 * so the reporting warehouse builds `syslogs` as a filtered view over its copy
 * of Moodle's standard logstore (`db_logstore_standard_log_raw`).
 *
 * "Admin actions" are the core events that change roles and capabilities,
 * plugins, site configuration (config_log_created), users (created, updated,
 * deleted, password updated, logged in as) and courses or course categories.
 * The exact patterns are in admin_action_eventname_patterns(). The columns are
 * those of the logstore table.
 *
 * @copyright 2026 IntelliBoard / IntelliStream
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syslogs_datatype {
    /** Entity / registry key (used by the reporting warehouse). */
    const ENTITY = 'syslogs';

    /**
     * The eventname LIKE patterns the reporting warehouse view filters on.
     *
     * The reporting warehouse's syslogs view is the source of truth; this list
     * must be kept identical to the filter in that view.
     *
     * @return string[]
     */
    public static function admin_action_eventname_patterns(): array {
        return [
            // Role / capability changes.
            '\\core\\event\\role\\_%',
            // Plugin install / upgrade / enable / disable.
            '\\core\\event\\plugin\\_%',
            // Site config edits.
            '\\core\\event\\config\\_log\\_created',
            // User actions performed by an admin.
            '\\core\\event\\user\\_created',
            '\\core\\event\\user\\_updated',
            '\\core\\event\\user\\_deleted',
            '\\core\\event\\user\\_password\\_updated',
            '\\core\\event\\user\\_loggedinas',
            // Course-level admin actions.
            '\\core\\event\\course\\_created',
            '\\core\\event\\course\\_updated',
            '\\core\\event\\course\\_deleted',
            '\\core\\event\\course\\_restored',
            '\\core\\event\\course\\_backup\\_created',
            '\\core\\event\\course\\_category\\_%',
        ];
    }
}
