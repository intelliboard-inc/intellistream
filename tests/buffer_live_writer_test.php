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

use local_intellistream\local\buffer_privacy;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\local_intellistream\buffer::class)]
#[CoversClass(\local_intellistream\shipper::class)]
#[CoversClass(\local_intellistream\local\buffer_privacy::class)]
/**
 * A writer never writes into a buffer file the shipper may have taken.
 *
 * A long-lived process (a cron runner kept alive between tasks, a full export) keeps
 * its buffer file open across gaps. The shipper takes an own-host file once it has been
 * idle shipper::SHIP_IDLE_SEC, and a later write into it would be lost without an error,
 * so the writer rotates before writing into a file idle for buffer::WRITER_IDLE_SEC.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\buffer
 * @covers     \local_intellistream\shipper
 * @covers     \local_intellistream\local\buffer_privacy
 */
final class buffer_live_writer_test extends \advanced_testcase {
    /**
     * Pair the site and start with an empty buffer and clean writer state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Statics outlive a test in the same process: start each one clean.
        $this->set_static('capretryat', 0);
        $this->set_static('replacing', false);
        $this->set_static('lastwrite', 0);
        buffer::flush();
        set_config('siteid', 'phpunit-live-writer', 'local_intellistream');
        set_config('enabled', 1, 'local_intellistream');
        set_config('rotateagesec', 3600, 'local_intellistream');
        config::refresh();
    }

    /**
     * Release the buffer handle and leave the writer statics clean for the next test class
     * in this process (a cooldown left set would refuse its writes).
     */
    protected function tearDown(): void {
        buffer::flush();
        $this->set_static('capretryat', 0);
        $this->set_static('replacing', false);
        $this->set_static('lastwrite', 0);
        parent::tearDown();
    }

    /**
     * Set a private static of buffer.
     *
     * @param string $name property
     * @param mixed $value value
     */
    private function set_static(string $name, $value): void {
        $p = new \ReflectionProperty(buffer::class, $name);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }

    /**
     * Read a private static of buffer.
     *
     * @param string $name property
     * @return mixed
     */
    private function get_static(string $name) {
        $p = new \ReflectionProperty(buffer::class, $name);
        $p->setAccessible(true);
        return $p->getValue();
    }

    /**
     * Make the held file look idle for $seconds, to the writer and to the shipper.
     *
     * @param int $seconds idle time
     */
    private function age_idle(int $seconds): void {
        $this->set_static('lastwrite', time() - $seconds);
        $path = $this->get_static('path');
        if ($path !== null && file_exists($path)) {
            touch($path, time() - $seconds);
        }
    }

    /**
     * Every record number in the buffer directory, in any file (active or closed).
     *
     * @return int[]
     */
    private function buffered(): array {
        $out = [];
        foreach (glob(config::buffer_dir() . '/events-*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $out[] = (int)json_decode($line, true)['n'];
            }
        }
        return $out;
    }

    /**
     * Run the shipper's stale promotion over the active files, then "ship" (read and
     * remove) whatever it promoted, as a ship run does.
     *
     * @return int files promoted
     */
    private function sweep_and_ship(): int {
        $promote = new \ReflectionMethod(shipper::class, 'promote_if_stale');
        $promote->setAccessible(true);
        $inv = ['foreign' => [], 'unreadable' => []];
        $n = 0;
        clearstatcache();
        foreach (glob(config::buffer_dir() . '/events-*.jsonl') as $path) {
            $args = [$path, basename($path), lstat($path), &$inv];
            $closed = $promote->invokeArgs(null, $args);
            if ($closed !== null) {
                $n++;
                unlink(config::buffer_dir() . '/' . $closed);
            }
        }
        return $n;
    }

    /**
     * Append record number $n.
     *
     * @param int $n record number
     * @return bool
     */
    private function put(int $n): bool {
        return buffer::append(json_encode(['n' => $n]) . "\n");
    }

    public function test_a_live_writer_idle_past_the_ship_threshold_keeps_every_record(): void {
        $this->assertTrue($this->put(0));
        $this->age_idle(200);
        $this->assertSame(1, $this->sweep_and_ship(), 'the shipper takes the idle file');
        for ($i = 1; $i <= 42; $i++) {
            $this->assertTrue($this->put($i));
        }
        $kept = $this->buffered();
        for ($i = 1; $i <= 42; $i++) {
            $this->assertContains($i, $kept, "record $i was written after the sweep and must not be lost");
        }
    }

    public function test_writer_idle_threshold_is_below_both_ship_thresholds(): void {
        $this->assertLessThan(shipper::SHIP_IDLE_SEC, buffer::WRITER_IDLE_SEC);
        $remote = new \ReflectionMethod(shipper::class, 'remote_idle_sec');
        $remote->setAccessible(true);
        $this->assertLessThan($remote->invoke(null), buffer::WRITER_IDLE_SEC);
    }

