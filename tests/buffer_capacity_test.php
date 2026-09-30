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
 * Tests for the buffer's published capacity measurement.
 *
 * The capture path decides whether the buffer has room from this measurement
 * instead of sizing the directory on every request, so a measurement that reads
 * back wrong either refuses capture on a site with room or lets the buffer grow
 * past its cap. The file format also has to stay readable across releases: a
 * measurement written by a build that did not know an optional key must still be
 * usable, or every site upgrading would size its buffer on the next page load.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\buffer::web_decides_on
 * @covers     \local_intellistream\buffer::publish_capacity
 * @covers     \local_intellistream\buffer::read_capacity
 * @covers     \local_intellistream\buffer::projected_bytes
 */
final class buffer_capacity_test extends \advanced_testcase {
    /**
     * The buffer directory the plugin resolves on this site, created.
     *
     * @return string
     */
    private function buffer_dir(): string {
        $dir = config::buffer_dir();
        $this->assertNotFalse(make_writable_directory($dir), 'the buffer directory can be created');
        return $dir;
    }

    /**
     * A version-2 measurement written the way the build before `parkedat` wrote it.
     *
     * @param string $dir Buffer directory.
     * @param int $at Unix time of the measurement.
     * @param int $version Format version to stamp.
     * @return array The document written.
     */
    private function write_measurement_without_parkedat(string $dir, int $at, int $version = 2): array {
        $doc = ['v' => $version, 'dir' => $dir];
        foreach (buffer::CAPACITY_INTS as $key) {
            $doc[$key] = 0;
        }
        $doc['at'] = $at;
        $doc['bytes'] = 4096;
        $doc['closed'] = 3;
        $doc['rate'] = 1.5;
        $doc['prev'] = new \stdClass();
        file_put_contents($dir . '/' . buffer::CAPACITY_FILE, json_encode($doc, JSON_UNESCAPED_SLASHES));
        return $doc;
    }

    /**
     * A fresh measurement is always what a web request decides on.
     */
    public function test_web_decides_on_a_fresh_measurement(): void {
        $this->assertTrue(buffer::web_decides_on(['at' => 1790000000 - 3600]));
        $this->assertTrue(buffer::web_decides_on(['at' => 1790000000]));
    }

    /**
     * Without a fresh measurement a web request leaves the decision to
     * measure_exclusive(), which throttles and serialises measuring.
     */
    public function test_web_does_not_decide_without_a_fresh_measurement(): void {
        $this->assertFalse(buffer::web_decides_on(null));
    }

    /**
     * What publish_capacity() writes, read_capacity() reads back, parkedat included.
     */
    public function test_publish_then_read_round_trips(): void {
        $this->resetAfterTest();
        $dir = $this->buffer_dir();
        $at = time();

        buffer::publish_capacity($dir, [
            'bytes' => 12345,
            'closed' => 2,
            'closedbytes' => 10000,
            'active' => 1,
            'parked' => 1,
            'parkedat' => $at - 50,
            'prev' => ['/old/dir' => 4],
        ], null, $at);

        $state = buffer::read_capacity($dir);
        $this->assertNotNull($state);
        $this->assertSame($at, $state['at']);
        $this->assertSame(12345, $state['bytes']);
        $this->assertSame(2, $state['closed']);
        $this->assertSame(10000, $state['closedbytes']);
        $this->assertSame(1, $state['active']);
        $this->assertSame(1, $state['parked']);
        $this->assertSame($at - 50, $state['parkedat']);
        $this->assertSame(['/old/dir' => 4], $state['prev']);
        $this->assertSame(0.0, $state['rate'], 'a plain publish derives no rate');
    }

    /**
     * Without a parked file, parkedat reads back as 0.
     */
    public function test_parkedat_defaults_to_zero(): void {
        $this->resetAfterTest();
        $dir = $this->buffer_dir();

        buffer::publish_capacity($dir, ['bytes' => 10], null, time());

        $state = buffer::read_capacity($dir);
        $this->assertNotNull($state);
        $this->assertSame(0, $state['parkedat']);
    }

    /**
     * A field left out keeps its previous value when a measurement is republished.
     */
    public function test_republish_keeps_fields_left_out(): void {
        $this->resetAfterTest();
        $dir = $this->buffer_dir();
        $at = time();

        buffer::publish_capacity($dir, ['bytes' => 100, 'closed' => 5, 'parkedat' => $at - 10], null, $at - 1);
        buffer::publish_capacity($dir, ['bytes' => 200], buffer::read_capacity($dir), $at);

        $state = buffer::read_capacity($dir);
        $this->assertNotNull($state);
        $this->assertSame(200, $state['bytes']);
        $this->assertSame(5, $state['closed']);
        $this->assertSame($at - 10, $state['parkedat']);
        $this->assertSame($at, $state['at']);
    }

