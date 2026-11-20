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
 * Tests for how many files a ship run selects, and what a long run leaves for capture.
 *
 * On a cluster every web worker writes its own small buffer file, so a fixed 2,000 files
 * per run could not keep up. The ship_events task now selects a larger window and its
 * time limit decides when it stops; an inline run keeps the small one. A long run must
 * leave a measurement page loads can still decide on, and the verification sweep no
 * longer ships inline.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\shipper
 * @covers     \local_intellistream\buffer
 * @covers     \local_intellistream\sweep
 * @covers     \local_intellistream\exporter::sweep_backpressure_ok
 */
final class ship_window_test extends \advanced_testcase {
    /** @var string Buffer directory. */
    private $dir;

    /**
     * Pair the site and create the buffer directory.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('siteid', 'phpunit-window-site', 'local_intellistream');
        $this->dir = config::buffer_dir();
        $this->assertNotFalse(make_writable_directory($this->dir));
    }

    /**
     * Release this process's buffer handle and clear the directory.
     */
    protected function tearDown(): void {
        buffer::flush();
        foreach (glob($this->dir . '/events-*') ?: [] as $path) {
            @unlink($path);
        }
        @unlink($this->dir . '/' . buffer::CAPACITY_FILE);
        parent::tearDown();
    }

    /**
     * A private static method of the shipper.
     *
     * @param string $name
     * @return \ReflectionMethod
     */
    private function method(string $name): \ReflectionMethod {
        $method = new \ReflectionMethod(shipper::class, $name);
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        return $method;
    }

    /**
     * Closed buffer files in a shuffled creation order, 40 distinct modification times,
     * so most files share theirs with others and the name decides between them.
     *
     * @param int $count
     * @return array Path => mtime.
     */
    private function seed(int $count): array {
        $files = [];
        $order = range(1, $count);
        shuffle($order);
        foreach ($order as $n) {
            $path = $this->dir . '/events-1-' . (1700000000000000 + $n) . '-2a0aa299-h00000000.jsonl.closed';
            file_put_contents($path, json_encode(['id' => 'w-' . $n, 'site_id' => config::site_id()]) . "\n");
            $mtime = time() - 3600 + ($n % 40) * 60;
            touch($path, $mtime);
            $files[$path] = $mtime;
        }
        clearstatcache();
        return $files;
    }

    /**
     * The scheduled task selects the larger window, an inline run the small one.
     */
    public function test_the_window_depends_on_the_caller(): void {
        $window = $this->method('files_per_run');
        $this->assertSame(shipper::SCHEDULED_FILES_PER_RUN, $window->invoke(null, true));
        $this->assertSame(shipper::MAX_FILES_PER_RUN, $window->invoke(null, false));
        $this->assertGreaterThan(shipper::MAX_FILES_PER_RUN, shipper::SCHEDULED_FILES_PER_RUN);
    }

    /**
     * A pass over more closed files than the window keeps exactly the oldest, oldest first,
     * and counts every closed file it saw.
     */
    public function test_the_pass_keeps_exactly_the_oldest(): void {
        $files = $this->seed(130);
        $expected = $files;
        uksort($expected, function ($a, $b) use ($files) {
            return ($files[$a] <=> $files[$b]) ?: strcmp($a, $b);
        });
        $expected = array_slice(array_keys($expected), 0, 50);

        $inv = $this->method('inventory')->invoke(null, [$this->dir], $this->dir, 50);

        $this->assertSame($expected, array_column($inv['closed'], 1));
        $this->assertSame(130, $inv['closedseen']);
        $this->assertSame(50, $inv['window']);
        $this->assertTrue($this->method('left_behind')->invoke(null, $inv, false), 'a full window leaves files behind');

        $inv = $this->method('inventory')->invoke(null, [$this->dir], $this->dir, 200);
        $this->assertCount(130, $inv['closed']);
        $this->assertFalse($this->method('left_behind')->invoke(null, $inv, false), 'all selected');
        $this->assertTrue($this->method('left_behind')->invoke(null, $inv, true), 'a time stop leaves files behind');
    }

