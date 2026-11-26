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

namespace local_intellistream\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;

/**
 * Tests for the privacy metadata the plugin declares.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\privacy\provider::get_metadata
 */
final class metadata_test extends \advanced_testcase {
    /**
     * Collect the declared items by name.
     *
     * @return array name => item
     */
    private function items(): array {
        $collection = provider::get_metadata(new collection('local_intellistream'));
        $items = [];
        foreach ($collection->get_collection() as $item) {
            $items[$item->get_name()] = $item;
        }
        return $items;
    }

    /**
     * Every summary and field string of every declared item exists.
     */
    public function test_every_metadata_string_exists(): void {
        $sm = get_string_manager();
        foreach ($this->items() as $name => $item) {
            $this->assertTrue($sm->string_exists($item->get_summary(), 'local_intellistream'), $name);
            foreach ($item->get_privacy_fields() as $field => $identifier) {
                $this->assertTrue($sm->string_exists($identifier, 'local_intellistream'), "{$name}:{$field}");
            }
        }
    }

    /**
     * The events, entities and pull export routes declare the data they carry.
     */
    public function test_declared_routes_and_fields(): void {
        $items = $this->items();

        $this->assertInstanceOf(external_location::class, $items['intellistream_pull']);
        $this->assertArrayHasKey('records', $items['intellistream_pull']->get_privacy_fields());

        $events = $items['intellistream_events']->get_privacy_fields();
        $this->assertArrayHasKey('userid', $events);
        $this->assertArrayHasKey('relateduserid', $events);

        $entities = $items['intellistream_entities']->get_privacy_fields();
        foreach (['useridentity', 'profilefields', 'filemetadata', 'usercontent'] as $field) {
            $this->assertArrayHasKey($field, $entities);
        }
    }
}
