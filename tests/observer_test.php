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

namespace local_intellistream;

/**
 * Tests for the capture payload's event_data normaliser.
 *
 * Moodle core puts FALSE in `objectid` for events that act on no object. The ETL
 * writes that field straight into a numeric column, where a boolean fails the
 * insert for the whole batch, degrades the table and holds its watermark
 * permanently — silently, because the table is not a core one. One such event
 * froze all activity tracking for two tenants (IBV2-1293).
 *
 * normalize_event_data() is pure, so it is testable without a database.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\observer::normalize_event_data
 */
final class observer_test extends \basic_testcase {
    /**
     * A real \tool_admin_presets\event\presets_listed payload — the event that
     * actually did this in production.
     */
    public function test_false_objectid_becomes_null(): void {
        $data = observer::normalize_event_data([
            'eventname' => '\\tool_admin_presets\\event\\presets_listed',
            'component' => 'tool_admin_presets',
            'action' => 'listed',
            'target' => 'presets',
            'objecttable' => 'adminpresets',
            'objectid' => false,
            'crud' => 'r',
            'edulevel' => 0,
            'contextid' => 1,
            'contextlevel' => 10,
            'contextinstanceid' => 0,
            'userid' => '60',
            'courseid' => 0,
            'relateduserid' => null,
            'anonymous' => 0,
            'other' => null,
            'timecreated' => 1789364999,
        ]);

        $this->assertNull($data['objectid']);
        $this->assertArrayHasKey('objectid', $data, 'the key must survive, only its value changes');
    }

    /**
     * Null, not 0 — 0 is a real-looking Moodle id, and it must match what the
     * ETL now stores for the same input.
     */
    public function test_false_does_not_become_zero(): void {
        $data = observer::normalize_event_data(['objectid' => false]);

        $this->assertNull($data['objectid']);
        $this->assertNotSame(0, $data['objectid']);
    }

    /**
     * Every numeric field carries the same exposure, not just objectid.
     */
    public function test_all_numeric_fields_are_guarded(): void {
        $fields = ['objectid', 'userid', 'courseid', 'relateduserid', 'contextid',
                   'contextlevel', 'contextinstanceid', 'edulevel', 'anonymous',
                   'timecreated'];

        foreach ($fields as $field) {
            $data = observer::normalize_event_data([$field => false]);
            $this->assertNull($data[$field], "$field was left as a boolean");

            $data = observer::normalize_event_data([$field => true]);
            $this->assertNull($data[$field], "$field was left as a boolean");
        }
    }

    /**
     * Real values must survive byte-for-byte, including the falsy ones and the
     * string ids Moodle emits depending on the site's DB driver.
     */
    public function test_real_values_are_untouched(): void {
        $input = [
            'objectid' => 3569,
            'userid' => '41',
            'courseid' => 0,
            'contextid' => 1,
            'contextlevel' => 10,
            'contextinstanceid' => 0,
            'edulevel' => 0,
            'anonymous' => 0,
            'relateduserid' => null,
            'timecreated' => 1783435180,
        ];

        $this->assertSame($input, observer::normalize_event_data($input));
    }

    /**
     * `other` is free-form, legitimately carries booleans, and lands in a text
     * column — it must not be walked.
     */
    public function test_other_is_left_alone(): void {
        $other = ['name' => 'endpoint', 'enabled' => false, 'nested' => ['flag' => true]];

        $data = observer::normalize_event_data(['objectid' => false, 'other' => $other]);

        $this->assertSame($other, $data['other']);
        $this->assertNull($data['objectid']);
    }

    /**
     * Absent keys are not invented — the ETL distinguishes a missing key from a
     * null one.
     */
    public function test_absent_keys_are_not_added(): void {
        $data = observer::normalize_event_data(['eventname' => '\\core\\event\\user_loggedin']);

        $this->assertSame(['eventname' => '\\core\\event\\user_loggedin'], $data);
        $this->assertArrayNotHasKey('objectid', $data);
    }

    /**
     * An empty payload is passed through rather than failing capture — this runs
     * on every page render.
     */
    public function test_empty_payload(): void {
        $this->assertSame([], observer::normalize_event_data([]));
    }
}
