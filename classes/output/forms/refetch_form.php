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
 * moodleform for the targeted re-fetch admin page.
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
 * Targeted re-fetch form: a date/time window + a multi-select of entity types,
 * plus a "Run re-fetch now" button. On submit the page (refetch.php) queues a
 * {@see \local_intellistream\task\run_targeted_refetch_adhoc_task}.
 *
 * `entityoptions` custom data is a value=>label map of the effective registry
 * keys (see refetch.php).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refetch_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $entityoptions = $this->_customdata['entityoptions'] ?? [];

        $mform->addElement(
            'static',
            'intro',
            '',
            get_string('targeted_intro', 'local_intellistream')
        );

        // Window. date_time_selector submits a unix timestamp directly.
        $mform->addElement(
            'date_time_selector',
            'from',
            get_string('targeted_from', 'local_intellistream')
        );
        $mform->addRule('from', null, 'required', null, 'client');

        $mform->addElement(
            'date_time_selector',
            'to',
            get_string('targeted_to', 'local_intellistream')
        );
        $mform->addRule('to', null, 'required', null, 'client');

        // Entity types (multi-select autocomplete). Empty = all entities.
        $select = $mform->addElement(
            'autocomplete',
            'entities',
            get_string('targeted_entities', 'local_intellistream'),
            $entityoptions
        );
        $select->setMultiple(true);
        $mform->addElement(
            'static',
            'entities_hint',
            '',
            get_string('targeted_entities_desc', 'local_intellistream')
        );

        $this->add_action_buttons(false, get_string('targeted_run', 'local_intellistream'));
    }

    /**
     * Ensure the window is ordered (to >= from).
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        // Isset (not !empty): empty(0)===true would skip the check for a crafted
        // from=0/to=0 POST (the required rules are client-side only).
        if (isset($data['from']) && isset($data['to']) && (int)$data['to'] <= (int)$data['from']) {
            $errors['to'] = get_string('targeted_err_order', 'local_intellistream');
        }
        return $errors;
    }
}
