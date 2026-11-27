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
 * Admin setting for the LTI role, not writable over the control webhook.
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
 * control webhook — otherwise a webhook command could point it at any role id
 * (Manager, say) and then assign it to arbitrary users via set_lti_role.
 *
 * lti_service::get_lti_role() re-checks the stored value at assignment time as
 * the second half of that fix; this class closes the write path.
 */
class admin_setting_ltirole extends \admin_setting_configselect implements locally_managed {
    /**
     * Load the role choices only when the setting is displayed, saved or searched.
     *
     * settings.php is included every time the admin tree is built, so the role
     * query must not run there. The parent calls this method before it reads
     * $this->choices.
     *
     * @return bool
     */
    public function load_choices() {
        if (!is_array($this->choices)) {
            $this->choices = \local_intellistream\helpers\lti_roles_helper::options();
        }
        return true;
    }

    /**
     * Render the select, carrying a warning when the role currently stored
     * holds capabilities that assigning it at system context would hand out
     * site-wide.
     *
     * The dropdown does not filter those roles out: the choice is the
     * administrator's, and removing an option a site has already chosen would
     * break a working configuration on upgrade.
     *
     * The warning goes onto $this->description, which the parent's output_html()
     * passes to format_admin_setting(), and the description is restored afterwards
     * so rendering twice in one request cannot stack two copies. The capability
     * names are escaped because neither get_string() nor html_writer::div()
     * escapes them.
     *
     * @param mixed $data Stored value: a role id, or '' when unset.
     * @param string $query Admin search term.
     * @return string
     */
    public function output_html($data, $query = '') {
        $caps = \local_intellistream\helpers\lti_roles_helper::escalation_capabilities((int)$data);
        if (!$caps) {
            return parent::output_html($data, $query);
        }

        $original = $this->description;
        $this->description .= \html_writer::div(
            get_string('ibnltirole_privileged', 'local_intellistream', s(implode(', ', $caps))),
            'alert alert-warning mt-2'
        );
        try {
            return parent::output_html($data, $query);
        } finally {
            $this->description = $original;
        }
    }
}
