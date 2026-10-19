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
 * Behat data generator for local_intellistream.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator for local_intellistream.
 *
 * Use as: Given the following "local_intellistream > logs" exist:
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_intellistream_generator extends behat_generator_base {
    /**
     * The entities this generator can create.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'logs' => [
                'singular' => 'log',
                'datagenerator' => 'log',
                'required' => ['type'],
            ],
        ];
    }
}