    /**
     * The shipper's run-start publishes derive the inflow rate and the run interval.
     */
    public function test_run_start_publishes_derive_rate_and_interval(): void {
        $this->resetAfterTest();
        $dir = $this->buffer_dir();
        $t0 = time() - 200;

        buffer::publish_capacity($dir, ['bytes' => 1000], null, $t0, buffer::PUBLISH_RUN_START);
        buffer::publish_capacity(
            $dir,
            ['bytes' => 2000],
            buffer::read_capacity($dir),
            $t0 + 100,
            buffer::PUBLISH_RUN_START
        );

        $state = buffer::read_capacity($dir);
        $this->assertNotNull($state);
        $this->assertEqualsWithDelta(10.0, $state['rate'], 0.0001, '1000 bytes over 100 s');
        $this->assertSame(100, $state['interval']);
        $this->assertSame($t0 + 100, $state['start']);
        $this->assertSame(2000, $state['refbytes']);
        $this->assertSame($t0 + 100, $state['refat']);
    }

    /**
     * A version-2 file written before `parkedat` existed is still a usable measurement.
     */
    public function test_measurement_without_parkedat_still_reads(): void {
        $this->resetAfterTest();
        $dir = $this->buffer_dir();
        $at = time();
        $this->write_measurement_without_parkedat($dir, $at);

        $state = buffer::read_capacity($dir);
        $this->assertNotNull($state, 'the optional key being absent must not make the file unusable');
        $this->assertSame(0, $state['parkedat']);
        $this->assertSame(4096, $state['bytes']);
        $this->assertSame(3, $state['closed']);
        $this->assertSame($at, $state['at']);
        $this->assertEqualsWithDelta(1.5, $state['rate'], 0.0001);
        $this->assertSame([], $state['prev']);
    }

    /**
     * A measurement of another format version, or of another directory, is ignored.
     */
    public function test_other_version_or_directory_is_ignored(): void {
        $this->resetAfterTest();
        $dir = $this->buffer_dir();

        $this->write_measurement_without_parkedat($dir, time(), 1);
        $this->assertNull(buffer::read_capacity($dir), 'a version-1 file is no measurement');

        $doc = $this->write_measurement_without_parkedat($dir, time());
        $doc['dir'] = $dir . '/elsewhere';
        file_put_contents($dir . '/' . buffer::CAPACITY_FILE, json_encode($doc, JSON_UNESCAPED_SLASHES));
        $this->assertNull(buffer::read_capacity($dir), 'a measurement of another directory');

        unlink($dir . '/' . buffer::CAPACITY_FILE);
        $this->assertNull(buffer::read_capacity($dir), 'no file at all');
    }

    /**
     * The projection adds the inflow since the measurement, and never overflows.
     */
    public function test_projected_bytes(): void {
        $now = time();

        $projected = buffer::projected_bytes(['bytes' => 1000, 'rate' => 10.0, 'at' => $now - 5]);
        $this->assertGreaterThanOrEqual(1050, $projected);
        $this->assertLessThanOrEqual(1070, $projected, 'at most a couple of seconds of test time');

        $future = buffer::projected_bytes(['bytes' => 1000, 'rate' => 10.0, 'at' => $now + 60]);
        $this->assertSame(1000, $future, 'a measurement from the future projects no growth');

        $still = buffer::projected_bytes(['bytes' => 1000, 'rate' => 0.0, 'at' => $now - 3600]);
        $this->assertSame(1000, $still, 'no inflow, no growth');

        $absurd = buffer::projected_bytes(['bytes' => 1000, 'rate' => 1.0e300, 'at' => $now - 10]);
        $this->assertSame(PHP_INT_MAX, $absurd, 'an absurd rate is capped, not overflowed');
    }

    /**
     * The truncated flag round-trips, is carried by a republish that leaves it out,
     * and reads as 0 from a measurement written without it.
     */
    public function test_truncated_flag_round_trips(): void {
        $dir = $this->buffer_dir();
        buffer::publish_capacity($dir, ['bytes' => 10, 'truncated' => 1], null, time());
        $this->assertSame(1, buffer::read_capacity($dir)['truncated']);

        buffer::publish_capacity($dir, ['bytes' => 20], buffer::read_capacity($dir), time());
        $this->assertSame(1, buffer::read_capacity($dir)['truncated'], 'kept when a republish leaves it out');

        buffer::publish_capacity($dir, ['truncated' => 0], buffer::read_capacity($dir), time());
        $this->assertSame(0, buffer::read_capacity($dir)['truncated']);

        $this->write_measurement_without_parkedat($dir, time());
        $this->assertSame(0, buffer::read_capacity($dir)['truncated'], 'absent reads as 0');
    }

    /**
     * A run that returns early (here: no storage configured) still clears a previous
     * run's truncated flag, so the flag never outlives the run that set it.
     *
     * @covers \local_intellistream\shipper::run
     */
    public function test_a_run_that_returns_early_clears_the_truncated_flag(): void {
        $this->resetAfterTest();
        $dir = $this->buffer_dir();
        set_config('siteid', 'phpunit-truncated-site', 'local_intellistream');
        set_config('enabled', 1, 'local_intellistream');
        unset_config('endpoint', 'local_intellistream');
        buffer::publish_capacity($dir, ['bytes' => 10, 'truncated' => 1], null, time() - 30);
        $this->assertSame(1, buffer::read_capacity($dir)['truncated']);

        ob_start();
        try {
            \local_intellistream\shipper::run(true);
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, buffer::read_capacity($dir)['truncated']);
    }
}
