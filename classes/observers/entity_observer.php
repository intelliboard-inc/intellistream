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
 * Entity event callbacks of the previous release.
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
class entity_observer {
    /**
     * Forwards to observer::course_module_created().
     *
     * @param \core\event\course_module_created $event
     * @return void
     */
    public static function course_module_created(\core\event\course_module_created $event): void {
        try {
            observer::course_module_created($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::course_module_updated().
     *
     * @param \core\event\course_module_updated $event
     * @return void
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        try {
            observer::course_module_updated($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::course_completed().
     *
     * @param \core\event\course_completed $event
     * @return void
     */
    public static function course_completed(\core\event\course_completed $event): void {
        try {
            observer::course_completed($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::course_completion_updated().
     *
     * @param \core\event\course_completion_updated $event
     * @return void
     */
    public static function course_completion_updated(\core\event\course_completion_updated $event): void {
        try {
            observer::course_completion_updated($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::user_created().
     *
     * @param \core\event\user_created $event
     * @return void
     */
    public static function user_created(\core\event\user_created $event): void {
        try {
            observer::user_created($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::user_updated().
     *
     * @param \core\event\user_updated $event
     * @return void
     */
    public static function user_updated(\core\event\user_updated $event): void {
        try {
            observer::user_updated($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::user_info_field_created().
     *
     * @param \core\event\user_info_field_created $event
     * @return void
     */
    public static function user_info_field_created(\core\event\user_info_field_created $event): void {
        try {
            observer::user_info_field_created($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::user_info_field_updated().
     *
     * @param \core\event\user_info_field_updated $event
     * @return void
     */
    public static function user_info_field_updated(\core\event\user_info_field_updated $event): void {
        try {
            observer::user_info_field_updated($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::user_info_category_created().
     *
     * @param \core\event\user_info_category_created $event
     * @return void
     */
    public static function user_info_category_created(\core\event\user_info_category_created $event): void {
        try {
            observer::user_info_category_created($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::user_info_category_updated().
     *
     * @param \core\event\user_info_category_updated $event
     * @return void
     */
    public static function user_info_category_updated(\core\event\user_info_category_updated $event): void {
        try {
            observer::user_info_category_updated($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::grade_letter_created().
     *
     * @param \core\event\grade_letter_created $event
     * @return void
     */
    public static function grade_letter_created(\core\event\grade_letter_created $event): void {
        try {
            observer::grade_letter_created($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Forwards to observer::grade_letter_updated().
     *
     * @param \core\event\grade_letter_updated $event
     * @return void
     */
    public static function grade_letter_updated(\core\event\grade_letter_updated $event): void {
        try {
            observer::grade_letter_updated($event);
        } catch (\Throwable $e) {
            debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
