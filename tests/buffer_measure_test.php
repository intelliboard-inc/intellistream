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
 * Tests for measuring the buffer directory.
 *
 * A measurement is trusted for a while after it is taken, so it must carry the time
 * its walk ended (a slow walk stamped at its start is already stale when published),
 * and requests that queue for the measuring lock must not each walk the directory
 * again when one of them has just done it.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\buffer::measure
 * @covers     \local_intellistream\buffer::measure_exclusive
 */
final class buffer_measure_test extends \advanced_testcase {
    /** @var string */
    private $dir;

    /**
     * Create the buffer directory with one closed file of known size.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        $this->dir = config::buffer_dir();
        $this->assertNotFalse(make_writable_directory($this->dir));
        file_put_contents($this->dir . '/events-1-1700000100000001-2a0aa299-h00000000.jsonl.closed', str_repeat('x', 777));
        clearstatcache();
    }

    /**
     * measure_exclusive(), which is private.
     *
     * @return int|null
     */
    private function measure_exclusive(): ?int {
        $method = new \ReflectionMethod(buffer::class, 'measure_exclusive');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        return $method->invoke(null, $this->dir);
    }

    /**
     * measure() returns the real size and publishes it stamped no earlier than the call.
     */
    public function test_measure_stamps_the_end_of_the_walk(): void {
        $before = time();

        $this->assertSame(777, buffer::measure($this->dir));

        $state = buffer::read_capacity($this->dir);
        $this->assertNotNull($state);
        $this->assertSame(777, $state['bytes']);
        $this->assertGreaterThanOrEqual($before, $state['at']);
        $this->assertLessThanOrEqual(time(), $state['at']);
    }

    /**
     * A measurement younger than WEB_MEASURE_MIN_SEC is answered without walking.
     */
    public function test_measure_exclusive_reuses_a_recent_measurement(): void {
        buffer::publish_capacity($this->dir, ['bytes' => 12345], null, time() - 1);

        $this->assertSame(12345, $this->measure_exclusive(), 'the published bytes, not the 777 on disk');
        $this->assertSame(12345, buffer::read_capacity($this->dir)['bytes'], 'and nothing was republished');
    }

    /**
     * An older measurement is replaced by a real walk.
     */
    public function test_measure_exclusive_walks_when_the_measurement_is_old(): void {
        buffer::publish_capacity($this->dir, ['bytes' => 12345], null, time() - buffer::WEB_MEASURE_MIN_SEC - 1);

        $this->assertSame(777, $this->measure_exclusive());
        $this->assertSame(777, buffer::read_capacity($this->dir)['bytes']);
    }

    /**
     * With no lock to take (something other than the lock file at its path), a stale
     * measurement is still replaced by a real walk, so the cap stays enforced; a recent
     * one is reused.
     */
    public function test_measure_exclusive_without_a_lock_still_measures(): void {
        $lockpath = $this->dir . '/' . buffer::CAPACITY_LOCK;
        @unlink($lockpath);
        $this->assertTrue(mkdir($lockpath), 'a directory where the lock file should be');
        try {
            buffer::publish_capacity($this->dir, ['bytes' => 12345], null, time() - buffer::WEB_MEASURE_MIN_SEC - 1);
            $this->assertSame(777, $this->measure_exclusive(), 'stale: measured without the lock');

            buffer::publish_capacity($this->dir, ['bytes' => 12345], null, time() - 1);
            $this->assertSame(12345, $this->measure_exclusive(), 'recent: reused, no walk');
        } finally {
            @rmdir($lockpath);
        }
    }

    /**
     * The marks file is bookkeeping, and its write temporary is residue the reclaim and
     * the uninstall purge remove.
     */
    public function test_marks_file_and_its_temporary_are_known(): void {
        $this->assertSame('state', buffer::entry_kind(buffer::MARKS_FILE));
        $this->assertSame('residue', buffer::entry_kind(buffer::MARKS_FILE . '.tmp-a1b2c3'));
    }

    /**
     * Without a lock, a request finding another's live measurement claim does not walk;
     * an abandoned claim is taken over.
     */
    public function test_lockless_measuring_is_single_flight(): void {
        $lockpath = $this->dir . '/' . buffer::CAPACITY_LOCK;
        $claim = $this->dir . '/' . buffer::MEASURE_CLAIM;
        @unlink($lockpath);
        $this->assertTrue(mkdir($lockpath), 'a directory where the lock file should be');
        try {
            buffer::publish_capacity($this->dir, ['bytes' => 12345], null, time() - buffer::WEB_MEASURE_MIN_SEC - 1);

            touch($claim);
            $this->assertNull($this->measure_exclusive(), 'someone else is measuring: no walk');
            $this->assertSame(12345, buffer::read_capacity($this->dir)['bytes'], 'nothing republished');

            touch($claim, time() - buffer::MEASURE_CLAIM_STALE_SEC - 1);
            $this->assertSame(777, $this->measure_exclusive(), 'an abandoned claim is taken over');
            $this->assertFalse(file_exists($claim), 'and released');
            $this->assertSame('state', buffer::entry_kind(buffer::MEASURE_CLAIM));
        } finally {
            @unlink($claim);
            @rmdir($lockpath);
        }
    }
}
