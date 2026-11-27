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
 * Filter form for the operational log page.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

use local_intellistream\services\log_service;

/**
 * Filter form for logs.php: type and action from fixed lists, datatype as text.
 *
 * The form posts; logs.php then redirects to a plain URL that carries only the
 * filter values, so a filtered page can be bookmarked. The fields are named
 * logtype, logdatatype and logaction: a field named "action" would hide the
 * form's own action property from JavaScript.
 */
class log_filter extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $any = ['' => get_string('log_filter_any', 'local_intellistream')];

        $mform->addElement(
            'select',
            'logtype',
            get_string('log_filter_type', 'local_intellistream'),
            $any + self::labels(log_service::types(), [log_service::class, 'type_label'])
        );
        $mform->setType('logtype', PARAM_ALPHANUMEXT);

        $mform->addElement('text', 'logdatatype', get_string('datatype', 'local_intellistream'), ['size' => 20]);
        $mform->setType('logdatatype', PARAM_ALPHANUMEXT);

        $mform->addElement(
            'select',
            'logaction',
            get_string('action', 'local_intellistream'),
            $any + self::labels(log_service::actions(), [log_service::class, 'action_label'])
        );
        $mform->setType('logaction', PARAM_ALPHANUMEXT);

        $this->add_action_buttons(false, get_string('filter', 'local_intellistream'));
    }

    /**
     * Select options: each value with its display name.
     *
     * @param string[] $values
     * @param callable $label Returns the display name of one value.
     * @return array value => display name
     */
    private static function labels(array $values, callable $label): array {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = $label($value);
        }
        return $options;
    }
}
