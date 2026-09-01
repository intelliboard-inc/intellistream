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
 * Tests for the shipper's pure batching, keying and ownership-filter functions.
 *
 * These three were deliberately written to be pure — they take values and return
 * values, touching neither the filesystem nor object storage — because they are the
 * parts of shipping that can go wrong SILENTLY. A mis-bucketed day mis-partitions a
 * tenant's data, a non-deterministic key leaves duplicate objects behind a retry,
 * and a hole in the ownership filter ships one tenant's records under another
 * tenant's prefix. None of those raise an error at the time.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\shipper
 */
final class shipper_test extends \advanced_testcase {
    /** 2026-08-03 23:59:59 UTC, in microseconds. */
    private const US_LATE_ON_THE_3RD = 1785801599000000;

    /** 2026-08-04 00:00:00 UTC, in microseconds. */
    private const US_MIDNIGHT_4TH = 1785801600000000;

    /** 2026-08-04 12:00:00 UTC, in microseconds. */
    private const US_NOON_4TH = 1785844800000000;

    /**
     * Build a buffer filename carrying a given creation time.
     *
     * @param int $us Creation time in microseconds.
     * @param string $token Process token.
     * @return string
     */
    private function name_at(int $us, string $token = '6ba907de'): string {
        return 'events-1234-' . $us . '-' . $token . '-h9f8e7d6.jsonl.closed';
    }

    /**
     * Files inside one date bucket and under the cap form a single batch.
     */
    public function test_groups_one_day_under_the_cap_into_one_batch(): void {
        $a = $this->name_at(self::US_MIDNIGHT_4TH, 'aaaaaaaa');
        $b = $this->name_at(self::US_NOON_4TH, 'bbbbbbbb');

        $batches = shipper::plan_batches([$a => 100, $b => 200], 1000);

        $this->assertCount(1, $batches);
        $this->assertSame('2026/08/04', $batches[0]['datebucket']);
        $this->assertSame([$a, $b], $batches[0]['paths']);
    }

    /**
     * The midnight case the bucketing exists for.
     *
     * Files arrive in close-time order while the partition comes from the creation
     * time embedded in the name, so the two interleave around midnight. Bucketing
     * rather than cutting the ordered list means no object ever spans two date
     * partitions — even when the input order alternates across the boundary.
     */
    public function test_never_lets_a_batch_span_two_date_partitions(): void {
        $late = $this->name_at(self::US_LATE_ON_THE_3RD, 'aaaaaaaa');
        $early = $this->name_at(self::US_MIDNIGHT_4TH, 'bbbbbbbb');
        $later = $this->name_at(self::US_LATE_ON_THE_3RD + 500000, 'cccccccc');

        $batches = shipper::plan_batches([$late => 10, $early => 10, $later => 10], 1000);

        $this->assertCount(2, $batches);
        $buckets = array_column($batches, 'datebucket');
        $this->assertSame(['2026/08/03', '2026/08/04'], $buckets);

        foreach ($batches as $batch) {
            foreach ($batch['paths'] as $path) {
                $us = (int) explode('-', basename($path))[2];
                $this->assertSame(
                    $batch['datebucket'],
                    gmdate('Y/m/d', intdiv($us, 1000000)),
                    'every file in a batch must belong to that batch\'s date'
                );
            }
        }
    }

    /**
     * A batch is flushed BEFORE it would exceed the cap, never after.
     */
    public function test_splits_before_exceeding_the_cap(): void {
        $a = $this->name_at(self::US_NOON_4TH, 'aaaaaaaa');
        $b = $this->name_at(self::US_NOON_4TH + 1, 'bbbbbbbb');
        $c = $this->name_at(self::US_NOON_4TH + 2, 'cccccccc');

        $batches = shipper::plan_batches([$a => 60, $b => 60, $c => 60], 100);

        $this->assertCount(3, $batches);
        foreach ($batches as $batch) {
            $this->assertCount(1, $batch['paths'], '60 + 60 exceeds a cap of 100');
        }
    }

    /**
     * A file larger than the whole cap becomes a batch of one rather than being
     * split. Splitting would have to cut mid-line and corrupt a record.
     */
    public function test_an_oversized_file_lands_alone_and_is_never_split(): void {
        $big = $this->name_at(self::US_NOON_4TH, 'aaaaaaaa');
        $small = $this->name_at(self::US_NOON_4TH + 1, 'bbbbbbbb');

        $batches = shipper::plan_batches([$big => 5000, $small => 10], 100);

        $this->assertCount(2, $batches);
        $this->assertSame([$big], $batches[0]['paths']);
        $this->assertSame([$small], $batches[1]['paths']);
    }

    /**
     * A cap of zero or below is clamped rather than causing an infinite split.
     */
    public function test_clamps_a_nonpositive_cap(): void {
        $a = $this->name_at(self::US_NOON_4TH);

        $this->assertCount(1, shipper::plan_batches([$a => 10], 0));
        $this->assertCount(1, shipper::plan_batches([$a => 10], -5));
        $this->assertSame([], shipper::plan_batches([], 100));
    }

    /**
     * The key shape the middleware depends on.
     *
     * Three properties are load-bearing downstream: the first two segments must be
     * `<prefix>/<site_id>/` because that is how the middleware attributes an object
     * to a tenant, and the key must end `.jsonl.gz` because the middleware's list
     * filter is exactly that suffix — a key without it is skipped silently, with no
     * error and no quarantine.
     */
    public function test_object_key_carries_prefix_site_and_suffix(): void {
        $this->resetAfterTest();
        set_config('prefix', 'events', 'local_intellistream');
        set_config('siteid', 'site-abc-123', 'local_intellistream');

        $key = shipper::batch_object_key('2026/08/04', [$this->name_at(self::US_NOON_4TH)]);

        $this->assertStringStartsWith('events/site-abc-123/', $key);
        $this->assertStringEndsWith('.jsonl.gz', $key);
        $this->assertStringContainsString('/2026/08/04/batch-', $key);
        $this->assertStringContainsString('-' . self::US_NOON_4TH . '-1-', $key);
    }

