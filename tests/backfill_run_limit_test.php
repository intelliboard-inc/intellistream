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
 * Tests for the time limit of a historical backfill run from a task.
 *
 * A run from the scheduled or the ad-hoc task stops between two pages once its time
 * is up, leaves the entity not done, and the next run continues after the last row
 * sent. It does not ship inline. The CLI backfill runs to the end, as before.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\backfill::run
 * @covers     \local_intellistream\backfill::run_limit_sec
 * @covers     \local_intellistream\exporter::export_entity_window
 * @covers     \local_intellistream\task\run_backfill_adhoc_task::execute
 * @covers     \local_intellistream\task\historical_backfill::execute
 * @covers     \local_intellistream\backfill::ensure_queued
 * @covers     \local_intellistream\backfill::lock_lifetime
 * @covers     \local_intellistream\backfill::entity_cutoff
 * @covers     \local_intellistream\task\run_backfill_adhoc_task::moved
 */
final class backfill_run_limit_test extends \advanced_testcase {
    /**
     * Pair the site, use the smallest page, and pin the site's own users below every
     * row the tests make.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $DB->set_field_select('user', 'timemodified', 1000000000, 'id > 0');
        buffer::flush();
        set_config('siteid', 'phpunit-backfill-site', 'local_intellistream');
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
        // One test sets this process's own-bytes count; do not carry it into the next.
        buffer::note_measured(1);
        parent::tearDown();
    }

    /**
     * Ids of `user` rows buffered since the last call, consuming the files.
     *
     * @return int[] In buffer order, duplicates kept.
     */
    private function sent(): array {
        buffer::flush();
        $ids = [];
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (is_array($rec) && ($rec['entity'] ?? '') === 'user' && ($rec['record_type'] ?? '') === 'entity_snapshot') {
                    $ids[] = (int)$rec['entity_data']['id'];
                }
            }
            unlink($path);
        }
        return $ids;
    }

    /**
     * More user rows than two pages, and the ids of every user row, ascending.
     *
     * @return int[]
     */
    private function make_users(): array {
        global $DB;
        for ($i = 0; $i < 2 * exporter::PAGE_ROWS_MIN + 50; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $DB->set_field('user', 'timemodified', 1000000100, ['id' => $user->id]);
        }
        $this->sent(); // The observers buffered the creations; drop them.
        return array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {user} ORDER BY id'));
    }

    /**
     * Run the backfill for `user` alone, its output captured.
     *
     * @param int|null $timelimit
     * @return array [result, output]
     */
    private function run_user(?int $timelimit): array {
        ob_start();
        try {
            $result = backfill::run(['user'], $timelimit);
        } finally {
            buffer::flush();
            $out = (string)ob_get_clean();
        }
        return [$result, $out];
    }

    /**
     * A run whose time is up stops between two pages: one page read, the entity not
     * done, the watermark at the page's last id, and no stall counted. Runs after it
     * continue there, and together they send every row once, in id order.
     */
    public function test_time_limit_stops_between_pages_and_resumes(): void {
        $all = $this->make_users();

        [$result, $out] = $this->run_user(0);
        $first = $this->sent();
        $this->assertFalse($result['complete']);
        $this->assertSame('timelimit', $result['reason']);
        $this->assertSame(exporter::PAGE_ROWS_MIN, $result['rows'], 'the rows of the entity it stopped in count');
        $this->assertSame(array_slice($all, 0, exporter::PAGE_ROWS_MIN), $first, 'one page, in id order');
        $this->assertStringContainsString('stopped at the run time limit', $out);
        $this->assertFalse(get_config('local_intellistream', 'backfill_done_user'), 'not done');
        $this->assertSame(end($first), (int)get_config('local_intellistream', 'backfill_wm_user'));
        $this->assertFalse(get_config('local_intellistream', 'backfill_noprog_user'), 'no stall counted');

        $sent = $first;
        for ($i = 0; $i < 10 && !get_config('local_intellistream', 'backfill_done_user'); $i++) {
            $this->run_user(0);
            $sent = array_merge($sent, $this->sent());
        }
        $this->assertSame($all, $sent, 'every row once, in id order');
        $this->assertSame('1', (string)get_config('local_intellistream', 'backfill_done_user'));
    }

    /**
     * Without a time limit (the CLI) the run reads the table to its end, as before.
     */
    public function test_no_time_limit_reads_to_the_end(): void {
        $all = $this->make_users();
        // A closed local port: the inline ship fails at once, without the network.
        set_config('endpoint', 'http://127.0.0.1:9', 'local_intellistream');
        set_config('ship_state', 'phpunit-marker', 'local_intellistream');
        [$result] = $this->run_user(null);
        $this->assertSame('partial', $result['reason']);
        $this->assertSame($all, $this->sent());
        $this->assertSame('1', (string)get_config('local_intellistream', 'backfill_done_user'));
        $this->assertNotSame('phpunit-marker', get_config('local_intellistream', 'ship_state'), 'the CLI run ships inline');
    }

    /**
     * A time-limited run never ships inline: the ship_events task sends what it buffered.
     */
    public function test_time_limited_run_does_not_ship_inline(): void {
        $this->make_users();
        set_config('endpoint', 'http://127.0.0.1:9', 'local_intellistream');
        set_config('ship_state', 'phpunit-marker', 'local_intellistream');
        for ($i = 0; $i < 4; $i++) {
            $this->run_user(0);
        }
        $this->assertTrue((bool)get_config('local_intellistream', 'backfill_done_user'), 'the runs read the table');
        $this->assertSame('phpunit-marker', get_config('local_intellistream', 'ship_state'), 'nothing was shipped');
    }

    /**
     * Over half the buffer cap, a time-limited run pauses without shipping inline, and
     * leaves the rows already read for the ship_events task. Only the CLI run ships.
     */
    public function test_backlog_over_half_pauses_without_shipping(): void {
        $this->make_users();
        set_config('endpoint', 'http://127.0.0.1:9', 'local_intellistream');
        set_config('exportbatchsize', 50, 'local_intellistream');
        set_config('maxbuffergb', 1, 'local_intellistream');
        $dir = config::buffer_dir();
        make_writable_directory($dir);
        // A sparse closed file over half the cap: measured as backlog, without writing it.
        $big = $dir . '/events-1-1-phpunit-h' . buffer::host_id() . '.jsonl.closed';
        $fh = fopen($big, 'c');
        ftruncate($fh, 600 * 1024 * 1024);
        fclose($fh);
        buffer::measure($dir);
        set_config('ship_state', 'phpunit-marker', 'local_intellistream');
        try {
            [$result, $out] = $this->run_user(backfill::RUN_LIMIT_SEC);
        } finally {
            unlink($big);
        }
        $this->assertSame('paused', $result['reason']);
        $this->assertStringContainsString('the ship_events task sends it', $out);
        $this->assertSame('phpunit-marker', get_config('local_intellistream', 'ship_state'), 'nothing was shipped');
        $this->assertFalse(get_config('local_intellistream', 'backfill_done_user'), 'not done');
    }

    /**
     * A run whose time is up before its first entity still reads that entity: a run
     * never stops without moving the campaign forward.
     */
    public function test_a_run_always_reads_something(): void {
        $this->make_users();
        [$result] = $this->run_user(0);
        $this->assertSame('timelimit', $result['reason']);
        $this->assertGreaterThan(0, (int)get_config('local_intellistream', 'backfill_wm_user'));
    }

    /**
     * The tuning setting: a whole number overrides the default, anything else is ignored.
     */
    public function test_run_limit_sec(): void {
        $this->assertSame(backfill::RUN_LIMIT_SEC, backfill::run_limit_sec());
        set_config('backfillrunsec', '0', 'local_intellistream');
        $this->assertSame(0, backfill::run_limit_sec());
        set_config('backfillrunsec', 'abc', 'local_intellistream');
        $this->assertSame(backfill::RUN_LIMIT_SEC, backfill::run_limit_sec());
    }

    /**
     * The ad-hoc task queues the next run after a stop by the time limit, a partial
     * request included (before, a partial request was re-queued only when its run could
     * not start), and starts its back-off again.
     */
    public function test_adhoc_task_queues_the_next_run_after_a_time_stop(): void {
        global $DB;
        $this->make_users();
        set_config('backfillrunsec', '0', 'local_intellistream');
        $DB->delete_records('task_adhoc', ['component' => 'local_intellistream']);
        $task = new task\run_backfill_adhoc_task();
        $task->set_custom_data(['only' => ['user'], 'attempt' => 5]);
        ob_start();
        try {
            $task->execute();
        } finally {
            buffer::flush();
            ob_end_clean();
        }
        $queued = $DB->get_records('task_adhoc', ['classname' => '\\' . task\run_backfill_adhoc_task::class]);
        $this->assertCount(1, $queued, 'the next run is queued');
        $next = reset($queued);
        $data = json_decode($next->customdata);
        $this->assertSame(['user'], $data->only);
        $this->assertSame(1, (int)$data->attempt, 'the back-off starts again');
        $this->assertLessThanOrEqual(time() + 60, (int)$next->nextruntime);
    }

    /**
     * Run the ad-hoc task with the given custom data, its output captured, and return
     * the ad-hoc task it queued (or null).
     *
     * @param array $data
     * @return \stdClass|null
     */
    private function run_adhoc(array $data): ?\stdClass {
        global $DB;
        $DB->delete_records('task_adhoc', ['component' => 'local_intellistream']);
        $task = new task\run_backfill_adhoc_task();
        $task->set_custom_data($data);
        ob_start();
        try {
            $task->execute();
        } finally {
            buffer::flush();
            ob_end_clean();
        }
        $queued = $DB->get_records('task_adhoc', ['classname' => '\\' . task\run_backfill_adhoc_task::class]);
        $this->assertLessThanOrEqual(1, count($queued));
        return $queued ? reset($queued) : null;
    }

    /**
     * Put a sparse closed file over half of a 1 GB buffer cap and publish the measurement,
     * so the buffer reads as over half full without writing it.
     *
     * @return string The file, for the caller to delete.
     */
    private function fill_over_half(): string {
        set_config('maxbuffergb', 1, 'local_intellistream');
        $dir = config::buffer_dir();
        make_writable_directory($dir);
        $big = $dir . '/events-1-1-phpunit-h' . buffer::host_id() . '.jsonl.closed';
        $fh = fopen($big, 'c');
        ftruncate($fh, 600 * 1024 * 1024);
        fclose($fh);
        buffer::measure($dir);
        return $big;
    }

    /**
     * A run whose time is up after it finished one entity stops before the next one, and
     * reports that entity.
     */
    public function test_time_limit_stops_between_entities(): void {
        [$result] = (function () {
            ob_start();
            try {
                return [backfill::run(['user', 'course'], 0)];
            } finally {
                buffer::flush();
                ob_end_clean();
            }
        })();
        $this->assertSame('timelimit', $result['reason']);
        $this->assertSame('course', $result['entity']);
        $this->assertSame('1', (string)get_config('local_intellistream', 'backfill_done_user'));
        $this->assertFalse(get_config('local_intellistream', 'backfill_wm_course'), 'course not started');
    }

    /**
     * The scheduled task runs the backfill with the time limit.
     */
    public function test_scheduled_task_is_time_limited(): void {
        $this->make_users();
        set_config('backfillrunsec', '0', 'local_intellistream');
        set_config('endpoint', 'http://127.0.0.1:9', 'local_intellistream');
        ob_start();
        try {
            (new task\historical_backfill())->execute();
        } finally {
            buffer::flush();
            $out = (string)ob_get_clean();
        }
        $this->assertStringContainsString('not complete — timelimit', $out);
        $this->assertFalse(get_config('local_intellistream', 'backfill_done_user'));
    }

    /**
     * A full (not held) request whose run pauses on a full buffer is queued again (the
     * shape of a control-plane reset on an existing site), no sooner than one run limit.
     */
    public function test_adhoc_requeues_a_paused_request(): void {
        $this->make_users();
        set_config('exportbatchsize', 50, 'local_intellistream');
        $big = $this->fill_over_half();
        try {
            $next = $this->run_adhoc(['only' => [], 'held' => 0]);
        } finally {
            unlink($big);
        }
        $this->assertNotNull($next, 'the paused campaign is queued again');
        $this->assertGreaterThanOrEqual(time() + backfill::RUN_LIMIT_SEC - 5, (int)$next->nextruntime);
    }

    /**
     * A new install's held chain ends when the hold is turned off (`backfillgate` 0, as in
     * setUp), and `backfillstop` ends every chain and every task run.
     */
    public function test_off_switches_end_the_chain(): void {
        $this->make_users();
        set_config('backfillrunsec', '0', 'local_intellistream');
        $this->assertNull($this->run_adhoc(['only' => [], 'held' => 1]), 'held chain, hold off');

        set_config('backfillstop', 1, 'local_intellistream');
        ob_start();
        try {
            $result = backfill::run(['user'], 0);
        } finally {
            ob_end_clean();
        }
        $this->assertSame('stopped', $result['reason']);
        $this->assertNull($this->run_adhoc(['only' => ['user'], 'held' => 0]), 'stopped');
    }

    /**
     * With no published measurement (ship_events not run yet, disabled or failing) and an
     * empty buffer, a time-limited run measures instead of pausing.
     */
    public function test_no_measurement_does_not_pause(): void {
        $this->make_users();
        set_config('exportbatchsize', 50, 'local_intellistream');
        @unlink(config::buffer_dir() . '/.capacity.json');
        [$result] = $this->run_user(0);
        $this->assertSame('timelimit', $result['reason'], 'not paused');
        $this->assertSame(exporter::PAGE_ROWS_MIN, $result['rows']);
    }

    /**
     * This process's own-bytes count keeps bytes since its last exact measure, shipped or
     * not; a time-limited run does not pause on that count while the buffer is empty.
     */
    public function test_own_bytes_count_does_not_pause(): void {
        $this->make_users();
        set_config('exportbatchsize', 50, 'local_intellistream');
        set_config('maxbuffergb', 1, 'local_intellistream');
        make_writable_directory(config::buffer_dir());
        buffer::measure(config::buffer_dir());
        $own = new \ReflectionProperty(buffer::class, 'ownbytes');
        if (PHP_VERSION_ID < 80100) {
            $own->setAccessible(true);
        }
        $own->setValue(null, 600 * 1024 * 1024); // As if this process had written 600 MB, since shipped.
        [$result] = $this->run_user(0);
        $this->assertSame('timelimit', $result['reason'], 'not paused');
    }

    /**
     * Once every entity is done, a run whose time is up does not start the final census:
     * the next run does, and completes the campaign.
     */
    public function test_final_census_starts_its_own_run(): void {
        foreach (array_keys(exporter::registry_with_overrides()) as $entity) {
            if ($entity !== 'user') {
                set_config('backfill_done_' . $entity, 1, 'local_intellistream');
            }
        }
        set_config('endpoint', 'http://127.0.0.1:9', 'local_intellistream');
        ob_start();
        try {
            $first = backfill::run([], 0);
            $second = backfill::run([], 0);
        } finally {
            buffer::flush();
            ob_end_clean();
        }
        $this->assertSame('timelimit', $first['reason'], 'the census waits for a run of its own');
        $this->assertFalse($first['complete']);
        $this->assertTrue($second['complete'], 'the next run writes the census and completes');
    }

    /**
     * A new install's held chain runs through the census to completion: the hold list
     * empties as the held entities finish, and the census still has to run.
     */
    public function test_held_chain_runs_to_completion(): void {
        set_config('backfillgate', 1, 'local_intellistream');
        set_config('backfillrunsec', '0', 'local_intellistream');
        foreach (array_keys(exporter::registry_with_overrides()) as $entity) {
            if (!in_array($entity, ['user', 'course'], true)) {
                set_config('backfill_done_' . $entity, 1, 'local_intellistream');
            }
        }
        set_config(backfill::GATE_KEY, json_encode(['user', 'course']), 'local_intellistream');
        $data = ['only' => [], 'held' => 1];
        for ($i = 0; $i < 10 && !backfill::is_complete(); $i++) {
            $next = $this->run_adhoc($data);
            if (!$next) {
                break;
            }
            $data = json_decode($next->customdata, true);
        }
        $this->assertTrue(backfill::is_complete(), 'the census was written and the campaign completed');
    }

    /**
     * A run that pauses on a buffer over half its cap reads nothing (the check comes
     * before the first page), and counts as no progress, so the back-off grows.
     */
    public function test_pause_on_a_full_buffer_moves_nothing(): void {
        $this->make_users();
        $big = $this->fill_over_half();
        try {
            [$result] = $this->run_user(backfill::RUN_LIMIT_SEC);
            $this->assertSame('paused', $result['reason']);
            $this->assertSame(0, $result['rows'], 'nothing read');
            $next = $this->run_adhoc(['only' => ['user'], 'held' => 0, 'attempt' => 2]);
        } finally {
            unlink($big);
        }
        $this->assertNotNull($next);
        $this->assertSame(3, (int)json_decode($next->customdata)->attempt, 'the back-off grows');
    }

    /**
     * Remove every queued backfill run. Selected by class: Moodle 3.9 does not set the
     * component of a queued task.
     */
    private function clear_chains(): void {
        global $DB;
        $DB->delete_records_select(
            'task_adhoc',
            'classname = :a OR classname = :b',
            ['a' => '\\' . task\run_backfill_adhoc_task::class, 'b' => task\run_backfill_adhoc_task::class]
        );
    }

    /**
     * Queue a backfill run as the control plane would, with the given customdata and
     * failure delay, and the next run time relative to now.
     *
     * @param array $data
     * @param int $in Seconds from now to its next run (0: due now).
     * @param int $faildelay
     * @return int Its task_adhoc id.
     */
    private function queue_chain(array $data, int $in = 0, int $faildelay = 0): int {
        global $DB;
        $task = new task\run_backfill_adhoc_task();
        $task->set_custom_data($data);
        $task->set_next_run_time(time() + $in);
        $id = (int)\core\task\manager::queue_adhoc_task($task);
        if ($faildelay) {
            $DB->set_field('task_adhoc', 'faildelay', $faildelay, ['id' => $id]);
        }
        return $id;
    }

    /**
     * Run the queued task $id as cron does (its own row stays queued while it runs), and
     * return the ids of the backfill rows queued after it.
     *
     * @param int $id
     * @return int[]
     */
    private function run_queued(int $id): array {
        global $DB;
        $record = $DB->get_record('task_adhoc', ['id' => $id], '*', MUST_EXIST);
        $task = \core\task\manager::adhoc_task_from_record($record);
        ob_start();
        try {
            $task->execute();
        } finally {
            buffer::flush();
            ob_end_clean();
        }
        return array_map('intval', $DB->get_fieldset_select(
            'task_adhoc',
            'id',
            '(classname = :a OR classname = :b) AND id > :id',
            ['a' => '\\' . task\run_backfill_adhoc_task::class, 'b' => task\run_backfill_adhoc_task::class, 'id' => $id]
        ));
    }

    /**
     * A chain does not queue its next run while another chain's next run, queued for
     * later, covers the request; a run that is itself queued is not covered by its own row.
     */
    public function test_a_second_chain_is_not_queued(): void {
        $this->make_users();
        set_config('backfillrunsec', '0', 'local_intellistream');
        $this->clear_chains();

        $me = $this->queue_chain(['only' => ['user'], 'held' => 0]);
        $this->assertCount(1, $this->run_queued($me), 'its own row does not stop it');

        $this->clear_chains();
        $this->queue_chain(['only' => []], 600);
        $me = $this->queue_chain(['only' => ['user'], 'held' => 0]);
        $this->assertSame([], $this->run_queued($me), 'the other chain carries the request');
    }

    /**
     * Only a row that will run covers a request: not a failed one, not one held
     * differently, not another partial list. Of two runs due now, the lower id continues.
     */
    public function test_only_rows_that_will_run_cover_a_request(): void {
        global $DB;
        $this->make_users();
        set_config('backfillrunsec', '0', 'local_intellistream');
        $this->clear_chains();
        $this->queue_chain(['only' => []], 600, 60);
        $this->queue_chain(['only' => [], 'held' => 1], 600);
        $this->queue_chain(['only' => ['course']], 600);
        $me = $this->queue_chain(['only' => ['user'], 'held' => 0]);
        $this->assertCount(1, $this->run_queued($me), 'none of them covers it');

        $this->clear_chains();
        $this->queue_chain(['only' => []]);
        $me = $this->queue_chain(['only' => []]);
        $this->assertSame([], $this->run_queued($me), 'the lower id continues');

        $this->clear_chains();
        $me = $this->queue_chain(['only' => []]);
        $this->queue_chain(['only' => []]);
        $this->assertCount(1, array_filter($this->run_queued($me), function ($id) use ($DB) {
            return $DB->get_field('task_adhoc', 'nextruntime', ['id' => $id]) > time();
        }), 'the lower id queues its next run');
    }

    /**
     * Progress for the back-off: a time stop, or rows buffered, even when the run then
     * paused. A run that moved nothing does not count.
     */
    public function test_a_paused_run_with_rows_moved(): void {
        $this->assertTrue(task\run_backfill_adhoc_task::moved(['reason' => 'paused', 'rows' => 500]));
        $this->assertTrue(task\run_backfill_adhoc_task::moved(['reason' => 'timelimit', 'rows' => 0]));
        $this->assertTrue(task\run_backfill_adhoc_task::moved(['reason' => 'incomplete', 'rows' => 1]));
        $this->assertFalse(task\run_backfill_adhoc_task::moved(['reason' => 'paused', 'rows' => 0]));
        $this->assertFalse(task\run_backfill_adhoc_task::moved(['reason' => 'busy']));
    }

    /**
     * The stop switch also keeps the 15-minute task from queueing a held backfill, and
     * shows in the progress report.
     */
    public function test_stop_switch_blocks_queueing(): void {
        set_config('backfillgate', 1, 'local_intellistream');
        set_config(backfill::GATE_KEY, json_encode(['user']), 'local_intellistream');
        set_config('backfillstop', 1, 'local_intellistream');
        $this->assertFalse(backfill::ensure_queued());
        $this->assertSame(1, backfill::progress()['stopped']);
        $this->setAdminUser();
        $status = external\get_status::clean_returnvalue(
            external\get_status::execute_returns(),
            external\get_status::execute()
        );
        $this->assertSame(1, $status['backfill_stopped'], 'the web service reports it');
        set_config('backfillstop', 0, 'local_intellistream');
        ob_start();
        try {
            $this->assertTrue(backfill::ensure_queued(), 'queued again once cleared');
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Lock lifetime, the derived-entity cutoff and the cap on the tuning setting.
     */
    public function test_lifetime_cutoff_and_cap(): void {
        $this->assertSame(3 * DAYSECS, backfill::lock_lifetime(null), 'CLI');
        $this->assertSame(DAYSECS, backfill::lock_lifetime(600), 'task run: longer than its unbounded steps');
        $this->assertSame(2 * DAYSECS, backfill::lock_lifetime(DAYSECS), 'task run: twice a long limit');
        $this->assertSame(1000, backfill::entity_cutoff(true, 1000, 600), 'keyset: the deadline');
        $this->assertSame(700, backfill::entity_cutoff(false, 1000, 600), 'derived: half the limit earlier');
        set_config('backfillrunsec', '999999', 'local_intellistream');
        $this->assertSame(DAYSECS, backfill::run_limit_sec());
    }
}
