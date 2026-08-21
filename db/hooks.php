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

// Moodle 4.3+ reads this file; older supported releases ignore it and fall back to
// the equivalent legacy callback in lib.php. The footer hook declares
// `replaces_callbacks`, so core stops calling local_intellistream_before_footer by
// itself and the two never both run. See \local_intellistream\hook_callbacks.
//
// The menu-bar entry is NOT registered here. It was, through
// \core\hook\navigation\primary_extend, and that hook does not dispatch under a
// theme which builds its menu bar from $CFG->custommenuitems instead of the primary
// navigation view — so the entry went missing on exactly those sites. See
// \local_intellistream\helpers\custom_menu_helper for the whole account.
$callbacks = [
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_intellistream\hook_callbacks::class, 'before_footer_html_generation'],
        // Capture only; nothing else in the plugin depends on ordering.
        'priority' => 0,
    ],
];
