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

/**
 * Tests for the incremental lane on a decimal change column.
 *
 * An admin can register any table as a custom entity, and some keep their change
 * time in a decimal column. A mark truncated to whole seconds there re-ships the
 * rows of the last second every run, and a mark rounded up skips rows. The mark
 * must be the exact value of the newest row read.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\exporter::export_incremental
 */
final class watermark_decimal_test extends \advanced_testcase {
    /** The custom table and entity name. */
    private const TABLE = 'lis_phpunit_wmdecimal';

    /**
     * Pair the site, create the table and register it as a hand-added entity.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        set_config('siteid', 'phpunit-wmdecimal-site', 'local_intellistream');
        set_config('backfillgate', 0, 'local_intellistream');

        $table = new \xmldb_table(self::TABLE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '32', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_NUMBER, '20, 10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_temp_table($table);
        // Creating a temporary table does not refresh the connection's cached table
        // list, which is what the plugin's table-presence check reads; a real custom
        // table exists before the request starts, so refresh it the way that would.
        $DB->get_tables(false);

        // Hand-added (discovered=0), so no discovery opt-in is involved.
        (new config_repository())->save(self::TABLE, (object)[
            'enabled' => 1,
            'discovered' => 0,
            'custom_table' => self::TABLE,
        ]);
    }

    /**
     * Drop the table and release the buffer handle.
     */
    protected function tearDown(): void {
        global $DB;
        buffer::flush();
        $table = new \xmldb_table(self::TABLE);
        if ($DB->get_manager()->table_exists($table)) {
            $DB->get_manager()->drop_table($table);
        }
        exporter::reset_registry_cache();
        parent::tearDown();
    }

    /**
     * The lane's stored mark for the entity.
     *
     * @return mixed
     */
    private function mark() {
        $method = new \ReflectionMethod(exporter::class, 'cdc_state');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $state = $method->invoke(null);
        return $state['wm'][self::TABLE] ?? null;
    }

    /**
     * Run the lane once; the names of this entity's rows it buffered.
     *
     * @return string[] Sorted.
     */
    private function run_lane(): array {
        ob_start();
        try {
            exporter::export_incremental();
        } finally {
            buffer::flush();
            ob_end_clean();
        }
        $names = [];
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (is_array($rec) && ($rec['entity'] ?? '') === self::TABLE) {
                    $names[] = (string)$rec['entity_data']['name'];
                }
            }
            unlink($path);
        }
        sort($names);
        return $names;
    }

    /**
     * The mark is the exact newest decimal read; an idle run ships nothing; a row just
     * above the mark ships alone.
     */
    public function test_decimal_mark_is_exact(): void {
        global $DB;
        $this->assertArrayHasKey(self::TABLE, exporter::registry_with_overrides(), 'the custom entity is registered');

        $DB->insert_record(self::TABLE, (object)['name' => 'a', 'timemodified' => 1000.5]);
        $DB->insert_record(self::TABLE, (object)['name' => 'b', 'timemodified' => 1000.25]);

        $this->assertSame(['a', 'b'], $this->run_lane());
        $this->assertNotNull($this->mark());
        $this->assertEqualsWithDelta(1000.5, (float)$this->mark(), 1e-9, 'the mark is the newest row, not rounded');

        $this->assertSame([], $this->run_lane(), 'an idle run ships nothing');
        $this->assertEqualsWithDelta(1000.5, (float)$this->mark(), 1e-9);

        $DB->insert_record(self::TABLE, (object)['name' => 'c', 'timemodified' => 1000.75]);
        $this->assertSame(['c'], $this->run_lane(), 'only the row above the mark');
        $this->assertEqualsWithDelta(1000.75, (float)$this->mark(), 1e-9);
    }
}
