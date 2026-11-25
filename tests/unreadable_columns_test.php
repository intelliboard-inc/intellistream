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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/unreadable_columns_exporter.php');

/**
 * Tests that a whole-row entity whose columns cannot be read is never exported whole.
 *
 * Its credential columns cannot be checked, so it is left out, and the registry that
 * left it out is reused only briefly.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\exporter::registry_with_overrides
 */
final class unreadable_columns_test extends \advanced_testcase {
    /**
     * Widen the built-ins so `forum` is a whole-row entry.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('informfullrow', 1, 'local_intellistream');
        exporter::reset_registry_cache();
    }

    /**
     * Drop the memoised registry.
     */
    protected function tearDown(): void {
        unreadable_columns_exporter::$unreadable = 'forum';
        exporter::reset_registry_cache();
        parent::tearDown();
    }

    /**
     * The entity is left out and the reason is logged.
     */
    public function test_entity_is_left_out_and_logged(): void {
        global $DB;
        $registry = unreadable_columns_exporter::registry_with_overrides();
        $this->assertArrayNotHasKey('forum', $registry);
        $this->assertArrayHasKey('course', $registry);
        $this->assertTrue($DB->record_exists('local_intellistream_logs', [
            'datatype' => 'forum', 'action' => 'columnsunreadable',
        ]));
    }

    /**
     * The registry that left it out is reused within the retry window, not rebuilt per call.
     */
    public function test_incomplete_registry_is_reused_briefly(): void {
        global $DB;
        unreadable_columns_exporter::registry_with_overrides();
        unreadable_columns_exporter::registry_with_overrides();
        $this->assertSame(1, $DB->count_records('local_intellistream_logs', ['action' => 'columnsunreadable']));

        // Once the columns can be read again, the build after the retry time brings it back.
        unreadable_columns_exporter::$unreadable = '';
        $this->assertArrayNotHasKey('forum', unreadable_columns_exporter::registry_with_overrides());
        $retryat = new \ReflectionProperty(exporter::class, 'registryretryat');
        $retryat->setAccessible(true);
        $retryat->setValue(null, time() - 1);
        $this->assertSame('*', unreadable_columns_exporter::registry_with_overrides()['forum']['columns']);
    }

    /**
     * A lasting failure is logged at most once an hour per entity, however often the registry is built.
     */
    public function test_log_is_throttled_across_builds(): void {
        global $DB;
        for ($i = 0; $i < 3; $i++) {
            exporter::reset_registry_cache();
            unreadable_columns_exporter::registry_with_overrides();
        }
        $this->assertSame(1, $DB->count_records('local_intellistream_logs', ['action' => 'columnsunreadable']));
    }
}
