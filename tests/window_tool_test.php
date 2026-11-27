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

#[CoversClass(\local_intellistream\window_tool::class)]
#[CoversClass(\local_intellistream\exporter::class)]
#[CoversClass(\local_intellistream\services\config_service::class)]
#[CoversClass(\local_intellistream\task\window_recovery_adhoc_task::class)]
/**
 * Recovery window tool: what it ships, what it refuses.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\window_tool
 * @covers     \local_intellistream\exporter::window_record_id
 * @covers     \local_intellistream\services\config_service::is_reserved
 * @covers     \local_intellistream\task\window_recovery_adhoc_task
 */
final class window_tool_test extends \advanced_testcase {
    /** @var int window start used by every test (one day, inside the logstore retention) */
    private $from;

    /** @var int window end used by every test */
    private $to;

    /**
     * Pair the site, enable capture and the standard logstore.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        set_config('siteid', 'phpunit-window-site', 'local_intellistream');
        set_config('enabled', 1, 'local_intellistream');
        set_config('trackadmin', 1, 'local_intellistream');
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('loglifetime', 0, 'logstore_standard');
        config::refresh();
        $this->to = time() - DAYSECS;
        $this->from = $this->to - DAYSECS;
    }

    /**
     * Release the buffer handle.
     */
    protected function tearDown(): void {
        buffer::flush();
        parent::tearDown();
    }

    /**
     * Insert a logstore row.
     *
     * @param int $userid actor
     * @param int $time timecreated
     * @param int $courseid course
     * @return int logstore id
     */
    private function log(int $userid, int $time, int $courseid = 2): int {
        global $DB;
        return (int)$DB->insert_record('logstore_standard_log', (object)[
            'eventname' => '\\core\\event\\course_viewed', 'component' => 'core', 'action' => 'viewed',
            'target' => 'course', 'objecttable' => null, 'objectid' => null, 'crud' => 'r',
            'edulevel' => 2, 'contextid' => 99, 'contextlevel' => 50, 'contextinstanceid' => $courseid,
            'userid' => $userid, 'courseid' => $courseid, 'relateduserid' => null, 'anonymous' => 0,
            'other' => 'secret-other', 'timecreated' => $time, 'origin' => 'web', 'ip' => '10.1.2.3',
            'realuserid' => null,
        ]);
    }

    /**
     * Write a config_log change.
     *
     * @param string|null $plugin plugin (null = core)
     * @param string $name setting
     * @param string|null $old old value
     * @param string $new new value
     * @param int $at time
     */
    private function config_change(?string $plugin, string $name, ?string $old, string $new, int $at): void {
        global $DB;
        $DB->insert_record('config_log', (object)['userid' => 2, 'timemodified' => $at,
            'plugin' => $plugin, 'name' => $name, 'value' => $new, 'oldvalue' => $old]);
    }