    public function test_idle_boundary_decides_same_file_or_new_file(): void {
        $this->put(1);
        $first = $this->get_static('path');
        $this->age_idle(buffer::WRITER_IDLE_SEC - 5);
        $this->put(2);
        $this->assertSame($first, $this->get_static('path'), 'under the threshold: same file');
        $this->age_idle(buffer::WRITER_IDLE_SEC + 5);
        $this->put(3);
        $this->assertNotSame($first, $this->get_static('path'), 'past the threshold: a new file');
        $this->assertFileExists($first . '.closed', 'the idle file is closed for shipping, not lost');
        $this->assertEqualsCanonicalizing([1, 2, 3], $this->buffered());
    }

    public function test_age_rotation_closes_the_file_after_the_write(): void {
        set_config('rotateagesec', 120, 'local_intellistream');
        config::refresh();
        $this->put(1);
        $first = $this->get_static('path');
        $this->set_static('created', time() - 130);
        $this->put(2);
        $this->assertNull($this->get_static('path'), 'the aged file is closed after the write');
        $this->assertFileExists($first . '.closed');
        $this->put(3);
        $this->assertNotSame($first, $this->get_static('path'));
        $this->assertEqualsCanonicalizing([1, 2, 3], $this->buffered());
    }

    public function test_a_steady_writer_keeps_one_file(): void {
        $this->put(1);
        $first = $this->get_static('path');
        for ($i = 2; $i <= 5; $i++) {
            $this->age_idle(10);
            $this->put($i);
        }
        $this->assertSame($first, $this->get_static('path'));
        $this->assertSame(0, $this->sweep_and_ship(), 'a file written 10 s ago is not taken');
    }

    public function test_keepalive_after_a_long_gap_rotates_instead_of_recreating(): void {
        $this->put(1);
        $old = $this->get_static('path');
        $this->age_idle(200);
        buffer::keepalive();
        $this->assertNull($this->get_static('path'), 'the idle file is handed to the shipper');
        $this->assertFalse(file_exists($old), 'no file is re-created at the old name');
        $this->sweep_and_ship();
        $this->put(2);
        $this->assertNotSame($old, $this->get_static('path'));
        $this->assertContains(2, $this->buffered());
    }

    public function test_keepalive_keeps_a_fresh_file(): void {
        $this->put(1);
        $path = $this->get_static('path');
        $this->age_idle(30);
        buffer::keepalive();
        $this->assertSame($path, $this->get_static('path'));
        clearstatcache();
        $this->assertGreaterThanOrEqual(time() - 2, filemtime($path));
    }

    public function test_a_rotation_for_idle_time_is_not_refused_at_the_cap(): void {
        $this->put(1);
        $this->age_idle(200);
        // The capacity re-check is in its cooldown, as after a recent refusal.
        $this->set_static('capretryat', time() + 60);
        $this->assertTrue($this->put(2), 'the record that would have landed in the held file still lands');
        $this->assertContains(2, $this->buffered());
    }

    public function test_a_replacement_is_not_replaced_again_without_admission(): void {
        $this->put(1);
        $this->age_idle(200);
        $this->set_static('capretryat', time() + 60);
        $this->assertTrue($this->put(2), 'one replacement of an admitted file is allowed');
        $this->age_idle(200);
        $this->assertFalse($this->put(3), 'the replacement itself is not replaced past the cap');
    }

    public function test_a_writer_without_a_file_is_still_refused_at_the_cap(): void {
        buffer::flush();
        $this->set_static('capretryat', time() + 60);
        $this->assertFalse($this->put(1));
    }

    public function test_a_reused_file_that_was_taken_opens_a_new_one(): void {
        $this->put(1);
        $old = $this->get_static('path');
        // A new request of the same worker: the handle is gone, the pointer remains.
        $this->set_static('handle', null);
        $this->set_static('path', null);
        // The shipper took the file between requests.
        rename($old, $old . '.closed');
        $this->assertTrue($this->put(2));
        $new = $this->get_static('path');
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        $this->assertFalse(file_exists($old), 'no empty file is left at the old name');
        $this->assertEqualsCanonicalizing([1, 2], $this->buffered());
    }

    public function test_an_idle_file_is_not_reused_by_the_next_request(): void {
        $this->put(1);
        $old = $this->get_static('path');
        $this->set_static('handle', null);
        $this->set_static('path', null);
        touch($old, time() - buffer::WRITER_IDLE_SEC);
        $this->put(2);
        $this->assertNotSame($old, $this->get_static('path'));
        $this->assertFileExists($old . '.closed');
    }

