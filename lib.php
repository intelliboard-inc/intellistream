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
 * Library callbacks for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Footer callback: inject the page-dwell capture AMD module.
 *
 * LEGACY PATH, kept for Moodle 4.1/4.2 only — those predate the hook system, so
 * db/hooks.php is never read there and this is the only injection point that
 * works. From 4.3 core ignores this callback because the plugin registers
 * \core\hook\output\before_footer_html_generation instead, so the two never both
 * run. Both delegate to the same implementation; see
 * \local_intellistream\hook_callbacks.
 *
 * @return void
 */
function local_intellistream_before_footer() {
    \local_intellistream\hook_callbacks::inject_dwell();
}

/**
 * Add the IntelliBoard LTI dashboard node to the navigation (under My Profile).
 *
 * The node is shown only when the LTI tool URL is configured and the current
 * user holds local/intellistream:viewlti (see custom_menu_helper::setup). Any
 * failure is swallowed: navigation must never break a page render.
 *
 * @param \global_navigation $nav
 * @return void
 */
function local_intellistream_extend_navigation(\global_navigation $nav) {
    try {
        (new \local_intellistream\helpers\custom_menu_helper())->setup($nav);
    } catch (\Throwable $e) {
        return;
    }
}

/**
 * FontAwesome icon map for the plugin's pix icons.
 *
 * @return string[]
 */
function local_intellistream_get_fontawesome_icon_map() {
    return [
        'local_intellistream:i/area_chart' => 'fa-area-chart',
    ];
}
