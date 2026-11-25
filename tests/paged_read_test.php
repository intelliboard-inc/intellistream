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
 * Tests for the paged reads of the incremental lane and the historical backfill.
 *
 * On MySQL, Moodle copies the whole result of a query into memory before the first
 * row, so one unbounded read of a large table failed on memory_limit. Both readers
 * now read `... ORDER BY id LIMIT n` pages. These tests hold the paged reads to the
 * rows, the watermarks and the resume points of a single read, and the lane to
 * reading nothing while the buffer is full.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\exporter::export_incremental
 * @covers     \local_intellistream\exporter::export_entity_window
 * @covers     \local_intellistream\exporter::next_page_rows
 * @covers     \local_intellistream\exporter::page_window_bound
 * @covers     \local_intellistream\exporter::lane_has_room
 * @covers     \local_intellistream\exporter::seed_incremental_watermark
 * @covers     \local_intellistream\exporter::window_first_id
 * @covers     \local_intellistream\exporter::open_window_mark
 * @covers     \local_intellistream\exporter::window_continues
 * @covers     \local_intellistream\exporter::export_census
 */
final class paged_read_test extends \advanced_testcase {
    /**
     * A fixed change time for the site's own users (admin and guest).
     *
     * They are stamped when the PHPUnit site is installed, which may be minutes or
     * days before a run; the tests below need them well below every window.
     *
     * @var int
     */
    private const SITE_USERS_STAMP = 1000000000;

    /**
     * Pair the site, release the new-install hold, use the smallest page, and pin
     * the site's own users below every window.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $DB->set_field_select('user', 'timemodified', self::SITE_USERS_STAMP, 'id > 0');
        buffer::flush();
        set_config('siteid', 'phpunit-paged-site', 'local_intellistream');
        set_config('endpoint', 'https://s3.example.com', 'local_intellistream');
        set_config('bucket', 'phpunit-bucket', 'local_intellistream');
        set_config('accesskey', 'phpunit-access', 'local_intellistream');
        set_config('secretkey', 'phpunit-secret', 'local_intellistream');
        set_config('backfillgate', 0, 'local_intellistream');
        set_config('pagerowsmax', exporter::PAGE_ROWS_MIN, 'local_intellistream');
    }

    /**
     * Release this process's buffer handle, whatever a test left open.
     */
    protected function tearDown(): void {
        buffer::flush();
        parent::tearDown();
    }

    /**
     * Call a private static method of the exporter.
     *
     * @param string $name
     * @param array $args
     * @return mixed
     */
    private function call_exporter(string $name, array $args = []) {
        $method = new \ReflectionMethod(exporter::class, $name);
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        return $method->invokeArgs(null, $args);
    }

    /**
     * The lane's state for `user`: watermark and frozen window.
     *
     * @return array [watermark|null, resume|null]
     */
    private function user_state(): array {
        $state = $this->call_exporter('cdc_state');
        return [$state['wm']['user'] ?? null, $state['resume']['user'] ?? null];
    }

