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
 * Tests for how the shipper takes apart a batch object storage keeps refusing.
 *
 * One file whose content an endpoint refuses used to wedge shipping: it sat in the
 * first batch of every run and the whole batch was refused with it. The shipper now
 * halves such a batch to find the file, delivers the rest, and holds the refused
 * file out. That search must find the file cheaply, must stay inside its per-run
 * upload budget however many batches a run takes apart, and must report a refusal
 * that looks site-wide instead of carrying on as if all were well.
 *
 * The transport is a stub with the one method ship_batch() calls, put(), which
 * counts uploads and refuses any body holding the marker POISON.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\shipper
 */
final class shipper_isolate_test extends \advanced_testcase {
    /** @var string Buffer directory for the test. */
    private $dir;

    /** @var int Sequence for unique file names. */
    private $seq = 0;

    /**
     * Pair the site and create the buffer directory.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('siteid', 'phpunit-isolate-site', 'local_intellistream');
        $this->dir = config::buffer_dir();
        $this->assertNotFalse(make_writable_directory($this->dir));
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
     * A transport double that counts PUTs and refuses bodies holding POISON.
     *
     * @param int $status 400 (BadDigest) or 413 for a refusal.
     * @param bool $rejectall Refuse every body (a site-wide fault).
     * @return object
     */
    private function stub(int $status = 400, bool $rejectall = false) {
        return new class ($status, $rejectall) {
            /** @var int */
            public $puts = 0;
            /** @var int */
            private $status;
            /** @var bool */
            private $rejectall;

            /**
             * Refusal behaviour.
             *
             * @param int $status
             * @param bool $rejectall
             */
            public function __construct(int $status, bool $rejectall) {
                $this->status = $status;
                $this->rejectall = $rejectall;
            }

            /**
             * Same signature shipper::ship_batch() calls.
             *
             * @param string $key
             * @param string $body
             * @param string $type
             * @param array $headers
             * @return array
             */
            public function put(string $key, string $body, string $type = '', array $headers = []): array {
                $this->puts++;
                $plain = (string)gzdecode($body);
                if ($this->rejectall || strpos($plain, 'POISON') !== false) {
                    return ['ok' => false, 'status' => $this->status, 'category' => shipper::REJECTED_CATEGORY,
                        'detail' => 'HTTP ' . $this->status, 'code' => $this->status === 413 ? '' : 'BadDigest'];
                }
                return ['ok' => true, 'status' => 200, 'category' => null, 'detail' => 'ok'];
            }
        };
    }

    /**
     * Plant closed buffer files, oldest first, one record each.
     *
     * @param bool[] $poison One entry per file; true puts the refused marker in it.
     * @return string[] Paths.
     */
    private function seed(array $poison): array {
        $paths = [];
        foreach ($poison as $bad) {
            $this->seq++;
            $path = $this->dir . '/events-1-' . (1700000100000000 + $this->seq) . '-2a0aa299-h00000000.jsonl.closed';
            file_put_contents($path, json_encode(['id' => 'iso-' . $this->seq, 'site_id' => config::site_id(),
                'record_type' => 'phpunit_probe', 'x' => $bad ? 'POISON' : 'ok']) . "\n");
            touch($path, time() - 600 + $this->seq);
            $paths[] = $path;
        }
        clearstatcache();
        return $paths;
    }

    /**
     * A run's shared state, built the way shipper::run() builds it.
     *
     * @param object $stub Transport.
     * @return array
     */
    private function newrun($stub): array {
        return ['s3' => $stub, 'encsvc' => new services\encryption_service(), 'encenabled' => false,
            'expected' => config::site_id(), 'dir' => $this->dir, 'files' => 0, 'events' => 0, 'objects' => 0,
            'badrecords' => 0, 'badfiles' => 0, 'undeletable' => []];
    }

    /**
     * Path => size for a set of files.
     *
     * @param string[] $paths
     * @return array
     */
    private function sizes(array $paths): array {
        $sizes = [];
        foreach ($paths as $path) {
            $sizes[$path] = (int)filesize($path);
        }
        return $sizes;
    }

