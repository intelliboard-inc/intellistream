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
 * Admin setting for the LTI role, not writable from the control plane.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\admin;

/**
 * The `ibnltirole` setting: unchanged select, marked {@see locally_managed}.
 *
 * The dropdown is already restricted to roles carrying
 * local/intellistream:viewlti (lti_roles_helper::options()). That constraint
 * only binds the admin UI, so the setting must not be writable over the
 * control webhook — otherwise the control plane could point it at any role id
 * (Manager, say) and then assign it to arbitrary users via set_lti_role.
 *
 * lti_service::get_lti_role() re-checks the stored value at assignment time as
 * the second half of that fix; this class closes the write path.
 */
class admin_setting_ltirole extends \admin_setting_configselect implements locally_managed {
}
