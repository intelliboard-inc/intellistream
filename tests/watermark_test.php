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
 * Tests for the incremental lane's watermark.
 *
 * The 15-minute lane ships only rows changed since its watermark, and takes the
 * next watermark from the rows it read. A watermark that runs ahead of what was
 * shipped skips rows for good; one that does not move re-ships the same rows
 * every run. Both are silent, which is why they are tested end to end here, on
 * a real entity (`user`, change column `timemodified`).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\exporter::export_incremental
 * @covers     \local_intellistream\exporter::cdc_recently_active
 */
final class watermark_test extends \advanced_testcase {
    /**
     * Pair the site with a destination, and release the new-install hold, so the
     * lane runs.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        set_config('siteid', 'phpunit-watermark-site', 'local_intellistream');
        set_config('endpoint', 'https://s3.example.com', 'local_intellistream');
        set_config('bucket', 'phpunit-bucket', 'local_intellistream');
        set_config('accesskey', 'phpunit-access', 'local_intellistream');
        set_config('secretkey', 'phpunit-secret', 'local_intellistream');
        set_config('backfillgate', 0, 'local_intellistream');
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
     * The lane's watermark for `user`, read the way the lane reads it.
     *
     * @return int|float|null
     */
    private function user_watermark() {
        $state = $this->call_exporter('cdc_state');
        return $state['wm']['user'] ?? null;
    }

    /**
     * Run the incremental lane once and return the ids of the `user` rows it buffered.
     *
     * The buffer files are consumed, so each run is counted on its own.
     *
     * @return int[] Sorted.
     */
    private function run_lane(): array {
        ob_start();
        try {
            exporter::export_incremental();
        } finally {
            buffer::flush();
            ob_end_clean();
        }
        $ids = [];
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (
                    is_array($rec) && ($rec['record_type'] ?? '') === 'entity_snapshot'
                        && ($rec['entity'] ?? '') === 'user'
                ) {
                    $ids[] = (int)$rec['entity_data']['id'];
                }
            }
            unlink($path);
        }
        sort($ids);
        return $ids;
    }

    /**
     * The watermark follows the rows read: it lands on the newest, holds when nothing
     * changed, and moves to exactly the row that changed.
     */
    public function test_watermark_is_taken_from_the_rows_read(): void {
        global $DB;
        $this->assertNotSame('', config::site_id());
        $this->assertTrue(config::destination_ready());

        // Ahead of every other user row on the site (admin, guest), so the three
        // below decide the mark.
        $t = time() + 100;
        $users = [];
        foreach ([0, 1, 2] as $offset) {
            $user = $this->getDataGenerator()->create_user();
            $DB->set_field('user', 'timemodified', $t + $offset, ['id' => $user->id]);
            $users[] = (int)$user->id;
        }

        $first = $this->run_lane();
        foreach ($users as $id) {
            $this->assertContains($id, $first, 'the first run ships every user row');
        }
        $this->assertSame($t + 2, $this->user_watermark(), 'the mark is the newest row read');

        $this->assertSame([], $this->run_lane(), 'nothing changed, nothing is buffered');
        $this->assertSame($t + 2, $this->user_watermark(), 'and the mark holds');

        $DB->set_field('user', 'timemodified', $t + 5, ['id' => $users[1]]);
        $this->assertSame([$users[1]], $this->run_lane(), 'exactly the changed row');
        $this->assertSame($t + 5, $this->user_watermark());
    }

    /**
     * Unpaired, the lane does not run and no watermark moves.
     */
    public function test_unpaired_site_moves_no_watermark(): void {
        unset_config('siteid', 'local_intellistream');
        $this->getDataGenerator()->create_user();

        $this->assertSame([], $this->run_lane());
        $this->assertNull($this->user_watermark());
    }

    /**
     * Which watermarks count as recent (the read runs without the existence check).
     */
    public function test_cdc_recently_active(): void {
        $window = 1800;
        $now = time();

        $this->assertFalse($this->call_exporter('cdc_recently_active', [0, $window]), 'never ran');
        $this->assertTrue($this->call_exporter('cdc_recently_active', [$now, $window]), 'changed now');
        $this->assertTrue($this->call_exporter('cdc_recently_active', [$now - $window + 5, $window]), 'inside the window');
        $this->assertFalse($this->call_exporter('cdc_recently_active', [$now - $window - 1, $window]), 'just outside');
        $this->assertFalse(
            $this->call_exporter('cdc_recently_active', [$now * 1000, $window]),
            'a millisecond value is not a recent Unix time'
        );
        $this->assertTrue(
            $this->call_exporter('cdc_recently_active', [$now + 0.5, $window]),
            'a decimal mark on a decimal column is compared by its whole seconds'
        );
    }
}
