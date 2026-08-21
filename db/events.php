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
 * Event observer registration for local_intellistream.
 *
 * A single observer on \core\event\base receives every event Moodle fires.
 * Moodle's event manager dispatches an event to observers registered on the
 * event's own class AND on any ancestor class, so \core\event\base catches all.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\base',
        'callback'  => '\local_intellistream\observer::capture',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\user_login_failed',
        'callback'  => '\local_intellistream\observers\exceptions_observer::user_login_failed',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\user_password_policy_failed',
        'callback'  => '\local_intellistream\observers\exceptions_observer::user_password_policy_failed',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\webservice_function_called',
        'callback'  => '\local_intellistream\observers\exceptions_observer::webservice_function_called',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\webservice_login_failed',
        'callback'  => '\local_intellistream\observers\exceptions_observer::webservice_login_failed',
        'internal'  => false,
    ],

    // Near-real-time capture of timestamp-less definition tables that
    // the 15-min incremental cannot carry (otherwise daily-snapshot-only). Each
    // handler snapshots the changed row into the buffer -> ships in ~1 min via
    // ship_events. All hot-path safe (see observers\entity_observer). Moodle
    // silently ignores any event class absent on a given Moodle/module version.
    // Deletes are NOT observed here — removals reconcile via the daily census.
    [
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\local_intellistream\observers\entity_observer::course_module_created',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback'  => '\local_intellistream\observers\entity_observer::course_module_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback'  => '\local_intellistream\observers\entity_observer::course_completed',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_completion_updated',
        'callback'  => '\local_intellistream\observers\entity_observer::course_completion_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\user_created',
        'callback'  => '\local_intellistream\observers\entity_observer::user_created',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\user_updated',
        'callback'  => '\local_intellistream\observers\entity_observer::user_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\user_info_field_created',
        'callback'  => '\local_intellistream\observers\entity_observer::user_info_field_created',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\user_info_field_updated',
        'callback'  => '\local_intellistream\observers\entity_observer::user_info_field_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\user_info_category_created',
        'callback'  => '\local_intellistream\observers\entity_observer::user_info_category_created',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\user_info_category_updated',
        'callback'  => '\local_intellistream\observers\entity_observer::user_info_category_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\grade_letter_created',
        'callback'  => '\local_intellistream\observers\entity_observer::grade_letter_created',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\grade_letter_updated',
        'callback'  => '\local_intellistream\observers\entity_observer::grade_letter_updated',
        'internal'  => false,
    ],
];
