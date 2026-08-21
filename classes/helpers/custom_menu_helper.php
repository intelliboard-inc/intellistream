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
 * Navigation setup for the embedded IntelliBoard dashboard link.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\helpers;

use local_intellistream\config;

/**
 * Adds the dashboard link to the navigation tree.
 *
 * Navigation only. It writes nothing and mutates no core configuration: an
 * earlier version appended the link to $CFG->custommenuitems on every page
 * render, because core builds the theme menu from that value and offers no
 * per-request injection API. A site that wants the link in the menu bar adds it
 * under Appearance > Advanced theme settings > Custom menu items.
 */
class custom_menu_helper {
    /**
     * Add the dashboard link to the navigation, for a user who may see it.
     *
     * @param \global_navigation $nav
     * @return void
     */
    public function setup($nav) {
        global $PAGE;

        try {
            $context = \context_system::instance();
            if (
                !isloggedin()
                || config::lti_tool_url() === ''
                || !has_capability('local/intellistream:viewlti', $context)
            ) {
                // Nothing to add, so touch nothing. The two navigation mutations
                // below used to run BEFORE this test, on every page render for
                // every user, whether or not the dashboard link was configured or
                // permitted. extend_navigation() is called from
                // global_navigation::initialise(), and make_inactive() recurses to
                // the navigation root clearing active_tree_node — so on a profile
                // page this plugin de-highlighted the whole active branch of
                // core's navigation while its own feature was switched off.
                return;
            }

            $mynode = $PAGE->navigation->find('myprofile', \navigation_node::TYPE_ROOTNODE);
            if (!$mynode) {
                // Navigation find() returns false when the node is absent (some
                // pages and themes have no myprofile root), and it was previously
                // dereferenced blind.
                return;
            }
            $mynode->collapse = true;
            $mynode->make_inactive();

            $name = config::lti_title();
            $url = new \moodle_url('/local/intellistream/lti.php');
            $nav->add($name, $url);
            $icon = new \pix_icon('i/area_chart', '', 'local_intellistream');
            $node = $mynode->add($name, $url, 0, null, 'intellistream_lti', $icon);
            $node->showinflatnavigation = true;
        } catch (\Throwable $e) {
            debugging('local_intellistream: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
