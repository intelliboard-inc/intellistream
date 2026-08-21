<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Hook callback registrations for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Moodle 4.3+ reads this file; 4.1/4.2 (the plugin's declared floor) ignore it and
// fall back to the before_footer callback in lib.php. Registering the hook is what
// makes core STOP calling that legacy callback, which is why the two never both run
// — see \local_intellistream\hook_callbacks for the full note.
$callbacks = [
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_intellistream\hook_callbacks::class, 'before_footer_html_generation'],
        // Capture only; nothing else in the plugin depends on ordering.
        'priority' => 0,
    ],
];