    /**
     * Window records buffered since the last call, consuming the files.
     *
     * @return array[] [envelope, ...]
     */
    private function sent(): array {
        buffer::flush();
        $out = [];
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (is_array($rec) && ($rec['entity'] ?? '') === window_tool::ENTITY) {
                    $out[] = $rec;
                }
            }
            unlink($path);
        }
        return $out;
    }

    /**
     * Run a window with test defaults.
     *
     * @param array $extra options
     * @return array state
     */
    private function run_window(array $extra = []): array {
        return window_tool::run($extra + ['from' => $this->from, 'to' => $this->to]);
    }

    public function test_ships_every_row_and_a_final_request_record(): void {
        $a = $this->log(5, $this->from + 10);
        $b = $this->log(6, $this->from + 20);
        $this->log(7, $this->from - 5);                 // Outside the window.
        $state = $this->run_window();
        $this->assertSame('complete', $state['status']);
        $this->assertSame(2, $state['rows']);
        $recs = $this->sent();
        $rows = array_values(array_filter($recs, function ($r) {
            return $r['entity_data']['kind'] === 'row';
        }));
        $reqs = array_values(array_filter($recs, function ($r) {
            return $r['entity_data']['kind'] === 'request';
        }));
        $this->assertSame([$a, $b], array_map(function ($r) {
            return $r['entity_data']['logid'];
        }, $rows));
        $final = end($reqs)['entity_data'];
        $this->assertSame('complete', $final['status']);
        $this->assertSame(2, $final['row_count']);
        $this->assertSame($b, $final['max_logid']);
        $this->assertSame($state['request_id'], $final['request_id']);
        foreach ($rows as $r) {
            $this->assertSame('entity_snapshot', $r['record_type']);
            $this->assertArrayNotHasKey('ip', $r['entity_data']);
            $this->assertArrayNotHasKey('other', $r['entity_data']);
            $this->assertArrayNotHasKey('realuserid', $r['entity_data']);
            $this->assertSame((string)$r['entity_data']['logid'], $r['entity_data']['id']);
        }
    }

    public function test_two_runs_over_the_same_rows_get_different_ids(): void {
        $this->log(5, $this->from + 10);
        $first = $this->run_window();
        $ids1 = array_column($this->sent(), 'id');
        $second = $this->run_window();
        $ids2 = array_column($this->sent(), 'id');
        $this->assertNotSame($first['request_id'], $second['request_id']);
        $this->assertSame(
            [],
            array_intersect($ids1, $ids2),
            'the request is part of the id: the middleware would drop a repeated id'
        );
        $this->assertSame(
            exporter::window_record_id('phpunit-window-site', $first['request_id'], 'request'),
            end($ids1)
        );
    }

    public function test_course_filter(): void {
        $this->log(5, $this->from + 10, 2);
        $this->log(5, $this->from + 11, 3);
        $state = $this->run_window(['courseid' => 3]);
        $this->assertSame(1, $state['rows']);
    }

    public function test_trackadmin_off_leaves_site_admins_out(): void {
        global $CFG;
        set_config('trackadmin', 0, 'local_intellistream');
        config::refresh();
        $admin = (int)explode(',', $CFG->siteadmins)[0];
        $this->log($admin, $this->from + 10);
        $this->log($admin + 1000, $this->from + 11);
        $state = $this->run_window();
        $this->assertSame(1, $state['rows'], 'the live observer never captured the admin event');
    }

    public function test_refuses_when_capture_is_off_now(): void {
        set_config('enabled', 0, 'local_intellistream');
        config::refresh();
        $this->assertSame('refused', $this->run_window()['status']);
    }

    public function test_refuses_when_capture_was_off_during_the_window(): void {
        // Switched on after the window: during it, the old value (0) applied.
        $this->config_change('local_intellistream', 'enabled', '0', '1', $this->to + 60);
        $facts = window_tool::facts($this->from, $this->to);
        $this->assertStringContainsString('switched off', (string)$facts['refuse']);
    }

    public function test_refuses_a_capture_setting_change_inside_the_window(): void {
        $this->config_change('local_intellistream', 'trackadmin', '1', '0', $this->from + 100);
        $facts = window_tool::facts($this->from, $this->to);
        $this->assertStringContainsString('changed inside the window', (string)$facts['refuse']);
        $this->assertSame('trackadmin', $facts['config_changes'][0]['name']);
    }

    public function test_refuses_a_forced_setting(): void {
        global $CFG;
        $CFG->forced_plugin_settings['local_intellistream'] = ['enabled' => 1];
        $facts = window_tool::facts($this->from, $this->to);
        unset($CFG->forced_plugin_settings['local_intellistream']);
        $this->assertSame(['enabled'], $facts['forced']);
        $this->assertNotNull($facts['refuse']);
    }

    public function test_refuses_when_the_standard_logstore_is_off(): void {
        set_config('enabled_stores', '', 'tool_log');
        $this->assertStringContainsString('not enabled', (string)window_tool::facts($this->from, $this->to)['refuse']);
    }

    public function test_refuses_a_window_older_than_loglifetime(): void {
        set_config('loglifetime', 1, 'logstore_standard');
        $this->assertStringContainsString('retention', (string)window_tool::facts(
            $this->from - 5 * DAYSECS,
            $this->to - 5 * DAYSECS
        )['refuse']);
    }

    public function test_refuses_a_bad_window(): void {
        $this->assertNotNull(window_tool::facts($this->to, $this->from)['refuse']);
        $this->assertNotNull(window_tool::facts($this->from, time() + 3600)['refuse']);
    }

    public function test_a_window_over_max_rows_needs_confirm_large(): void {
        $this->log(5, $this->from + 1);
        $this->log(5, $this->from + 2);
        $this->assertSame('refused', $this->run_window(['max_rows' => 1])['status']);
        $this->assertSame('complete', $this->run_window(['max_rows' => 1, 'confirm_large' => true])['status']);
    }

    public function test_stop_ends_the_run_with_a_stopped_request_record(): void {
        $this->log(5, $this->from + 1);
        // A stop is set only for a live run: here a queued one, stopped before its first block.
        $rid = '0b6f2a8e-3c1d-4e5f-9a7b-1c2d3e4f5a6c';
        window_tool::save_queued($rid, $this->from, $this->to, null);
        $this->assertNull(window_tool::request_stop($rid));
        $state = $this->run_window(['request_id' => $rid]);
        $this->assertSame('stopped', $state['status']);
        $reqs = array_filter($this->sent(), function ($r) {
            return $r['entity_data']['kind'] === 'request';
        });
        $this->assertSame('stopped', end($reqs)['entity_data']['status']);
        $this->assertFalse(get_config('local_intellistream', window_tool::STOP_KEY));
    }

    public function test_a_paused_run_resumes_without_resending_rows(): void {
        $a = $this->log(5, $this->from + 1);
        $paused = $this->run_window(['max_seconds' => -1]);      // Deadline already passed.
        $this->assertTrue($paused['paused']);
        $done = window_tool::run(['resume' => $paused['request_id']]);
        $this->assertSame('complete', $done['status']);
        $rows = array_filter($this->sent(), function ($r) {
            return $r['entity_data']['kind'] === 'row';
        });
        $this->assertSame([$a], array_values(array_map(function ($r) {
            return $r['entity_data']['logid'];
        }, $rows)));
    }

    public function test_webhook_capture_switch_change_is_logged(): void {
        global $DB;
        // This test needs no logstore. With logstore_standard on, the config_log_created
        // event is buffered and written while PHPUnit resets the log manager ("unexpected
        // DB writes in reset_all_data").
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $m = new \ReflectionMethod(webhook_commands::class, 'set_plugin_config');
        $m->setAccessible(true);
        $m->invoke(null, 'trackadmin', '0');
        $this->assertTrue($DB->record_exists_select(
            'config_log',
            'plugin = :p AND name = :n AND ' . $DB->sql_compare_text('value') . ' = :v',
            ['p' => 'local_intellistream', 'n' => 'trackadmin', 'v' => '0']
        ));
        // A write that does not change the value is not logged again.
        $before = $DB->count_records('config_log', ['plugin' => 'local_intellistream', 'name' => 'trackadmin']);
        $m->invoke(null, 'trackadmin', '0');
        $this->assertSame($before, $DB->count_records(
            'config_log',
            ['plugin' => 'local_intellistream', 'name' => 'trackadmin']
        ));
    }

    public function test_logstore_and_window_names_are_reserved(): void {
        $this->assertTrue(services\config_service::is_reserved('anything', 'logstore_standard_log'));
        $this->assertTrue(services\config_service::is_reserved('window_x', 'user'));
        $this->assertFalse(services\config_service::is_reserved('custom', 'user'));
    }

    /**
     * Call a webhook command the way handle() does after the signature check.
     *
     * @param string $action command
     * @param array $payload payload
     * @return array [http status, body]
     */
    private function command(string $action, array $payload = []): array {
        $m = new \ReflectionMethod(webhook_commands::class, 'dispatch');
        $m->setAccessible(true);
        return $m->invoke(null, $action, $payload);
    }

    /**
     * Run the queued window adhoc task(s) once.
     */
    private function run_queued(): void {
        global $DB;
        $rows = $DB->get_records('task_adhoc', ['classname' => '\\' . task\window_recovery_adhoc_task::class]);
        $DB->delete_records('task_adhoc', ['classname' => '\\' . task\window_recovery_adhoc_task::class]);
        foreach ($rows as $r) {
            $t = new task\window_recovery_adhoc_task();
            $t->set_custom_data(json_decode($r->customdata));
            ob_start();
            try {
                $t->execute();
            } finally {
                ob_end_clean();
            }
        }
    }

    public function test_webhook_recover_window_rejects_a_bad_window(): void {
        [$code] = $this->command('recover_window', ['from' => $this->to, 'to' => $this->from]);
        $this->assertSame(400, $code);
    }

    public function test_webhook_recover_window_refuses_at_once(): void {
        set_config('enabled', 0, 'local_intellistream');
        config::refresh();
        [$code, $body] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to]);
        // Final, not a delivery failure: 200 + status refused, the reason in `detail` (the
        // IntelliBoard service's relay keeps only status and detail, and retries non-2xx / error).
        $this->assertSame(200, $code);
        $this->assertSame('refused', $body['status']);
        $this->assertStringContainsString('switched off', $body['detail']);
    }

    public function test_webhook_recover_window_runs_under_the_returned_request_id(): void {
        $this->log(5, $this->from + 10);
        [$code, $body] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to]);
        $this->assertSame(202, $code);
        $rid = $body['request_id'];
        $this->assertStringContainsString('request_id=' . $rid, $body['detail']);
        $this->run_queued();
        $recs = $this->sent();
        $this->assertNotEmpty($recs);
        foreach ($recs as $r) {
            $this->assertSame($rid, $r['entity_data']['request_id']);
        }
        [, $status] = $this->command('recover_window_status');
        $this->assertSame('complete', $status['data'][0]['status']);
        $this->assertSame($rid, $status['data'][0]['request_id']);
    }

    public function test_webhook_request_refused_when_the_task_starts_is_visible(): void {
        [, $body] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to]);
        // A capture setting changes inside the window before the task runs.
        $this->config_change('local_intellistream', 'trackadmin', '1', '0', $this->from + 50);
        $this->run_queued();
        [, $status] = $this->command('recover_window_status');
        $this->assertSame($body['request_id'], $status['data'][0]['request_id']);
        $this->assertSame('refused', $status['data'][0]['status']);
        $this->assertStringContainsString('changed inside the window', $status['data'][0]['reason']);
    }

    public function test_webhook_repeated_delivery_queues_one_run(): void {
        global $DB;
        $this->log(5, $this->from + 10);
        $rid = '0b6f2a8e-3c1d-4e5f-9a7b-1c2d3e4f5a6b';
        $payload = ['from' => $this->from, 'to' => $this->to, 'request_id' => $rid];
        [$code, $first] = $this->command('recover_window', $payload);
        $this->assertSame(202, $code);
        $this->assertSame($rid, $first['request_id']);
        // The relay lost the reply and delivers the same command again.
        [$code, $again] = $this->command('recover_window', $payload);
        $this->assertSame(202, $code);
        $this->assertSame($rid, $again['request_id']);
        $this->assertTrue($again['duplicate']);
        $this->assertStringContainsString('request_id=' . $rid, $again['detail']);
        $this->assertSame(1, $DB->count_records(
            'task_adhoc',
            ['classname' => '\\' . task\window_recovery_adhoc_task::class]
        ));
        $this->run_queued();
        foreach ($this->sent() as $r) {
            $this->assertSame($rid, $r['entity_data']['request_id']);
        }
        [, $status] = $this->command('recover_window_status', ['request_id' => $rid]);
        $this->assertStringContainsString("request_id={$rid} status=complete rows=1", $status['detail']);
    }

    public function test_webhook_refuses_a_known_request_id_for_a_different_window(): void {
        global $DB;
        $rid = '0b6f2a8e-3c1d-4e5f-9a7b-1c2d3e4f5a6e';
        [$code] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to, 'request_id' => $rid]);
        $this->assertSame(202, $code);
        $others = [
            ['from' => $this->from + 60, 'to' => $this->to],
            ['from' => $this->from, 'to' => $this->to, 'course' => 7],
        ];
        foreach ($others as $other) {
            [$code, $body] = $this->command('recover_window', $other + ['request_id' => $rid]);
            $this->assertSame(200, $code);
            $this->assertSame('refused', $body['status']);
            $this->assertStringContainsString('already used for a different window', $body['detail']);
        }
        // Still exactly one queued task, for the original window.
        $this->assertSame(1, $DB->count_records(
            'task_adhoc',
            ['classname' => '\\' . task\window_recovery_adhoc_task::class]
        ));
    }

    public function test_webhook_rejects_a_bad_request_id(): void {
        [$code, $body] = $this->command(
            'recover_window',
            ['from' => $this->from, 'to' => $this->to, 'request_id' => 'abc']
        );
        $this->assertSame(400, $code);
        $this->assertSame('invalid_request_id', $body['detail']);
        $this->assertSame(400, $this->command('recover_window_status', ['request_id' => 'abc'])[0]);
    }

    public function test_webhook_status_detail_shows_queued_then_a_size_refusal(): void {
        for ($i = 0; $i < 5; $i++) {
            $this->log(5, $this->from + 10 + $i);
        }
        [, $body] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to, 'max_rows' => 2]);
        $rid = $body['request_id'];
        [$code, $status] = $this->command('recover_window_status', ['request_id' => $rid]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString("request_id={$rid} status=queued", $status['detail']);
        $this->run_queued();
        [, $status] = $this->command('recover_window_status', ['request_id' => $rid]);
        $this->assertStringContainsString('status=refused', $status['detail']);
        $this->assertStringContainsString('over --max-rows 2', $status['detail']);
        // Newest runs when no id is given; an unknown id says so.
        $this->assertStringContainsString($rid, $this->command('recover_window_status')[1]['detail']);
        $this->assertSame(
            'request_id=11111111-2222-3333-4444-555555555555 status=unknown',
            $this->command('recover_window_status', ['request_id' => '11111111-2222-3333-4444-555555555555'])[1]['detail']
        );
    }

    public function test_a_busy_window_lock_requeues_the_task_instead_of_losing_the_run(): void {
        global $CFG, $DB;
        // Db_record locks are not re-entrant within one process, so the test can hold one.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $this->log(5, $this->from + 10);
        [, $body] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to]);
        $rid = $body['request_id'];
        $held = \core\lock\lock_config::get_lock_factory('local_intellistream')->get_lock('window_tool', 0);
        $this->assertNotFalse($held);
        try {
            $this->run_queued();
        } finally {
            $held->release();
        }
        $rows = $DB->get_records('task_adhoc', ['classname' => '\\' . task\window_recovery_adhoc_task::class]);
        $this->assertCount(1, $rows);
        $this->assertSame($rid, json_decode(reset($rows)->customdata)->request_id);
        $this->assertStringContainsString('status=queued', window_tool::summary($rid));
        $this->run_queued();
        $this->assertStringContainsString('status=complete rows=1', window_tool::summary($rid));
    }

    public function test_a_queued_or_refused_run_cannot_be_resumed(): void {
        [, $body] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to]);
        $state = window_tool::run(['resume' => $body['request_id']]);
        $this->assertSame('refused', $state['status']);
        $this->assertStringContainsString('is queued, not paused', $state['reason']);
    }

    public function test_capture_switched_off_before_the_task_starts_marks_the_queued_run_refused(): void {
        [, $body] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to]);
        set_config('enabled', 0, 'local_intellistream');
        config::refresh();
        $this->run_queued();
        $this->assertStringContainsString('status=refused', window_tool::summary($body['request_id']));
        $this->assertStringContainsString('disabled now', window_tool::summary($body['request_id']));
    }

    public function test_webhook_stop(): void {
        // Nothing queued or running: a '*' would stop the next run, so it is not set.
        [$code, $body] = $this->command('recover_window_stop', ['request_id' => '*']);
        $this->assertSame(200, $code);
        $this->assertSame('refused', $body['status']);
        $this->assertStringContainsString('no run is queued or running', $body['detail']);
        $this->assertFalse(get_config('local_intellistream', window_tool::STOP_KEY));

        $this->log(5, $this->from + 10);
        [, $start] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to]);
        [$code, $body] = $this->command('recover_window_stop', ['request_id' => '*']);
        $this->assertSame(200, $code);
        $this->assertSame('ok', $body['status']);
        $this->assertSame('*', get_config('local_intellistream', window_tool::STOP_KEY));
        $this->assertSame(400, $this->command('recover_window_stop')[0]);

        // The queued run sees the stop when its task starts, and the stop is then cleared.
        $this->run_queued();
        $this->assertStringContainsString('status=stopped', window_tool::summary($start['request_id']));
        $this->assertFalse(get_config('local_intellistream', window_tool::STOP_KEY));
        // A stop for a finished or unknown run is not set either.
        $this->assertStringContainsString('is stopped', (string)window_tool::request_stop($start['request_id']));
        $this->assertStringContainsString(
            'no run with request id',
            (string)window_tool::request_stop('11111111-2222-3333-4444-555555555555')
        );
        $this->assertFalse(get_config('local_intellistream', window_tool::STOP_KEY));
    }

    public function test_a_stop_for_a_run_refused_at_start_does_not_stop_the_next_run(): void {
        $this->log(5, $this->from + 10);
        [, $first] = $this->command('recover_window', ['from' => $this->from, 'to' => $this->to]);
        $this->assertNull(window_tool::request_stop('*'));
        set_config('enabled', 0, 'local_intellistream');
        config::refresh();
        $this->run_queued();
        $this->assertStringContainsString('status=refused', window_tool::summary($first['request_id']));
        $this->assertFalse(get_config('local_intellistream', window_tool::STOP_KEY));
    }

    public function test_anonymous_log_rows_are_never_shipped(): void {
        global $DB;
        $this->log(5, $this->from + 10);
        $anon = $this->log(6, $this->from + 11);
        $DB->set_field('logstore_standard_log', 'anonymous', 1, ['id' => $anon]);
        $this->assertSame(1, window_tool::estimate($this->from, $this->to)['rows']);
        $this->assertSame(1, $this->run_window()['rows']);
        foreach ($this->sent() as $r) {
            if ($r['entity_data']['kind'] !== 'request') {
                $this->assertSame(5, (int)$r['entity_data']['userid']);
            }
        }
    }

    public function test_webhook_stop_rejects_a_bad_request_id(): void {
        [$code, $body] = $this->command('recover_window_stop', ['request_id' => 'abc']);
        $this->assertSame(400, $code);
        $this->assertSame('invalid_request_id', $body['detail']);
        $this->assertFalse(get_config('local_intellistream', window_tool::STOP_KEY));
    }

    public function test_only_the_newest_finished_run_states_are_kept(): void {
        for ($i = 0; $i < window_tool::KEEP_FINISHED + 3; $i++) {
            $this->run_window(['request_id' => sprintf('00000000-0000-4000-8000-%012d', $i)]);
            $this->waitForSecond();
        }
        $runs = window_tool::runs();
        $this->assertCount(window_tool::KEEP_FINISHED, $runs);
        // The oldest three are gone; the newest is kept.
        $this->assertFalse(window_tool::known('00000000-0000-4000-8000-000000000000'));
        $this->assertTrue(window_tool::known(sprintf('00000000-0000-4000-8000-%012d', window_tool::KEEP_FINISHED + 2)));
    }

    /**
     * Insert a logstore row with a chosen id (to build a sparse id range).
     *
     * @param int $id logstore id
     * @param int $userid actor
     * @param int $time timecreated
     * @param int $courseid course
     */
    private function log_at(int $id, int $userid, int $time, int $courseid = 2): void {
        global $DB;
        $DB->insert_record_raw('logstore_standard_log', [
            'id' => $id, 'eventname' => '\\core\\event\\course_viewed', 'component' => 'core',
            'action' => 'viewed', 'target' => 'course', 'crud' => 'r', 'edulevel' => 2, 'contextid' => 99,
            'contextlevel' => 50, 'contextinstanceid' => $courseid, 'userid' => $userid,
            'courseid' => $courseid, 'anonymous' => 0, 'timecreated' => $time, 'origin' => 'web',
        ], false, false, true);
    }

    public function test_background_mode_pauses_after_a_slice_quota_instead_of_sleeping(): void {
        $first = $this->log(5, $this->from + 10);
        $this->log_at($first + 3 * window_tool::BLOCK, 5, $this->from + 20);
        // Minimum pace, 300 s slices: the quota is one block, so the first slice pauses.
        $state = $this->run_window(['rows_per_hour' => 1, 'yield' => 300]);
        $this->assertSame('running', $state['status']);
        $this->assertTrue($state['paused']);
        $this->assertStringContainsString('slice quota', $state['reason']);
        $this->assertSame(1, $state['rows']);
        // Resume at the top pace in background mode: one slice reads the rest, no sleep.
        $done = window_tool::run(['resume' => $state['request_id'], 'rows_per_hour' => window_tool::MAX_ROWS_PER_HOUR,
            'yield' => 300]);
        $this->assertSame('complete', $done['status']);
        $this->assertSame(2, $done['rows']);
        $this->assertSame(window_tool::MIN_ROWS_PER_HOUR, window_tool::clamp_rate(1));
        $this->assertSame(window_tool::MAX_ROWS_PER_HOUR, window_tool::clamp_rate(PHP_INT_MAX));
    }

    public function test_a_resumed_background_slice_keeps_the_requested_pace(): void {
        $first = $this->log(5, $this->from + 10);
        $this->log_at($first + 6000, 5, $this->from + 20);
        $this->log_at($first + 12000, 5, $this->from + 30);
        // Minimum pace: one block (5000 ids) per 300 s slice.
        $state = $this->run_window(['rows_per_hour' => window_tool::MIN_ROWS_PER_HOUR, 'yield' => 300]);
        $this->assertTrue($state['paused']);
        // The task's resume passes no pace: the run keeps the one it started with, so the
        // second slice also stops after one block instead of reading at the default pace.
        $next = window_tool::run(['resume' => $state['request_id'], 'rows_per_hour' => null, 'yield' => 300]);
        $this->assertTrue($next['paused']);
        $this->assertSame('running', $next['status']);
        $this->assertSame(window_tool::MIN_ROWS_PER_HOUR, $next['rows_per_hour']);
    }

    public function test_a_retried_first_slice_continues_the_run(): void {
        $first = $this->log(5, $this->from + 10);
        $this->log_at($first + 3 * window_tool::BLOCK, 5, $this->from + 20);
        $rid = '0b6f2a8e-3c1d-4e5f-9a7b-1c2d3e4f5a6d';
        $opts = ['request_id' => $rid, 'rows_per_hour' => 1, 'yield' => 300];
        $this->assertTrue($this->run_window($opts)['paused']);
        // Core re-runs the same adhoc record (same custom data) after a failure: the run goes on.
        $again = $this->run_window(['request_id' => $rid, 'rows_per_hour' => window_tool::MAX_ROWS_PER_HOUR, 'yield' => 300]);
        $this->assertSame('complete', $again['status']);
        $this->assertSame(2, $again['rows']);
    }

    public function test_status_data_leaves_out_the_window_facts(): void {
        global $CFG;
        set_config('trackadmin', 0, 'local_intellistream');
        config::refresh();
        $this->log(5, $this->from + 10);
        $this->run_window();
        [, $status] = $this->command('recover_window_status');
        $this->assertNotEmpty($status['data']);
        $this->assertArrayNotHasKey('facts', $status['data'][0]);
        $this->assertNotEmpty($CFG->siteadmins);
    }

    public function test_a_course_window_over_a_huge_id_span_is_refused(): void {
        $first = $this->log(5, $this->from + 10, 77);
        $this->log_at($first + window_tool::HARD_MAX_ROWS + 1, 5, $this->from + 20, 77);
        // Only 2 matching rows, but the run would read the whole id span between them.
        $state = $this->run_window(['courseid' => 77]);
        $this->assertSame('refused', $state['status']);
        $this->assertStringContainsString('logstore ids, over the hard ceiling', $state['reason']);
    }

    public function test_a_buffered_window_row_belongs_to_its_related_user_too(): void {
        global $DB;
        $id = $this->log(5, $this->from + 10);
        $DB->set_field('logstore_standard_log', 'relateduserid', 4243, ['id' => $id]);
        $this->assertSame(1, $this->run_window()['rows']);
        $this->assertTrue(buffer_privacy::has_user_records(4243));
        buffer_privacy::delete_user_records([4243]);
        $this->assertFalse(buffer_privacy::has_user_records(4243));
    }

    public function test_a_paused_run_whose_resume_is_refused_is_recorded_refused(): void {
        $first = $this->log(5, $this->from + 10);
        $this->log_at($first + 3 * window_tool::BLOCK, 5, $this->from + 20);
        $state = $this->run_window(['rows_per_hour' => 1, 'yield' => 300]);
        $this->assertTrue($state['paused']);
        set_config('enabled', 0, 'local_intellistream');
        config::refresh();
        window_tool::run(['resume' => $state['request_id']]);
        $this->assertStringContainsString('status=refused', window_tool::summary($state['request_id']));
        // A refused run is not live: a '*' stop is not set for it.
        $this->assertSame('no run is queued or running', window_tool::request_stop('*'));
    }

    public function test_refuses_a_window_that_ends_less_than_the_settle_time_ago(): void {
        global $DB;
        // This window reaches into the last hours: drop config changes written when the test
        // site was installed, so only the settle rule decides.
        $DB->delete_records('config_log');
        $refuse = (string)window_tool::facts($this->from, time() - window_tool::SETTLE_SECONDS + 60)['refuse'];
        $this->assertStringContainsString('minutes ago', $refuse);
        $this->assertNull(window_tool::facts($this->from, time() - window_tool::SETTLE_SECONDS - 60)['refuse']);
    }

    public function test_trackadmin_off_uses_admin_changes_logged_by_admins_php(): void {
        global $CFG;
        set_config('trackadmin', 0, 'local_intellistream');
        config::refresh();
        $current = (string)$CFG->siteadmins;
        $removed = 4242;
        // Admins.php logs add/remove with plugin 'core': X was an admin in the window and
        // was removed after it, so X's window events were dropped live and stay out.
        $this->config_change('core', 'siteadmins', $current . ',' . $removed, $current, $this->to + 100);
        $this->log($removed, $this->from + 10);
        $this->log($removed + 1, $this->from + 11);
        $facts = window_tool::facts($this->from, $this->to);
        $this->assertNull($facts['refuse']);
        $this->assertContains($removed, $facts['exclude_userids']);
        $this->assertSame(1, $this->run_window()['rows']);
        // A change inside the window, logged the same way, refuses it.
        $this->config_change('core', 'siteadmins', $current, $current . ',99', $this->from + 50);
        $this->assertStringContainsString('admin list changed', (string)window_tool::facts($this->from, $this->to)['refuse']);
    }

    public function test_a_resume_before_the_first_row_sends_the_request_record(): void {
        $this->log(5, $this->from + 1);
        $paused = $this->run_window(['max_seconds' => -1]);
        $this->assertTrue($paused['paused']);
        $this->assertSame(0, $paused['rows']);
        // The request record of the first slice never reached the pipeline (the buffer
        // refused it): a resume that still has no row sends it again.
        $this->sent();
        $again = window_tool::run(['resume' => $paused['request_id'], 'max_seconds' => -1]);
        $this->assertTrue($again['paused']);
        $reqs = array_filter($this->sent(), function ($r) {
            return $r['entity_data']['kind'] === 'request';
        });
        $this->assertCount(1, $reqs);
        $this->assertSame('running', reset($reqs)['entity_data']['status']);
        $this->assertSame($paused['request_id'], reset($reqs)['entity_data']['request_id']);
    }
}
