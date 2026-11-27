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
 * Output renderer for local_intellistream (LTI launch).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\output;

use plugin_renderer_base;

/**
 * Standard HTML output renderer for local_intellistream.
 */
class renderer extends plugin_renderer_base {
    /**
     * Render the embedded "View LTI" page (the iframe wrapper).
     *
     * @param lti_view $page
     * @return string HTML
     */
    public function render_lti_view(\local_intellistream\output\lti_view $page) {
        return $this->render_from_template(
            'local_intellistream/lti_view',
            $page->export_for_template($this)
        );
    }

    /**
     * Render the auto-submitting "Launch LTI" form.
     *
     * @param lti_launch $page
     * @return string HTML
     */
    public function render_lti_launch(\local_intellistream\output\lti_launch $page) {
        return $this->render_from_template(
            'local_intellistream/lti_launch',
            $page->export_for_template($this)
        );
    }
}
