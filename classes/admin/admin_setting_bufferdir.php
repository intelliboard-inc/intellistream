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
 * Validated admin setting for the on-disk buffer directory.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\admin;

use local_intellistream\config;

/**
 * The `bufferdir` setting: a path inside moodledata, validated on save.
 *
 * Two reasons this is not a plain admin_setting_configtext:
 *
 *  - It validates. Without a validate() the setting accepted any string, and
 *    config::buffer_dir() silently fell back to the default for a bad one —
 *    so an operator got no feedback and their buffer quietly moved. Rejecting
 *    on save surfaces the mistake at the point it is made.
 *  - It is {@see locally_managed}, so the control webhook cannot write it.
 *    The uninstall purge acts on this path; the control plane has no reason to
 *    set it and must not be able to plant a destructive value.
 */
class admin_setting_bufferdir extends admin_setting_localonly_text {
    /**
     * Reject a path that must never hold the buffer.
     *
     * Delegates to config::buffer_dir_problem() so the save-time rule and the
     * read-time guard in config::buffer_dir() can never diverge.
     *
     * @param string $data Submitted value.
     * @return bool|string True when valid, else a localised error message.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        $problem = config::buffer_dir_problem((string)$data);
        if ($problem !== '') {
            return get_string($problem, config::COMPONENT);
        }

        return true;
    }
}
