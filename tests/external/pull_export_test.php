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

namespace local_intellistream\external;

use local_intellistream\buffer;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\local_intellistream\external\pull_export::class)]
/**
 * Tests for the local_intellistream_pull_export web service function.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\external\pull_export
 */
final class pull_export_test extends \advanced_testcase {
    /**
     * Pair the site and stage five records in one closed buffer file: three events,
     * one page dwell and one user row.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        set_config('siteid', 'phpunit-pull-site', 'local_intellistream');

        $records = [
            ['record_type' => 'event', 'event_data' => ['eventname' => '\\core\\event\\user_loggedin', 'userid' => 2]],
            ['record_type' => 'event', 'event_data' => ['eventname' => '\\core\\event\\course_viewed', 'userid' => 2]],
            ['record_type' => 'event', 'event_data' => ['eventname' => '\\core\\event\\user_loggedout', 'userid' => 2]],
            ['record_type' => 'page_dwell', 'dwell' => ['userid' => 2, 'url' => '/course/view.php?id=2', 'ms' => 5000]],
            ['record_type' => 'entity_snapshot', 'entity' => 'user', 'entity_data' => ['id' => 2]],
        ];
        foreach ($records as $i => $record) {
            // Capture gives every record a UUID; the fixture does the same.
            $record['id'] = sprintf('00000000-0000-4000-8000-%012d', $i + 1);
            $reason = null;
            $this->assertTrue(buffer::append_record($record, $reason), 'buffered, refusal: ' . var_export($reason, true));
        }
        buffer::flush();
    }

    /**
     * Call the function the way the web service layer does, and validate the result.
     *
     * @param int $limit Maximum records.
     * @param string[] $types Record type filter.
     * @return array The returned records.
     */
    private function pull(int $limit = pull_export::DEFAULT_LIMIT, array $types = []): array {
        // The first pull of a new site queues its historical backfill and reports that.
        // A web request reports it through debugging(); under PHPUnit it is printed.
        ob_start();
        try {
            $result = pull_export::execute('', $limit, $types);
        } finally {
            ob_end_clean();
        }
        return pull_export::clean_returnvalue(pull_export::execute_returns(), $result);
    }

    /**
     * The record types of the returned records, sorted.
     *
     * @param array $records Returned records.
     * @return string[]
     */
    private static function types(array $records): array {
        $types = array_column($records, 'record_type');
        sort($types);
        return $types;
    }

    /**
     * A user without the pull capability is refused.
     */
    public function test_requires_the_pull_capability(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        pull_export::execute();
    }

    /**
     * Every staged record is returned once, and a second pull returns nothing.
     */
    public function test_returns_every_record_once(): void {
        $this->setAdminUser();

        $records = $this->pull();
        $this->assertSame(['entity_snapshot', 'event', 'event', 'event', 'page_dwell'], self::types($records));
        $this->assertCount(5, array_unique(array_column($records, 'id')), 'each record once');
        foreach ($records as $record) {
            $this->assertIsArray(json_decode($record['data'], true), 'data is a JSON object');
        }

        $this->assertSame([], $this->pull(), 'delivered records are removed from the buffer');
    }

    /**
     * A pull stops at its limit, and the next pull continues with the rest.
     */
    public function test_limit_continues_on_the_next_pull(): void {
        $this->setAdminUser();

        $first = $this->pull(2);
        $this->assertCount(2, $first);
        $rest = $this->pull();
        $this->assertCount(3, $rest);

        $ids = array_merge(array_column($first, 'id'), array_column($rest, 'id'));
        $this->assertCount(5, array_unique($ids), 'no record is returned twice');
    }

    /**
     * A type filter returns only those records and keeps the others for a later pull.
     */
    public function test_type_filter_keeps_the_other_records(): void {
        $this->setAdminUser();

        $this->assertSame(['page_dwell'], self::types($this->pull(pull_export::DEFAULT_LIMIT, ['page_dwell'])));
        $this->assertSame(['entity_snapshot', 'event', 'event', 'event'], self::types($this->pull()));
    }

    /**
     * A filter that names no known type is treated as no filter.
     */
    public function test_unknown_type_filter_returns_every_record(): void {
        $this->setAdminUser();

        $this->assertCount(5, $this->pull(pull_export::DEFAULT_LIMIT, ['unknown_type']));
    }
}
