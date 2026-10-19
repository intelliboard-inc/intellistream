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

use local_intellistream\repositories\config_repository;
use local_intellistream\services\dynamic_discovery_service;

/**
 * Tests for how table discovery treats a row an administrator added by hand.
 *
 * Discovery registers every table matching a configured prefix as a discovered row,
 * and a discovered row exports only while whole-row discovery export is switched on.
 * Re-marking a hand-added row as discovered would therefore stop a table the
 * administrator chose to export whenever that setting is off.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\services\dynamic_discovery_service
 */
final class discovery_register_test extends \advanced_testcase {
    /** A plugin table that is not a built-in entity, taken for the hand-added row. */
    private const HANDADDED = 'local_intellistream_logs';

    /** Another such table, with no row yet. */
    private const FRESH = 'local_intellistream_sweep';

    /**
     * Discovery matches both tables.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('dynamic_discovery_prefixes', self::HANDADDED . "\n" . self::FRESH, 'local_intellistream');
    }

    /**
     * A hand-added row is left exactly as it is; a table with no row is registered.
     */
    public function test_register_leaves_a_hand_added_row_alone(): void {
        global $DB;
        $registry = exporter::registry();
        $this->assertArrayNotHasKey(self::HANDADDED, $registry, 'fixture must not be a built-in');
        $this->assertArrayNotHasKey(self::FRESH, $registry, 'fixture must not be a built-in');

        $repo = new config_repository();
        $repo->save(self::HANDADDED, (object)[
            'enabled' => 1,
            'discovered' => 0,
            'custom_table' => self::HANDADDED,
            'custom_columns' => 'id, type',
            'notes' => 'added by an administrator',
        ]);
        $DB->set_field(config_repository::TABLE, 'timemodified', 12345, ['datatype' => self::HANDADDED]);
        $before = $DB->get_record(config_repository::TABLE, ['datatype' => self::HANDADDED]);

        $result = (new dynamic_discovery_service())->discover();

        $this->assertContains(self::HANDADDED, $result['tables'], 'the table was matched');
        $after = $DB->get_record(config_repository::TABLE, ['datatype' => self::HANDADDED]);
        $this->assertEquals($before, $after, 'the hand-added row is unchanged, timemodified included');

        $fresh = $DB->get_record(config_repository::TABLE, ['datatype' => self::FRESH]);
        $this->assertNotFalse($fresh, 'a matching table with no row is registered');
        $this->assertSame(1, (int)$fresh->discovered);
        $this->assertSame(self::FRESH, $fresh->custom_table);
    }

    /**
     * A row discovery wrote itself is re-asserted as discovered, and keeps its enabled flag.
     */
    public function test_register_reasserts_a_discovered_row(): void {
        global $DB;
        $repo = new config_repository();
        $repo->save(self::FRESH, (object)['enabled' => 0, 'discovered' => 1, 'custom_table' => self::FRESH]);

        (new dynamic_discovery_service())->discover();

        $row = $DB->get_record(config_repository::TABLE, ['datatype' => self::FRESH]);
        $this->assertSame(1, (int)$row->discovered);
        $this->assertSame(0, (int)$row->enabled, 'an administrator opt-out is kept');
    }
}
