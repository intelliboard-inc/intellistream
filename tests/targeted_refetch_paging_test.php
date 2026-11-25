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
 * Tests for the paged read of a targeted re-fetch.
 *
 * A re-fetch reads each table in pages by id, so a large table never needs its whole
 * result in memory at once (on MySQL, Moodle holds a whole result in memory). Every
 * row is sent once, in id order, for a time window and for a whole table.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\exporter::export_entity_range
 * @covers     \local_intellistream\exporter::export_entity
 */
final class targeted_refetch_paging_test extends \advanced_testcase {
    /**
     * Pair the site, use the smallest page, and pin the site's own users outside every
     * window the tests use.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $DB->set_field_select('user', 'timemodified', 1000000000, 'id > 0');
        buffer::flush();
        set_config('siteid', 'phpunit-refetch-site', 'local_intellistream');
        set_config('pagerowsmax', exporter::PAGE_ROWS_MIN, 'local_intellistream');
        exporter::reset_instance_name_cache();
    }

    /**
     * Release this process's buffer handle, whatever a test left open.
     */
    protected function tearDown(): void {
        buffer::flush();
        exporter::reset_instance_name_cache();
        parent::tearDown();
    }

    /**
     * Ids of the rows of $entity buffered since the last call, consuming the files.
     *
     * @param string $entity
     * @return int[] In buffer order, duplicates kept.
     */
    private function sent(string $entity): array {
        buffer::flush();
        $ids = [];
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (
                    is_array($rec) && ($rec['entity'] ?? '') === $entity
                        && ($rec['record_type'] ?? '') === 'entity_snapshot'
                ) {
                    $ids[] = (int)$rec['entity_data']['id'];
                }
            }
            unlink($path);
        }
        return $ids;
    }

    /**
     * Users that span more than two pages, modified at $time.
     *
     * @param int $count
     * @param int $time
     * @return int[] Their ids, ascending.
     */
    private function make_users(int $count, int $time): array {
        global $DB;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $DB->set_field('user', 'timemodified', $time, ['id' => $user->id]);
            $ids[] = (int)$user->id;
        }
        $this->sent('user'); // The observers buffered the creations; drop them.
        sort($ids);
        return $ids;
    }

    /**
     * Run export_entity_range() with its output captured.
     *
     * @param string $entity
     * @param int|null $from
     * @param int|null $to
     * @param bool $stoponrefusal
     * @return array [rows, database reads, pages that exported rows]
     */
    private function refetch(string $entity, ?int $from, ?int $to, bool $stoponrefusal = true): array {
        global $DB;
        $reads = $DB->perf_get_reads();
        ob_start();
        try {
            $rows = exporter::export_entity_range($entity, 'phpunit-batch', null, $from, $to, $stoponrefusal);
        } finally {
            buffer::flush();
            $out = (string)ob_get_clean();
        }
        $pages = preg_match_all('/: ' . preg_quote($entity, '/') . ' — [1-9][0-9]* rows exported/u', $out);
        return [$rows, $DB->perf_get_reads() - $reads, $pages];
    }

    /**
     * A time window larger than one page is read in pages: every row in the window
     * once, in id order, and no row outside it.
     */
    public function test_window_is_read_in_pages(): void {
        $inside = $this->make_users(2 * exporter::PAGE_ROWS_MIN + 50, 1500000000);
        $outside = $this->make_users(20, 1600000000);

        [$rows, , $pages] = $this->refetch('user', 1400000000, 1550000000);

        $sent = $this->sent('user');
        $this->assertSame($inside, $sent, 'every row in the window once, in id order');
        $this->assertSame([], array_values(array_intersect($outside, $sent)), 'no row outside the window');
        $this->assertSame(count($inside), $rows);
        $this->assertSame(3, $pages, 'three pages, not one read');
    }

    /**
     * Without a window the whole table is read, in pages as well.
     */
    public function test_whole_table_is_read_in_pages(): void {
        global $DB;
        $this->make_users(2 * exporter::PAGE_ROWS_MIN + 50, 1500000000);
        $all = array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {user} ORDER BY id'));

        [$rows, , $pages] = $this->refetch('user', null, null);

        $this->assertSame($all, $this->sent('user'));
        $this->assertSame(count($all), $rows);
        $this->assertSame(3, $pages, 'three pages, not one read');
    }

    /**
     * A paged whole-table read keeps the map-cached activity names: one read per page
     * and per module table, not one per course module.
     */
    public function test_whole_table_pages_keep_the_name_map(): void {
        $course = $this->getDataGenerator()->create_course();
        // Page 2 holds 60 rows: a read per row there would be 60 reads.
        for ($i = 0; $i < exporter::PAGE_ROWS_MIN + 60; $i++) {
            $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        }
        $this->sent('course_modules');

        [$rows, $reads] = $this->refetch('course_modules', null, null);

        $this->assertSame(exporter::PAGE_ROWS_MIN + 60, $rows);
        $this->assertLessThan(40, $reads, 'names come from the map on every page, not a read per row');
    }

    /**
     * A refusal the caller stops on ends the read at its first page. Without a stop,
     * the read moves past refused rows page by page to the end of the window, and ends.
     */
    public function test_refusal_stops_or_pages_on(): void {
        $users = $this->make_users(2 * exporter::PAGE_ROWS_MIN + 50, 1500000000);
        // An unpaired site: the buffer refuses every record for now, and keeps no state.
        set_config('siteid', '', 'local_intellistream');

        $before = buffer::transient_refusals();
        [$stoprows] = $this->refetch('user', 1400000000, 1550000000, true);
        $stopped = buffer::transient_refusals() - $before;
        $before = buffer::transient_refusals();
        [$onrows] = $this->refetch('user', 1400000000, 1550000000, false);
        $paged = buffer::transient_refusals() - $before;

        $this->assertSame(0, $stoprows);
        $this->assertSame(1, $stopped, 'the stop ended the read at the first refused row');
        $this->assertSame(0, $onrows);
        $this->assertSame(count($users), $paged, 'every row of every page was offered, once');
    }
}