    /**
     * Ids of an entity's rows buffered since the last call, consuming the files.
     *
     * @param string $entity Registry key; `user` by default.
     * @return int[] In buffer order, duplicates kept.
     */
    private function shipped_users(string $entity = 'user'): array {
        buffer::flush();
        $ids = [];
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (
                    is_array($rec) && ($rec['record_type'] ?? '') === 'entity_snapshot'
                        && ($rec['entity'] ?? '') === $entity
                ) {
                    $ids[] = (int)$rec['entity_data']['id'];
                }
            }
            unlink($path);
        }
        return $ids;
    }

    /**
     * Run the incremental lane once.
     *
     * @return string Its trace output.
     */
    private function run_lane(): string {
        ob_start();
        try {
            exporter::export_incremental();
        } finally {
            buffer::flush();
            $out = ob_get_clean();
        }
        return (string)$out;
    }

    /**
     * Create users whose change time is well before the paged window's clock bound.
     *
     * @param int $count
     * @param int $at Base change time; user $i gets $at + $i.
     * @return int[] Their ids, ascending.
     */
    private function make_users(int $count, int $at): array {
        global $DB;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $DB->set_field('user', 'timemodified', $at + $i, ['id' => $user->id]);
            $ids[] = (int)$user->id;
        }
        sort($ids);
        // Creating a user fires events the observers buffer; drop them, so each test
        // sees only what the reader under test buffered.
        $this->shipped_users();
        return $ids;
    }

    /**
     * A window larger than a page is read in pages within one run: every row once,
     * the watermark at the window's bound, no frozen window left, and the next run
     * reads nothing.
     */
    public function test_paged_lane_reads_every_row_once(): void {
        $at = time() - 50000;
        $users = $this->make_users(2 * exporter::PAGE_ROWS_MIN + 50, $at);

        $out = $this->run_lane();
        $shipped = $this->shipped_users();
        foreach ($users as $id) {
            $this->assertContains($id, $shipped, "user {$id} is read");
        }
        $this->assertSame(count($shipped), count(array_unique($shipped)), 'no row is read twice');
        $this->assertStringNotContainsString('HELD', $out);

        [$wm, $resume] = $this->user_state();
        $this->assertNull($resume, 'the paged window was completed in the run');
        $this->assertSame($at + count($users) - 1, (int)$wm, 'the mark is the highest mark read');
        $this->assertLessThanOrEqual(time() - exporter::PAGE_CLOCK_SKEW_SEC, (int)$wm);

        $this->run_lane();
        $this->assertSame([], $this->shipped_users(), 'nothing changed, nothing is read');
    }

    /**
     * The page size an entity last read at is where its next run starts.
     */
    public function test_page_size_is_carried_to_the_next_run(): void {
        set_config('pagerowsmax', exporter::PAGE_ROWS_MAX, 'local_intellistream');
        $this->make_users(8 * exporter::PAGE_ROWS_MIN, time() - 50000);

        $this->run_lane();
        $this->shipped_users();
        $state = $this->call_exporter('cdc_state');
        $this->assertGreaterThan(exporter::PAGE_ROWS_MIN, (int)($state['page']['user'] ?? 0), 'a grown size is kept');
    }

    /**
     * One page per run (budget 0): the window is frozen and resumed run by run, and a
     * row changed after it was read, or before it was reached, is read again in the
     * window after. No row is lost.
     */
    public function test_row_changed_between_pages_is_not_lost(): void {
        global $DB;
        set_config('pagebudgetsec', 0, 'local_intellistream');
        $users = $this->make_users(2 * exporter::PAGE_ROWS_MIN + 50, time() - 50000);

        $this->run_lane();
        $first = $this->shipped_users();
        [, $resume] = $this->user_state();
        $this->assertNotNull($resume, 'one page read, the window is frozen');
        $this->assertSame(exporter::PAGE_ROWS_MIN, count($first));

        // One row already read, one not reached yet: both change now.
        $readone = $first[5];
        $later = end($users);
        $this->assertNotContains($later, $first);
        $DB->set_field('user', 'timemodified', time(), ['id' => $readone]);
        $DB->set_field('user', 'timemodified', time(), ['id' => $later]);

        $seen = $first;
        for ($run = 0; $run < 10; $run++) {
            $this->run_lane();
            $seen = array_merge($seen, $this->shipped_users());
            [, $resume] = $this->user_state();
            if ($resume === null) {
                break;
            }
        }
        $this->assertNull($resume, 'the frozen window completes');
        foreach ($users as $id) {
            if ($id !== $later) {
                $this->assertContains($id, $seen, "user {$id} is read");
            }
        }
        // Changed before the pass reached it, so it is above the bound: the frozen
        // window does not read it, the window after does (below).
        $this->assertNotContains($later, array_slice($seen, count($first)));

        // The window completes at the highest mark it settled, below both changes, so
        // the window after reads them as they are.
        [$wm] = $this->user_state();
        foreach ([$readone, $later] as $id) {
            $this->assertGreaterThan((int)$wm, (int)$DB->get_field('user', 'timemodified', ['id' => $id]));
        }
        $this->run_lane();
        $next = $this->shipped_users();
        $this->assertContains($readone, $next, 'a row changed after it was read is read again');
        $this->assertContains($later, $next, 'a row changed before it was reached is read');
    }

    /**
     * A row stamped late below the window's bound (a web node whose clock is behind,
     * a replica, a long transaction) after the read passed its id is not stepped
     * over: the window completes at the highest mark it settled, not at its bound.
     */
    public function test_late_row_below_the_bound_is_not_skipped(): void {
        global $DB;
        set_config('pagebudgetsec', 0, 'local_intellistream');
        $this->make_users(2 * exporter::PAGE_ROWS_MIN + 50, time() - 50000);

        $this->run_lane();
        $first = $this->shipped_users();
        [, $resume] = $this->user_state();
        $this->assertNotNull($resume);
        $readone = $first[5];
        $late = (int)$resume['hi'] - 100;
        $DB->set_field('user', 'timemodified', $late, ['id' => $readone]);

        for ($run = 0; $run < 10 && $resume !== null; $run++) {
            $this->run_lane();
            $this->shipped_users();
            [, $resume] = $this->user_state();
        }
        $this->assertNull($resume, 'the frozen window completes');
        [$wm] = $this->user_state();
        $this->assertLessThan($late, (int)$wm, 'the window completes below the late row');
        $this->run_lane();
        $this->assertContains($readone, $this->shipped_users(), 'the window after reads it');
    }

    /**
     * A watermark column that holds a counter, not a time, larger than a page: the
     * mark is the highest value read, so rows added later still flow.
     */
    public function test_counter_valued_column_is_not_skipped(): void {
        global $DB;
        $this->make_users(2 * exporter::PAGE_ROWS_MIN + 50, 0);
        $DB->execute('UPDATE {user} SET timemodified = 20261019 + id');
        $max = (int)$DB->get_field_sql('SELECT MAX(timemodified) FROM {user}');

        $this->run_lane();
        $this->shipped_users();
        [$wm, $resume] = $this->user_state();
        $this->assertNull($resume);
        $this->assertSame($max, (int)$wm, 'the mark is the highest value read, not the clock');

        [$added] = $this->make_users(1, 0);
        $DB->set_field('user', 'timemodified', $max + 1, ['id' => $added]);
        $this->run_lane();
        $this->assertContains($added, $this->shipped_users(), 'a row added later is read');
    }

    /**
     * A millisecond column with a backlog of several pages drains in one run: each
     * finished window opens the next while budget remains.
     */
    public function test_millisecond_column_drains_in_one_run(): void {
        global $DB;
        $this->make_users(3 * exporter::PAGE_ROWS_MIN + 50, 0);
        // Far above the clock, as a millisecond column is (and within a 10-digit column).
        $DB->execute('UPDATE {user} SET timemodified = :ms + id', ['ms' => 9000000000]);
        $all = array_map('intval', $DB->get_fieldset_select('user', 'id', '1 = 1'));

        $this->run_lane();
        $shipped = $this->shipped_users();
        foreach ($all as $id) {
            $this->assertContains($id, $shipped, "user {$id} is read in the first run");
        }
        [$wm, $resume] = $this->user_state();
        $this->assertNull($resume);
        $this->assertSame((int)$DB->get_field_sql('SELECT MAX(timemodified) FROM {user}'), (int)$wm);
    }

    /**
     * A window on a table whose watermark column is indexed starts its first page at
     * the window's lowest id: exactly the window's rows are read, and none below it.
     */
    public function test_indexed_window_starts_at_its_first_id(): void {
        global $DB;
        $base = time() - 50000;
        $rows = [];
        for ($i = 0; $i < 3 * exporter::PAGE_ROWS_MIN; $i++) {
            $rows[] = (object)['action' => 2, 'oldid' => $i + 1, 'source' => 'phpunit', 'timemodified' => $base + $i,
                'loggeduser' => 2, 'itemid' => 1, 'userid' => 2, 'rawgrademax' => 100, 'rawgrademin' => 0];
        }
        $DB->insert_records('grade_grades_history', $rows);
        $ids = array_map('intval', $DB->get_fieldset_select(
            'grade_grades_history',
            'id',
            'timemodified > :t ORDER BY id',
            ['t' => $base + exporter::PAGE_ROWS_MIN - 1]
        ));
        $state = $this->call_exporter('cdc_state');
        $state['wm']['grade_grades_history'] = $base + exporter::PAGE_ROWS_MIN - 1;
        $this->call_exporter('cdc_state_save', [$state]);

        $this->run_lane();
        $shipped = $this->shipped_users('grade_grades_history');
        sort($shipped);
        $this->assertSame($ids, $shipped, 'exactly the window, each row once');
        $first = $this->call_exporter('window_first_id', ['grade_grades_history', 'timemodified', 'timemodified > :cdcwm',
            ['cdcwm' => $base + exporter::PAGE_ROWS_MIN - 1]]);
        $this->assertSame($ids[0], $first, 'the indexed window starts at its lowest id');
        $this->assertNull($this->call_exporter('window_first_id', ['user', 'timemodified', 'timemodified > :cdcwm',
            ['cdcwm' => 0]]), 'an unindexed column is not looked up');
    }

    /**
     * A window frozen by the time budget with more above it continues into the next
     * window in the run that finishes it, instead of stopping there for a run.
     */
    public function test_budget_frozen_window_continues_next_run(): void {
        global $DB;
        $this->make_users(4 * exporter::PAGE_ROWS_MIN, 0);
        $DB->execute('UPDATE {user} SET timemodified = :ms + id', ['ms' => 9000000000]);
        $all = array_map('intval', $DB->get_fieldset_select('user', 'id', '1 = 1'));

        set_config('pagebudgetsec', 0, 'local_intellistream');
        $this->run_lane();
        $seen = $this->shipped_users();
        [, $resume] = $this->user_state();
        $this->assertNotNull($resume, 'the budget froze the window');

        set_config('pagebudgetsec', exporter::PAGE_BUDGET_SEC, 'local_intellistream');
        $this->run_lane();
        $seen = array_merge($seen, $this->shipped_users());
        foreach ($all as $id) {
            $this->assertContains($id, $seen, "user {$id} is read by the second run");
        }
    }

    /**
     * An entity with no position when the run loaded its state takes the one stored
     * now (a backfill seed written since), not 0: the table is not sent again, and
     * the seed survives the run's save.
     */
    public function test_seed_written_after_the_run_loaded_its_state_is_used(): void {
        global $DB;
        $users = $this->make_users(20, time() - 50000);
        // Load the plugin's config into this process's cache, as a run does at its start.
        $this->call_exporter('cdc_state');
        // Then the seed lands in the database behind that cache, as another process's
        // write does, with a second entity's seed beside it.
        $seed = (int)$DB->get_field('user', 'timemodified', ['id' => $users[9]]);
        $doc = json_encode(['wm' => ['user' => $seed, 'phpunit_seeded_elsewhere' => 12345], 'idwm' => [], 'idseen' => []]);
        if ($DB->record_exists('config_plugins', ['plugin' => 'local_intellistream', 'name' => 'cdc_state'])) {
            $DB->set_field('config_plugins', 'value', $doc, ['plugin' => 'local_intellistream', 'name' => 'cdc_state']);
        } else {
            $DB->insert_record('config_plugins', (object)['plugin' => 'local_intellistream', 'name' => 'cdc_state',
                'value' => $doc]);
        }
        $this->assertFalse(strpos((string)get_config('local_intellistream', 'cdc_state'), '12345'), 'the cache is stale');

        $this->run_lane();
        $shipped = $this->shipped_users();
        sort($shipped);
        $this->assertSame(array_slice($users, 10), $shipped, 'only the rows above the seed are read');
        $raw = $DB->get_field('config_plugins', 'value', ['plugin' => 'local_intellistream', 'name' => 'cdc_state']);
        $stored = json_decode((string)$raw, true);
        $this->assertSame(12345, (int)$stored['wm']['phpunit_seeded_elsewhere'], 'a seed the run did not touch survives its save');
    }

    /**
     * A watermark the backfill seeds while the lane runs survives the lane's save:
     * the save keeps the stored value for every entity the run did not change.
     */
    public function test_backfill_seed_survives_the_lane_save(): void {
        $original = $this->call_exporter('cdc_state');
        exporter::seed_incremental_watermark('course', 12345);
        $state = $original;
        $state['wm']['user'] = 777;
        $this->call_exporter('cdc_state_save', [$state, $original]);

        $after = $this->call_exporter('cdc_state');
        $this->assertSame(12345, (int)$after['wm']['course'], 'the seed is kept');
        $this->assertSame(777, (int)$after['wm']['user'], 'the run\'s own change is kept');
    }

    /**
     * A site that still keeps the watermarks of before the state document, seeded by
     * the backfill before the lane's first run: the seed adopts those watermarks into
     * the new document instead of writing a document that holds the seed alone.
     */
    public function test_seed_before_the_first_run_keeps_legacy_watermarks(): void {
        unset_config(exporter::CDC_STATE_KEY, 'local_intellistream');
        set_config(exporter::CDC_LEGACY_PREFIX . 'user', 4444, 'local_intellistream');
        set_config(exporter::CDC_LEGACY_PREFIX . 'course', 5555, 'local_intellistream');

        exporter::seed_incremental_watermark('role', 12345);

        $state = $this->call_exporter('cdc_state');
        $this->assertSame(4444, (int)$state['wm']['user'], 'legacy position kept');
        $this->assertSame(5555, (int)$state['wm']['course'], 'legacy position kept');
        $this->assertSame(12345, (int)$state['wm']['role'], 'the seed is kept');
        $this->assertNotEmpty(get_config('local_intellistream', exporter::CDC_STATE_KEY), 'the document exists');
        $this->assertFalse(get_config('local_intellistream', exporter::CDC_LEGACY_PREFIX . 'user'), 'legacy key cleared');
    }

    /**
     * A completed paged window goes on to the next in the same run only while a backlog
     * is left: below the clock bound of the entity's first window, or on a column that
     * is not a time. A watermark that only follows the clock stops there.
     */
    public function test_window_continues(): void {
        $start = time() - 20;
        $opened = $start + 15;
        $window = 1800;
        $skew = exporter::PAGE_CLOCK_SKEW_SEC;
        $this->assertTrue($this->call_exporter('window_continues', [$start - $skew - 1, $opened, $start, $window]), 'backlog');
        $this->assertTrue($this->call_exporter('window_continues', [1000, $opened, $start, $window]), 'counter column');
        $this->assertTrue($this->call_exporter('window_continues', [$opened * 1000, $opened, $start, $window]), 'ms column');
        $this->assertFalse($this->call_exporter('window_continues', [$start - $skew, $opened, $start, $window]), 'first bound');
        $this->assertFalse($this->call_exporter('window_continues', [$opened - $skew, $opened, $start, $window]), 'clock catch-up');
        $this->assertFalse($this->call_exporter('window_continues', [$opened - 1, $opened, $start, $window]), 'recent');
    }

    /**
     * Rows changed within the clock skew of a paged read are past the window's bound:
     * the run leaves them for the next window, and never moves the watermark over one
     * it did not send.
     */
    public function test_rows_inside_the_skew_wait_for_the_next_window(): void {
        global $DB;
        $users = $this->make_users(2 * exporter::PAGE_ROWS_MIN + 50, time() - 50000);
        $recent = time() - 30;
        $DB->set_field_select('user', 'timemodified', $recent, 'id >= :lo', ['lo' => $users[0]]);

        $this->run_lane();
        $sent = $this->shipped_users();
        [$wm, $resume] = $this->user_state();
        $this->assertNull($resume);
        $this->assertLessThan($recent, (int)$wm, 'the watermark stays below the recent rows');
        // The invariant that makes this safe: every row not sent is above the mark, so
        // the next window (wm > mark) reads it.
        foreach (array_diff($users, $sent) as $id) {
            $this->assertGreaterThan((int)$wm, (int)$DB->get_field('user', 'timemodified', ['id' => $id]));
        }
        $this->assertNotEmpty(array_diff($users, $sent), 'some rows were left for the next window');
    }

    /**
     * More than a page changed within the clock skew (a bulk update): the window is
     * bounded at the second before the read and read in pages in the same run, every
     * row once.
     */
    public function test_recent_bulk_change_is_paged(): void {
        global $DB;
        $now = time();
        $state = $this->call_exporter('cdc_state');
        $state['wm']['user'] = $now - 20;
        $this->call_exporter('cdc_state_save', [$state]);
        $users = $this->make_users(exporter::PAGE_ROWS_MIN + 20, $now - 19 - exporter::PAGE_ROWS_MIN - 20);
        $DB->set_field_select('user', 'timemodified', $now - 10, 'id >= :lo', ['lo' => $users[0]]);

        $out = $this->run_lane();
        $shipped = $this->shipped_users();
        foreach ($users as $id) {
            $this->assertContains($id, $shipped, "user {$id} is read");
        }
        $this->assertStringNotContainsString('HELD', $out);
        [$wm, $resume] = $this->user_state();
        $this->assertNull($resume, 'the window was completed in the run');
        $this->assertGreaterThanOrEqual($now - 10, (int)$wm);
        $this->assertLessThan(time(), (int)$wm, 'bounded below the clock');
    }

    /**
     * A watermark not yet in the past (a future-dated row) has no bound below the clock:
     * a full first page holds the window for a run, and no frozen window is left.
     */
    public function test_future_watermark_window_is_held(): void {
        $future = time() + 600; // Ahead of the clock, but within the window that still reads as seconds.
        $state = $this->call_exporter('cdc_state');
        $state['wm']['user'] = $future;
        $this->call_exporter('cdc_state_save', [$state]);
        $users = $this->make_users(exporter::PAGE_ROWS_MIN + 20, $future + 1);
        $this->assertNotEmpty($users);

        $out = $this->run_lane();
        $this->shipped_users();
        [$wm, $resume] = $this->user_state();
        $this->assertSame($future, (int)$wm, 'the watermark is held');
        $this->assertNull($resume, 'no window is frozen');
        $this->assertStringContainsString('watermark HELD', $out);
    }

    /**
     * A full buffer: the lane reads nothing and holds every watermark.
     */
    public function test_full_buffer_skips_the_lane(): void {
        global $DB;
        $this->make_users(5, time() - 50000);
        set_config('maxbuffergb', 1, 'local_intellistream');
        $dir = config::buffer_dir();
        make_writable_directory($dir);
        // A sparse file over the cap: the buffer measures sizes, so it is full,
        // without writing a gigabyte.
        $big = $dir . '/events-1-1-phpunit-h' . buffer::host_id() . '.jsonl.closed';
        $fh = fopen($big, 'c');
        ftruncate($fh, 1100 * 1024 * 1024);
        fclose($fh);
        // The shipper publishes the buffer's size on every run; publish it here.
        buffer::measure($dir);
        $reads = $DB->perf_get_reads();
        try {
            $out = $this->run_lane();
        } finally {
            unlink($big);
        }
        // An unskipped lane runs a query per entity (some 200); a skipped one none.
        $this->assertLessThan(5, $DB->perf_get_reads() - $reads, 'no table is read');
        $this->assertStringContainsString('incremental export skipped — the buffer is full', $out);
        $this->assertSame([], $this->shipped_users(), 'nothing is read');
        [$wm, $resume] = $this->user_state();
        $this->assertNull($wm);
        $this->assertNull($resume);

        // Room again: the next run reads from the same place.
        buffer::measure($dir);
        $this->run_lane();
        $this->assertNotEmpty($this->shipped_users());
    }

    /**
     * The backfill's keyset read pages: every row once, in id order, the watermark
     * at the last id.
     */
    public function test_backfill_window_reads_in_pages(): void {
        global $DB;
        $this->make_users(2 * exporter::PAGE_ROWS_MIN + 50, time() - 50000);
        $all = array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {user} ORDER BY id'));

        $reads = $DB->perf_get_reads();
        ob_start();
        try {
            $rows = exporter::export_entity_window('user', 'phpunit-batch', null, 'phpunit_bf_wm_user');
        } finally {
            buffer::flush();
            ob_end_clean();
        }
        $shipped = $this->shipped_users();
        $this->assertSame(count($all), $rows);
        $this->assertSame($all, $shipped, 'every row once, in id order');
        $this->assertSame(end($all), (int)get_config('local_intellistream', 'phpunit_bf_wm_user'));
        // Read in pages: at least one query per page of PAGE_ROWS_MIN rows.
        $this->assertGreaterThanOrEqual((int)ceil(count($all) / exporter::PAGE_ROWS_MIN), $DB->perf_get_reads() - $reads);
    }

    /**
     * The bound of a paged window: below the clock for a seconds column, none yet for a
     * window that starts within the skew, and the highest mark read for a column that
     * is not in seconds, whether the window's start or the rows read say so.
     */
    public function test_page_window_bound(): void {
        $now = time();
        $window = 1800;
        $skew = exporter::PAGE_CLOCK_SKEW_SEC;
        $this->assertSame($now - $skew, $this->call_exporter('page_window_bound', [0, $now - 5000, $now, $window]));
        $this->assertSame($now - $skew, $this->call_exporter('page_window_bound', [$now - 9000, $now - 10, $now, $window]));
        $this->assertSame($now - 1, $this->call_exporter('page_window_bound', [$now - 10, $now, $now, $window]), 'recent');
        $this->assertNull($this->call_exporter('page_window_bound', [$now + 100, $now + 200, $now, $window]), 'future: hold');
        // Milliseconds from 0 (new to the lane, or seeded in seconds by the backfill).
        $ms = $now * 1000;
        $this->assertSame($ms, $this->call_exporter('page_window_bound', [0, $ms, $now, $window]));
        $this->assertSame($ms, $this->call_exporter('page_window_bound', [$now - 9000, $ms, $now, $window]));
        $this->assertSame($ms + 5, $this->call_exporter('page_window_bound', [$ms, $ms + 5, $now, $window]));
    }

    /**
     * The lane's room check gives the verdict an append gets: room below the cap,
     * none at or over it, decided on an exact measurement.
     */
    public function test_lane_room_matches_the_cap(): void {
        set_config('maxbuffergb', 1, 'local_intellistream');
        $cap = config::max_buffer_bytes();
        $dir = config::buffer_dir();
        make_writable_directory($dir);
        $big = $dir . '/events-1-1-phpunit-h' . buffer::host_id() . '.jsonl.closed';
        try {
            foreach ([[$cap - 4096, true], [$cap, false], [$cap + 4096, false]] as [$size, $room]) {
                $fh = fopen($big, 'c');
                ftruncate($fh, $size);
                fclose($fh);
                buffer::measure($dir);
                $this->assertSame($room, $this->call_exporter('lane_has_room'), "buffer of {$size} bytes");
            }
        } finally {
            unlink($big);
        }
    }

    /**
     * Page sizing: grows when nothing is measured, shrinks to the byte target on large rows, and
     * stays within the smallest and largest page.
     */
    public function test_next_page_rows(): void {
        set_config('pagerowsmax', exporter::PAGE_ROWS_MAX, 'local_intellistream');
        $min = exporter::PAGE_ROWS_MIN;
        $this->assertSame(500, exporter::next_page_rows(0, 0, 500), 'an empty page keeps the size');
        $this->assertSame(2000, exporter::next_page_rows(1000, 0, 1000), 'nothing measured: doubles');
        $this->assertSame(2000, exporter::next_page_rows(1000, 1000 * 1024, 1000), 'small rows: doubles at most');
        $this->assertSame($min, exporter::next_page_rows(1000, 1000 * 1048576, 1000), '1 MiB rows: the smallest page');
        $this->assertSame(200, exporter::next_page_rows(100, 100 * 200 * 1024, 100), '200 KiB rows: bounded growth');
        $this->assertSame(327, exporter::next_page_rows(400, 400 * 200 * 1024, 400), '200 KiB rows: the byte target');
        set_config('pagerowsmax', 300, 'local_intellistream');
        $this->assertSame(300, exporter::next_page_rows(1000, 0, 1000), 'never above pagerowsmax');
        set_config('pagerowsmax', 'abc', 'local_intellistream');
        $this->assertSame(2000, exporter::next_page_rows(1000, 0, 1000), 'a mistyped value: the default largest page');
    }

    /**
     * An open window read to its end: the watermark stops below the read's first
     * second when the highest mark read is in a second the read was still in, so a
     * row written in that second after the read passed it is read next run.
     */
    public function test_open_window_mark(): void {
        $opened = time() - 2;
        $w = 1800;
        $this->assertNull($this->call_exporter('open_window_mark', [null, $opened, $w]), 'nothing read');
        $this->assertSame($opened - 10, $this->call_exporter('open_window_mark', [$opened - 10, $opened, $w]), 'older');
        $this->assertSame($opened - 1, $this->call_exporter('open_window_mark', [$opened - 1, $opened, $w]));
        $this->assertSame($opened - 1, $this->call_exporter('open_window_mark', [$opened, $opened, $w]), 'first second');
        $this->assertSame($opened - 1, $this->call_exporter('open_window_mark', [$opened + 2, $opened, $w]), 'last second');
        $this->assertSame($opened - 1, $this->call_exporter('open_window_mark', [$opened + 60, $opened, $w]), 'clock ahead');
        $this->assertSame($opened - 1, $this->call_exporter('open_window_mark', [$opened + $w, $opened, $w]), 'window edge');
        $big = $opened + $w + 1;
        $this->assertSame($big, $this->call_exporter('open_window_mark', [$big, $opened, $w]), 'not a time: unchanged');
        $this->assertSame($opened * 1000, $this->call_exporter('open_window_mark', [$opened * 1000, $opened, $w]), 'ms: unchanged');
    }

    /**
     * The lane, end to end: a row stamped in or after the second its read started (here
     * by a clock a few seconds ahead) leaves the watermark below that second, so a row
     * stamped the same after the read passed it is read by the next run.
     */
    public function test_lane_keeps_the_read_second_open(): void {
        global $DB;
        [$id] = $this->make_users(1, time() - 50000);
        $state = $this->call_exporter('cdc_state');
        $state['wm']['user'] = time() - 100;
        $this->call_exporter('cdc_state_save', [$state]);
        $stamp = time() + 5;
        $DB->set_field('user', 'timemodified', $stamp, ['id' => $id]);

        $this->run_lane();
        $this->assertContains($id, $this->shipped_users(), 'the row is read');
        [$wm] = $this->user_state();
        $this->assertLessThan($stamp, (int)$wm, 'the watermark stays below the stamp');

        // Another row with the same stamp, written after the read passed that second.
        [$late] = $this->make_users(1, $stamp);
        $this->run_lane();
        $this->assertContains($late, $this->shipped_users(), 'the late row is read');
    }

    /**
     * The run that adopts the legacy per-entity watermarks keeps a seed the backfill
     * wrote while it ran.
     */
    public function test_adoption_run_keeps_a_seed_written_during_it(): void {
        unset_config(exporter::CDC_STATE_KEY, 'local_intellistream');
        set_config(exporter::CDC_LEGACY_PREFIX . 'user', 4444, 'local_intellistream');
        set_config(exporter::CDC_LEGACY_PREFIX . 'course', 5555, 'local_intellistream');
        $original = $this->call_exporter('cdc_state');
        $this->assertTrue($original['migrated']);

        exporter::seed_incremental_watermark('role', 12345);
        $state = $original;
        $state['wm']['user'] = 7777;
        $this->call_exporter('cdc_state_save', [$state, $original]);

        $after = $this->call_exporter('cdc_state');
        $this->assertSame(12345, (int)$after['wm']['role'], 'the seed is kept');
        $this->assertSame(7777, (int)$after['wm']['user'], 'the run\'s own change is kept');
        $this->assertSame(5555, (int)$after['wm']['course'], 'the adopted position is kept');
        $this->assertFalse(get_config('local_intellistream', exporter::CDC_LEGACY_PREFIX . 'course'), 'legacy key cleared');
    }

    /**
     * Ids of the census records in the buffer, page records and single record alike,
     * consuming the files.
     *
     * @return array [ids in buffer order, number of records that carry ids]
     */
    private function census_ids(): array {
        buffer::flush();
        $ids = [];
        $records = 0;
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (is_array($rec) && ($rec['entity'] ?? '') === 'entity_census' && isset($rec['entity_data']['pks'])) {
                    $records++;
                    foreach ($rec['entity_data']['pks'] as $id) {
                        $ids[] = (int)$id;
                    }
                }
            }
            unlink($path);
        }
        return [$ids, $records];
    }

    /**
     * The census reads ids a bounded number at a time and lists every id once, in
     * order, whether the reads are a multiple of the rows or not, and whether the
     * census pages are smaller or larger than a read.
     */
    public function test_census_reads_ids_in_pages(): void {
        global $DB;
        $this->make_users(10, time() - 50000);
        $all = array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {user} ORDER BY id'));
        $n = count($all);
        $registry = ['user' => ['table' => 'user']];
        $cases = [
            [3, 1000, 1], // Reads not a multiple of the rows, one census record.
            [$n, 1000, 1], // One full read, then an empty one.
            [4, 5, (int)ceil($n / 5)], // Census pages across reads.
            [5, 2, (int)ceil($n / 2)],
            [0, 1000, 1], // 0 is the default read size, not one id a read.
        ];
        foreach ($cases as [$readrows, $pagesize, $records]) {
            set_config('censusreadrows', $readrows, 'local_intellistream');
            set_config('census_page_size', $pagesize, 'local_intellistream');
            $reads = $DB->perf_get_reads();
            ob_start();
            try {
                exporter::export_census('phpunit-census', $registry);
            } finally {
                buffer::flush();
                ob_end_clean();
            }
            $label = "read {$readrows}, page {$pagesize}";
            if ($readrows > 0) {
                $this->assertGreaterThanOrEqual((int)floor($n / $readrows) + 1, $DB->perf_get_reads() - $reads, $label);
            } else {
                $this->assertLessThan($n, $DB->perf_get_reads() - $reads, $label);
            }
            $this->assertSame([$all, $records], $this->census_ids(), $label);
        }
    }
}