    /**
     * Ship a batch whole, then take it apart; the isolate() result and its suspects.
     *
     * @param array $run
     * @param string[] $paths
     * @return array [failure, suspects, PUTs spent by isolate()]
     */
    private function ship_then_isolate(array &$run, array $paths): array {
        $inv = ['current' => buffer::measure_dir($this->dir), 'prev' => []];
        $sizes = $this->sizes($paths);
        $batch = shipper::plan_batches($sizes, shipper::MAX_BATCH_BYTES)[0];
        $args = [&$run, &$inv, $batch['datebucket'], $batch['paths'], $sizes];
        $whole = $this->method('ship_batch')->invokeArgs(null, $args);
        $this->assertFalse($whole['ok'], 'the whole batch is refused');

        $before = $run['s3']->puts;
        $suspects = [];
        ob_start();
        try {
            $args = [&$run, &$inv, $batch['datebucket'], $whole['paths'], $sizes, &$suspects, $whole['result']];
            $failure = $this->method('isolate')->invokeArgs(null, $args);
        } finally {
            ob_end_clean();
        }
        clearstatcache();
        return [$failure, $suspects, $run['s3']->puts - $before];
    }

    /**
     * One poisoned file among forty is found by halving, cheaply, and everything else
     * is delivered and removed.
     */
    public function test_bisection_finds_one_poisoned_file_cheaply(): void {
        $n = 40;
        $poison = array_fill(0, $n, false);
        $poison[17] = true;
        $paths = $this->seed($poison);
        $run = $this->newrun($this->stub());

        [$failure, $suspects, $puts] = $this->ship_then_isolate($run, $paths);

        $this->assertNull($failure, 'the run carries on');
        $this->assertSame([basename($paths[17])], array_keys($suspects), 'the poisoned file is the one suspect');
        $this->assertLessThanOrEqual(2 * (int)ceil(log($n, 2)) + 2, $puts, 'about two uploads per halving');
        $this->assertTrue(file_exists($paths[17]), 'the suspect is kept, not deleted');
        foreach ($paths as $i => $path) {
            if ($i !== 17) {
                $this->assertFalse(file_exists($path), 'good file ' . $i . ' was delivered and removed');
            }
        }
        $this->assertSame($n - 1, $run['files']);
    }

    /**
     * The upload budget is the run's: two refused batches in one run share it.
     */
    public function test_two_refused_batches_share_one_budget(): void {
        // One good file ahead of many refused ones keeps the search going (something is
        // accepted, so the site-wide stop never fires) until the budget runs out.
        $first = $this->seed(array_merge([false], array_fill(0, 39, true)));
        $second = $this->seed(array_merge([false], array_fill(0, 39, true)));
        $run = $this->newrun($this->stub());

        [$failure1, , $puts1] = $this->ship_then_isolate($run, $first);
        [$failure2, , $puts2] = $this->ship_then_isolate($run, $second);

        $this->assertNotNull($failure1, 'stopped by the budget, the refusal is reported');
        $this->assertSame(shipper::ISOLATE_MAX_PUTS, $puts1, 'the first batch spends the whole budget');
        $this->assertLessThanOrEqual(
            shipper::ISOLATE_MAX_PUTS,
            $puts1 + $puts2,
            'both batches together stay inside ISOLATE_MAX_PUTS'
        );
        $this->assertSame(0, $puts2, 'nothing is left for the second batch this run');
        $this->assertNotNull($failure2, 'with the allowance spent, the batch refusal is reported and the run stops');
        $resume = (string)get_config('local_intellistream', 'ship_rejected');
        $this->assertNotSame('', $resume, 'the next run is told to resume the search');
    }

    /**
     * Refused alone twice with nothing accepted looks site-wide: reported, not swallowed.
     */
    public function test_two_lone_refusals_with_nothing_accepted_report_the_refusal(): void {
        $paths = $this->seed([false, false, false, false]);
        $run = $this->newrun($this->stub(400, true));

        [$failure, $suspects, $puts] = $this->ship_then_isolate($run, $paths);

        $this->assertIsArray($failure, 'a refusal is returned, not null');
        $this->assertFalse($failure['ok']);
        $this->assertSame(shipper::REJECTED_CATEGORY, $failure['category']);
        $this->assertCount(2, $suspects);
        $this->assertSame(3, $puts, 'the older pair, then each of its two files: then it stops');
        $this->assertSame(0, $run['objects']);
        foreach ($paths as $path) {
            $this->assertTrue(file_exists($path), 'every file is still queued');
        }
    }

    /**
     * A 413 on one poisoned file in a batch is found the same way.
     */
    public function test_a_413_is_isolated_like_a_content_refusal(): void {
        $paths = $this->seed([false, false, true, false]);
        $run = $this->newrun($this->stub(413));

        [$failure, $suspects] = $this->ship_then_isolate($run, $paths);

        $this->assertNull($failure);
        $this->assertSame([basename($paths[2])], array_keys($suspects));
    }

