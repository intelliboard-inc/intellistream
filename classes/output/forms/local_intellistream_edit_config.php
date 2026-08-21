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
 * moodleform for per-datatype admin overrides.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\output\forms;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/lib/formslib.php');

/**
 * Per-datatype admin configuration form.
 *
 * Lives under Site administration -> Plugins -> Local plugins -> IntelliStream ->
 * Datatype config -> Edit. Lets a site admin:
 *   - toggle an entity off,
 *   - override its SELECT column list (one column name per line), and
 *   - jot a note explaining why.
 *
 * The form is data-driven (datatype is a hidden POST field) and uses the
 * built-in Moodle save/cancel button row. Persistence happens in config/edit.php
 * via {@see \local_intellistream\repositories\config_repository::save()}.
 */
class local_intellistream_edit_config extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $data = isset($this->_customdata['data']) ? (object)$this->_customdata['data'] : new \stdClass();
        $datatype = isset($this->_customdata['datatype']) ? (string)$this->_customdata['datatype'] : '';
        $tablename = isset($this->_customdata['table']) ? (string)$this->_customdata['table'] : '';

        // Header: just so the operator sees which datatype/table they're touching.
        $heading = $datatype !== '' ? $datatype : get_string('datatype', 'local_intellistream');
        if ($tablename !== '') {
            $heading .= ' (mdl_' . $tablename . ')';
        }
        $mform->addElement('header', 'intellistreamheader', $heading);

        // Enabled.
        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_intellistream'));
        $mform->setType('enabled', PARAM_INT);
        $mform->setDefault('enabled', 1);

        // Custom columns override.
        $mform->addElement(
            'textarea',
            'custom_columns',
            get_string('custom_columns', 'local_intellistream'),
            ['rows' => 6, 'cols' => 60]
        );
        $mform->setType('custom_columns', PARAM_RAW);
        $mform->addHelpButton('custom_columns', 'custom_columns', 'local_intellistream');

        // Notes.
        $mform->addElement(
            'textarea',
            'notes',
            get_string('notes', 'local_intellistream'),
            ['rows' => 4, 'cols' => 60]
        );
        $mform->setType('notes', PARAM_RAW);

        // Hidden datatype identifier.
        $mform->addElement('hidden', 'datatype', $datatype);
        $mform->setType('datatype', PARAM_ALPHANUMEXT);

        $this->add_action_buttons(true, get_string('savechanges'));

        if (!empty($data)) {
            $this->set_data($data);
        }
    }
}
