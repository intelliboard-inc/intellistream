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

namespace local_intellistream;

/**
 * Tests that change_ledger::unconfirmed() counts entities, not rows.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\change_ledger
 */
final class change_ledger_unconfirmed_test extends \advanced_testcase {
    /**
     * Add one ledger row.
     *
     * @param string $entity
     * @param string $kind
     * @param int $part
     * @return void
     */
    private function add_row(string $entity, string $kind, int $part): void {
        global $DB;
        $DB->insert_record(change_ledger::TABLE, (object)[
            'fingerprint'  => str_repeat('a', 64),
            'entity'       => $entity,
            'kind'         => $kind,
            'part'         => $part,
            'passstart'    => 0,
            'blocks'       => '',
            'timemodified' => 0,
        ]);
    }

    /**
     * Three suspect rows across two entities count as two; other kinds do not count.
     */
    public function test_unconfirmed_counts_distinct_suspect_entities(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records(change_ledger::TABLE);

        $this->add_row('user', change_ledger::SUSPECT, 0);
        $this->add_row('user', change_ledger::SUSPECT, 20000);
        $this->add_row('course', change_ledger::SUSPECT, 0);
        $this->add_row('grade_grades', change_ledger::COMMITTED, 0);

        $this->assertSame(2, change_ledger::unconfirmed());
    }

    /**
     * An empty ledger has no unconfirmed entities.
     */
    public function test_unconfirmed_is_zero_on_an_empty_ledger(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records(change_ledger::TABLE);

        $this->assertSame(0, change_ledger::unconfirmed());
    }
}