    public function test_an_own_file_with_a_future_mtime_is_not_stranded(): void {
        $dir = config::buffer_dir();
        @mkdir($dir, 0777, true);
        $name = 'events-999998-' . (time() * 1000000) . '-abd-h' . buffer::host_id() . '.jsonl';
        file_put_contents($dir . '/' . $name, json_encode(['n' => 8]) . "\n");
        touch($dir . '/' . $name, time() + 10 * shipper::SHIP_IDLE_SEC);
        $this->assertSame(1, $this->sweep_and_ship(), 'a file stamped far in the future is taken');
    }

    public function test_an_own_file_is_taken_on_idle_time_not_on_a_dead_pid(): void {
        $dir = config::buffer_dir();
        @mkdir($dir, 0777, true);
        $name = 'events-999999-' . (time() * 1000000) . '-abc-h' . buffer::host_id() . '.jsonl';
        file_put_contents($dir . '/' . $name, json_encode(['n' => 9]) . "\n");
        // PID 999999 is not alive here, but the file was written just now.
        $this->assertSame(0, $this->sweep_and_ship(), 'a recently written file is not taken on a dead-looking PID');
        touch($dir . '/' . $name, time() - shipper::SHIP_IDLE_SEC);
        $this->assertSame(1, $this->sweep_and_ship(), 'after SHIP_IDLE_SEC it is taken');
    }

    public function test_keepalive_after_a_long_gap_is_not_refused_at_the_cap(): void {
        $this->put(1);
        $this->age_idle(200);
        $this->set_static('capretryat', time() + 60);
        buffer::keepalive();
        $this->assertTrue($this->put(2), 'the export row after a keepalive rotation still lands');
        $this->assertContains(2, $this->buffered());
    }

    public function test_keepalive_does_not_recreate_a_renamed_file(): void {
        $this->put(1);
        $path = $this->get_static('path');
        // The shipper renamed the file although the writer was not idle (e.g. clock skew).
        rename($path, $path . '.closed');
        buffer::keepalive();
        $this->assertFalse(file_exists($path), 'touch() must not re-create the renamed path');
    }

    public function test_a_reused_file_is_measured_from_its_mtime(): void {
        $this->put(1);
        $old = $this->get_static('path');
        $this->set_static('handle', null);
        $this->set_static('path', null);
        $mtime = time() - 60;
        touch($old, $mtime);
        clearstatcache();
        $handle = new \ReflectionMethod(buffer::class, 'handle');
        $handle->setAccessible(true);
        $handle->invoke(null);
        $this->assertSame($old, $this->get_static('path'), 'a file idle 60 s is reused');
        $this->assertSame($mtime, $this->get_static('lastwrite'), 'idle time counts from its last write');
    }

    public function test_privacy_skips_a_recent_own_file_with_a_dead_looking_pid(): void {
        $dir = config::buffer_dir();
        @mkdir($dir, 0777, true);
        $path = $dir . '/events-999999-' . (time() * 1000000) . '-abe-h' . buffer::host_id() . '.jsonl';
        $line = json_encode(['record_type' => 'event', 'event_data' => ['userid' => 4242]]) . "\n";
        file_put_contents($path, $line);
        $res = buffer_privacy::delete_user_records([4242]);
        $this->assertSame(1, $res['skipped'], 'written just now: its writer may be alive');
        $this->assertSame($line, file_get_contents($path), 'the file is left unchanged');
        touch($path, time() - shipper::SHIP_IDLE_SEC);
        clearstatcache();
        $res = buffer_privacy::delete_user_records([4242]);
        $this->assertSame(0, $res['skipped']);
        $this->assertSame(1, $res['removed'], 'idle past SHIP_IDLE_SEC: rewritten without the record');
    }

    public function test_a_file_with_a_future_mtime_is_not_reused_by_the_next_request(): void {
        $this->put(1);
        $old = $this->get_static('path');
        $this->set_static('handle', null);
        $this->set_static('path', null);
        // A clock stepped back: the shipper treats this mtime as idle (see the future-mtime test).
        touch($old, time() + shipper::SHIP_IDLE_SEC + 80);
        clearstatcache();
        $this->put(2);
        $this->assertNotSame($old, $this->get_static('path'), 'a new file, not the takeable one');
        $this->assertFileExists($old . '.closed');
        $this->assertEqualsCanonicalizing([1, 2], $this->buffered());
    }

    public function test_a_flush_after_a_keepalive_rotation_ends_the_replacement_right(): void {
        $this->put(1);
        $this->age_idle(200);
        buffer::keepalive();
        buffer::flush();
        // Later in the same long-lived process, the cap is full (cooldown active).
        $this->set_static('capretryat', time() + 60);
        $this->assertFalse($this->put(2), 'the next file goes through the capacity admission');
    }
}
