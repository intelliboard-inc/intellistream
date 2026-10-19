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
 * Central plugin-settings + capability accessors.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\helpers;

/**
 * Thin wrapper around get_config/set_config so callers don't have to remember
 * the `local_intellistream` component name, plus capability-name constants used
 * by the admin pages and the operational log viewer.
 */
class settings_helper {
    /** Component name (matches version.php->component). */
    const COMPONENT = 'local_intellistream';

    /** Capability: edit per-datatype configuration overrides. */
    const CAP_MANAGE = 'local/intellistream:manage';

    /** Capability: view the operational log feed. */
    const CAP_VIEW_LOGS = 'local/intellistream:viewlogs';

    /** Capability: view the status page (pre-existing). */
    const CAP_VIEW_STATUS = 'local/intellistream:viewstatus';

    /**
     * Read a plugin setting.
     *
     * @param string $key
     * @param mixed  $default Returned when the setting is unset or empty string.
     * @return mixed
     */
    public static function get_setting(string $key, $default = null) {
        $value = get_config(self::COMPONENT, $key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    /**
     * Write a plugin setting.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public static function set_setting(string $key, $value): void {
        set_config($key, $value, self::COMPONENT);
    }
}