    /**
     * A ship run longer than the measurement's freshness limit still leaves a measurement
     * page loads decide on: age runs from when it was written, not from the run start.
     */
    public function test_a_long_run_leaves_a_fresh_measurement(): void {
        $start = time() - 1000;
        $fields = ['bytes' => 1000, 'closed' => 1, 'closedbytes' => 1000];
        buffer::publish_capacity($this->dir, $fields, null, $start, buffer::PUBLISH_RUN_START);
        buffer::publish_capacity($this->dir, $fields, buffer::read_capacity($this->dir), $start, buffer::PUBLISH_RUN_END);

        $state = buffer::read_capacity($this->dir);
        $this->assertSame($start, $state['at'], 'the counts still describe the run start, for the projection');
        $this->assertNotNull(buffer::fresh_capacity($this->dir), 'written just now, so fresh');
    }

    /**
     * A measurement written by an earlier release (no publish time) is judged on its own
     * time, as before.
     */
    public function test_an_earlier_measurement_is_judged_on_its_time(): void {
        $fields = ['bytes' => 1000, 'closed' => 1, 'closedbytes' => 1000];
        foreach ([time() - 1000 => false, time() - 10 => true] as $at => $fresh) {
            buffer::publish_capacity($this->dir, $fields, null, $at, buffer::PUBLISH_RUN_START);
            $path = $this->dir . '/' . buffer::CAPACITY_FILE;
            $state = json_decode(file_get_contents($path), true);
            unset($state['pubat']);
            file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES));
            $this->assertSame($fresh, buffer::fresh_capacity($this->dir) !== null);
        }
    }

    /**
     * A pull taking what it drained off an old measurement does not make it fresh: only
     * a measurement does.
     */
    public function test_a_pull_does_not_refresh_an_old_measurement(): void {
        $then = time() - 1000;
        $fields = ['bytes' => 5000, 'closed' => 5, 'closedbytes' => 5000];
        buffer::publish_capacity($this->dir, $fields, null, $then, buffer::PUBLISH_RUN_START);
        $path = $this->dir . '/' . buffer::CAPACITY_FILE;
        $state = json_decode(file_get_contents($path), true);
        $state['pubat'] = $then;   // Written then, as the measurement it is.
        file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES));
        $this->assertNull(buffer::fresh_capacity($this->dir), 'stale before the pull');

        buffer::publish_drain($this->dir, 1000, 1, time());

        $state = buffer::read_capacity($this->dir);
        $this->assertSame(4000, $state['bytes'], 'the pull took its bytes off');
        $this->assertSame($then, $state['pubat'], 'and kept the publish time');
        $this->assertNull(buffer::fresh_capacity($this->dir), 'still stale after the pull');
    }

    /**
     * The verification sweep pauses on a backlog over half the cap without starting a ship
     * run of its own, so it neither takes the ship lock nor writes the shipper's status.
     */
    public function test_the_sweep_does_not_ship_inline(): void {
        set_config('endpoint', 'https://127.0.0.1:9', 'local_intellistream');
        set_config('bucket', 'phpunit-bucket', 'local_intellistream');
        set_config('accesskey', 'phpunit-access', 'local_intellistream');
        set_config('secretkey', 'phpunit-secret', 'local_intellistream');
        set_config('maxbuffergb', 1, 'local_intellistream');
        set_config('ship_state', 'ok', 'local_intellistream');
        set_config('backfillgate', 0, 'local_intellistream');   // No new-install hold on the sweep.
        // A sparse closed file over half the cap: measured as backlog, without writing it.
        $big = $this->dir . '/events-1-1-phpunit-h' . buffer::host_id() . '.jsonl.closed';
        $fh = fopen($big, 'c');
        ftruncate($fh, 600 * 1024 * 1024);
        fclose($fh);
        buffer::measure($this->dir);

        ob_start();
        try {
            $summary = sweep::run();
        } finally {
            buffer::flush();
            $out = (string)ob_get_clean();
            @unlink($big);
        }

        $this->assertSame('backpressure', $summary['status']);
        $this->assertSame('ok', (string)get_config('local_intellistream', 'ship_state'), 'no ship run wrote its status');
        // What an inline ship run prints, gated, failed or done.
        $this->assertStringNotContainsString('host load above gate', $out);
        $this->assertStringNotContainsString('ship failed', $out);
        $this->assertStringNotContainsString('local_intellistream: shipped ', $out);
    }
}
