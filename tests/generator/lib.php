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
 * Test data generator for local_intellistream.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Test data generator for local_intellistream.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_intellistream_generator extends component_generator_base {
    /**
     * Create one operational log entry.
     *
     * @param array $record Fields: type, datatype, action, details, timecreated.
     * @return stdClass The stored row.
     */
    public function create_log(array $record): stdClass {
        global $DB;
        $row = (object)[
            'type'        => $record['type'] ?? 'ship',
            'datatype'    => $record['datatype'] ?? '',
            'action'      => $record['action'] ?? '',
            'details'     => $record['details'] ?? '',
            'timecreated' => isset($record['timecreated']) ? (int)$record['timecreated'] : time(),
        ];
        $row->id = $DB->insert_record('local_intellistream_logs', $row);
        return $row;
    }
}
