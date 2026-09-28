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
 * Role-select options for the LTI role-assignment setting.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\helpers;

/**
 * Builds the dropdown of roles that may be configured as the LTI role.
 */
class lti_roles_helper {
    /**
     * Options for the `ibnltirole` setting: a "not selected" entry plus every
     * role that allows the viewlti capability.
     *
     * @return array roleid => name
     */
    public static function options(): array {
        $options = ['' => get_string('notselected', 'local_intellistream')];
        if ($roles = get_roles_with_capability('local/intellistream:viewlti', CAP_ALLOW)) {
            foreach ($roles as $role) {
                $options[$role->id] = !empty($role->name)
                    ? format_string($role->name)
                    : ucfirst($role->shortname);
            }
        }
        return $options;
    }
}