    /**
     * Too large on more than one file is taken apart at once; on one file it is not.
     */
    public function test_too_large_batch(): void {
        $toolarge = $this->method('too_large_batch');
        $http413 = ['ok' => false, 'status' => 413, 'category' => shipper::REJECTED_CATEGORY, 'code' => ''];
        $entity = ['ok' => false, 'status' => 400, 'category' => shipper::REJECTED_CATEGORY, 'code' => 'EntityTooLarge'];
        $digest = ['ok' => false, 'status' => 400, 'category' => shipper::REJECTED_CATEGORY, 'code' => 'BadDigest'];

        $this->assertTrue($toolarge->invoke(null, $http413, ['a', 'b']), 'a 413 on several files');
        $this->assertTrue($toolarge->invoke(null, $entity, ['a', 'b', 'c']), 'EntityTooLarge on several files');
        $this->assertFalse($toolarge->invoke(null, $http413, ['a']), 'a 413 on one file');
        $this->assertFalse($toolarge->invoke(null, $entity, ['a']), 'EntityTooLarge on one file');
        $this->assertFalse($toolarge->invoke(null, $digest, ['a', 'b']), 'another code');
    }

    /**
     * Only a content code, or a 413, can count towards parking a file.
     */
    public function test_parkable(): void {
        $parkable = $this->method('parkable');
        $result = function (int $status, string $code): array {
            return ['ok' => false, 'status' => $status, 'category' => shipper::REJECTED_CATEGORY,
                'detail' => 'HTTP ' . $status, 'code' => $code];
        };

        $this->assertTrue($parkable->invoke(null, $result(400, 'BadDigest')));
        $this->assertTrue($parkable->invoke(null, $result(400, 'EntityTooLarge')));
        $this->assertTrue($parkable->invoke(null, $result(413, '')));
        $this->assertFalse($parkable->invoke(null, $result(400, 'InvalidArgument')), 'an argument error');
        $this->assertFalse($parkable->invoke(null, $result(400, 'MalformedXML')), 'a request-document error');
        $this->assertFalse($parkable->invoke(null, $result(400, 'RequestTimeout')), 'a timeout');
        $this->assertFalse($parkable->invoke(null, $result(400, '')), 'a code-less 400');
        $this->assertFalse($parkable->invoke(null, $result(408, 'BadDigest')), 'a 408');
    }

    /**
     * A suspect refused alone again, the same way, while something else is accepted, is parked.
     */
    public function test_retry_suspects_parks_a_file_refused_twice(): void {
        $paths = $this->seed([true, false, false, false, false]);
        $run = $this->newrun($this->stub());
        [, $suspects] = $this->ship_then_isolate($run, $paths);
        $this->assertSame([basename($paths[0])], array_keys($suspects));

        $inv = ['current' => buffer::measure_dir($this->dir), 'prev' => []];
        $sizes = $this->sizes([$paths[0]]);
        $still = [];
        ob_start();
        try {
            $args = [&$run, &$inv, [$paths[0]], $sizes, $suspects, &$still];
            $failure = $this->method('retry_suspects')->invokeArgs(null, $args);
        } finally {
            ob_end_clean();
        }
        clearstatcache();

        $this->assertNull($failure);
        $this->assertSame([], $still);
        $this->assertTrue(file_exists($paths[0] . buffer::PARKED_SUFFIX), 'parked');
        $this->assertFalse(file_exists($paths[0]));
    }

    /**
     * A run that has reached its deadline stops retrying suspects: they stay suspects
     * for the next run, and the run is marked as cut short.
     */
    public function test_retry_suspects_stops_at_the_run_deadline(): void {
        $paths = $this->seed([true, true]);
        $run = $this->newrun($this->stub());
        $run['deadline'] = time() - 1;
        $inv = ['current' => buffer::measure_dir($this->dir), 'prev' => []];
        $known = [];
        foreach ($paths as $path) {
            $known[basename($path)] = '400:BadDigest';
        }
        $kept = [];
        $args = [&$run, &$inv, $paths, $this->sizes($paths), $known, &$kept];
        $failure = $this->method('retry_suspects')->invokeArgs(null, $args);

        $this->assertNull($failure);
        $this->assertSame(0, $run['s3']->puts, 'nothing is sent after the deadline');
        $this->assertSame(array_keys($known), array_keys($kept), 'both stay suspects');
        $this->assertTrue($run['truncated']);
    }

    /**
     * The isolate() allowance never runs past the run's deadline.
     */
    public function test_isolate_stops_at_the_run_deadline(): void {
        $paths = $this->seed(array_merge([false], array_fill(0, 9, true)));
        $run = $this->newrun($this->stub());
        $run['deadline'] = time() - 1;
        [$failure, , $puts] = $this->ship_then_isolate($run, $paths);

        $this->assertSame(0, $puts, 'no upload once the run is out of time');
        $this->assertNotNull($failure, 'the batch refusal is reported');
    }
}
