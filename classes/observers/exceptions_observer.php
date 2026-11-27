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
 * Exception event callbacks of the previous release.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\observers;

use local_intellistream\observer;

/**
 * Forwards to \local_intellistream\observer.
 *
 * db/events.php registers \local_intellistream\observer for every event. Moodle
 * keeps calling the observers registered by the previous release until the
 * upgrade runs, so this class forwards those calls for one release.
 *
 * @deprecated since 0.9.35 - use \local_intellistream\observer. Removed in 0.9.36.
 */
class exceptions_observer {
    /**
     * Forwards to observer::user_login_failed().
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function user_login_failed($event): void {
        try {
            observer::user_login_failed($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::user_password_policy_failed().
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function user_password_policy_failed($event): void {
        try {
            observer::user_password_policy_failed($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::webservice_function_called().
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function webservice_function_called($event): void {
        try {
            observer::webservice_function_called($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::webservice_login_failed().
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function webservice_login_failed($event): void {
        try {
            observer::webservice_login_failed($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::reset_datatype_cache().
     *
     * @return void
     */
    public static function reset_datatype_cache(): void {
        try {
            observer::reset_datatype_cache();
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