    /**
     * The key is a deterministic function of the batch's contents, and independent
     * of the order the files were listed in.
     *
     * This is what makes a retry idempotent: a run that failed at the PUT rebuilds
     * the identical batch and overwrites its own object instead of leaving a
     * duplicate behind.
     */
    public function test_object_key_is_deterministic_and_order_independent(): void {
        $this->resetAfterTest();
        set_config('prefix', 'events', 'local_intellistream');
        set_config('siteid', 'site-abc-123', 'local_intellistream');

        $a = $this->name_at(self::US_NOON_4TH, 'aaaaaaaa');
        $b = $this->name_at(self::US_NOON_4TH + 1, 'bbbbbbbb');

        $forward = shipper::batch_object_key('2026/08/04', [$a, $b]);
        $reverse = shipper::batch_object_key('2026/08/04', [$b, $a]);
        $again = shipper::batch_object_key('2026/08/04', [$a, $b]);

        $this->assertSame($forward, $again, 'same input must give the same key');
        $this->assertSame($forward, $reverse, 'listing order must not change the key');
    }

    /**
     * A different set of files gets a different key, so one run can never clobber
     * another run's object.
     */
    public function test_a_different_batch_gets_a_different_key(): void {
        $this->resetAfterTest();
        set_config('prefix', 'events', 'local_intellistream');
        set_config('siteid', 'site-abc-123', 'local_intellistream');

        $a = $this->name_at(self::US_NOON_4TH, 'aaaaaaaa');
        $b = $this->name_at(self::US_NOON_4TH + 1, 'bbbbbbbb');

        $this->assertNotSame(
            shipper::batch_object_key('2026/08/04', [$a]),
            shipper::batch_object_key('2026/08/04', [$a, $b])
        );
    }

    /**
     * Call the private ownership filter.
     *
     * Private because nothing outside the shipper should be filtering by tenant,
     * but its correctness is worth pinning: it is the defence-in-depth behind the
     * capture-time guard, and a hole in it ships one tenant's records under
     * another tenant's prefix.
     *
     * @param string $body JSONL body.
     * @param string $expected Expected site id.
     * @param int $dropped Out-param: records dropped.
     * @return string Filtered body.
     */
    private function filter(string $body, string $expected, int &$dropped): string {
        $method = new \ReflectionMethod(shipper::class, 'filter_site_id');
        $method->setAccessible(true);
        $args = [$body, $expected, &$dropped];
        return $method->invokeArgs(null, $args);
    }

    /**
     * A record belonging to another tenant is dropped.
     */
    public function test_filter_drops_a_foreign_site_id(): void {
        $body = '{"id":"1","site_id":"mine"}' . "\n"
            . '{"id":"2","site_id":"theirs"}' . "\n"
            . '{"id":"3","site_id":"mine"}' . "\n";

        $dropped = 0;
        $out = $this->filter($body, 'mine', $dropped);

        $this->assertSame(1, $dropped);
        $this->assertStringNotContainsString('theirs', $out);
        $this->assertStringContainsString('"id":"1"', $out);
        $this->assertStringContainsString('"id":"3"', $out);
    }

    /**
     * When nothing is dropped the body comes back byte-for-byte, so the normal path
     * pays no recomposition cost.
     */
    public function test_filter_returns_the_body_unchanged_when_nothing_is_dropped(): void {
        $body = '{"id":"1","site_id":"mine"}' . "\n" . '{"id":"2","site_id":"mine"}' . "\n";

        $dropped = 0;
        $out = $this->filter($body, 'mine', $dropped);

        $this->assertSame(0, $dropped);
        $this->assertSame($body, $out);
    }

    /**
     * This guard validates ownership; it is not a JSON linter. A line that does not
     * parse, or that omits site_id entirely, is KEPT — deciding what to do with
     * malformed input is the middleware's job, and dropping it here would destroy
     * records on a judgement this function is not qualified to make.
     */
    public function test_filter_keeps_unparseable_and_site_less_lines(): void {
        $body = 'not json at all' . "\n"
            . '{"id":"2"}' . "\n"
            . '{"id":"3","site_id":"mine"}' . "\n";

        $dropped = 0;
        $out = $this->filter($body, 'mine', $dropped);

        $this->assertSame(0, $dropped);
        $this->assertSame($body, $out);
    }

    /**
     * Every record foreign gives an empty body, which the caller treats as
     * nothing to ship rather than shipping an empty object.
     */
    public function test_filter_returns_empty_when_every_record_is_foreign(): void {
        $body = '{"id":"1","site_id":"theirs"}' . "\n" . '{"id":"2","site_id":"theirs"}' . "\n";

        $dropped = 0;
        $out = $this->filter($body, 'mine', $dropped);

        $this->assertSame(2, $dropped);
        $this->assertSame('', $out);
    }

    /**
     * Comparison is on the string value, so a numeric-looking site id that differs
     * only in type is not treated as a match.
     */
    public function test_filter_compares_site_id_as_a_string(): void {
        $body = '{"id":"1","site_id":123}' . "\n";

        $dropped = 0;
        $this->filter($body, '123', $dropped);
        $this->assertSame(0, $dropped, 'numeric 123 and string "123" are the same tenant');

        $dropped = 0;
        $this->filter($body, '1234', $dropped);
        $this->assertSame(1, $dropped);
    }
}
