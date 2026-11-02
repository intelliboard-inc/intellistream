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
 * Tests the login counts kept by the count_userlogins task.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\userlogins
 * @covers     \local_intellistream\exporter::request_derived
 * @covers     \local_intellistream\exporter::export_entity_range
 * @covers     \local_intellistream\exporter::sweep_kind
 * @covers     \local_intellistream\privacy\provider
 */
final class userlogins_test extends \advanced_testcase {
    /** @var int Next fake log row time. */
    private $time = 1700000000;

    /**
     * Enabled, paired, with a destination, and blocks of a few ids so a small log spans many.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        set_config('enabled', 1, 'local_intellistream');
        set_config('siteid', 'phpunit-logins-site', 'local_intellistream');
        set_config('endpoint', 'https://s3.example.com', 'local_intellistream');
        set_config('bucket', 'phpunit-bucket', 'local_intellistream');
        set_config('accesskey', 'phpunit-access', 'local_intellistream');
        set_config('secretkey', 'phpunit-secret', 'local_intellistream');
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('ulminwidth', 3, 'local_intellistream');
        set_config('ulmaxwidth', 7, 'local_intellistream');
        exporter::reset_registry_cache();
        $this->drain();
    }

    /**
     * Release this process's buffer handle.
     */
    protected function tearDown(): void {
        buffer::flush();
        exporter::reset_registry_cache();
        parent::tearDown();
    }

    /**
     * Add log rows: logins of the given users, plus rows the count must ignore.
     *
     * @param int[] $userids One login per entry (repeat an id for more).
     * @return void
     */
    private function log(array $userids): void {
        global $DB;
        $sys = \context_system::instance()->id;
        $course = \context_course::instance(SITEID)->id;
        $rows = [];
        foreach ($userids as $userid) {
            $rows[] = $this->logrow('\\core\\event\\user_loggedin', $sys, $userid);
            // Never counted: another event, another context, the guest-less user 0.
            $rows[] = $this->logrow('\\core\\event\\user_loggedout', $sys, $userid);
            $rows[] = $this->logrow('\\core\\event\\user_loggedin', $course, $userid);
        }
        $rows[] = $this->logrow('\\core\\event\\user_loggedin', $sys, 0);
        $DB->insert_records('logstore_standard_log', $rows);
    }

    /**
     * One log row.
     *
     * @param string $eventname
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    private function logrow(string $eventname, int $contextid, int $userid): array {
        return ['eventname' => $eventname, 'component' => 'core', 'action' => 'loggedin', 'target' => 'user',
            'objecttable' => 'user', 'objectid' => $userid, 'crud' => 'r', 'edulevel' => 0, 'contextid' => $contextid,
            'contextlevel' => CONTEXT_SYSTEM, 'contextinstanceid' => 0, 'userid' => $userid, 'courseid' => 0,
            'relateduserid' => null, 'anonymous' => 0, 'other' => 'N;', 'timecreated' => $this->time++,
            'origin' => 'web', 'ip' => '127.0.0.1', 'realuserid' => null];
    }

    /**
     * The count today's single query gives.
     *
     * @return array User id => logins.
     */
    private function expected(): array {
        global $DB;
        $out = $DB->get_records_sql_menu(
            'SELECT userid, COUNT(1) FROM {logstore_standard_log}
              WHERE eventname = :e AND contextid = :c AND userid > 0 GROUP BY userid',
            ['e' => userlogins::EVENT, 'c' => \context_system::instance()->id]
        );
        ksort($out);
        return array_map('intval', $out);
    }

    /**
     * The stored totals.
     *
     * @return array User id => logins.
     */
    private function stored(): array {
        global $DB;
        return array_map('intval', $DB->get_records_menu(userlogins::TABLE, null, 'userid', 'userid, logins'));
    }

    /**
     * Totals buffered since the last call, consuming the files.
     *
     * @return array User id => logins (the last one sent per user).
     */
    private function drain(): array {
        buffer::flush();
        $out = [];
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (is_array($rec) && ($rec['entity'] ?? '') === userlogins::ENTITY) {
                    $out[(int)$rec['entity_data']['id']] = (int)$rec['entity_data']['logins'];
                }
            }
            unlink($path);
        }
        ksort($out);
        return $out;
    }

    /**
     * One task run, its output captured.
     *
     * @return array userlogins::run() result.
     */
    private function run_task(): array {
        ob_start();
        try {
            return userlogins::run(600.0);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Make the highest log id seen old enough to be counted.
     */
    private function age_upper(): void {
        global $DB;
        $DB->execute('UPDATE {' . userlogins::STATE . '} SET nextupperat = nextupperat - :d', ['d' => userlogins::UPPER_DELAY]);
    }

    /**
     * Count everything logged so far: the first run notes the highest id, the second counts
     * up to it (each after the waiting id has aged).
     */
    private function settle(): void {
        $this->age_upper();
        $this->run_task();
        $this->age_upper();
        $this->run_task();
    }

    /**
     * The state row.
     *
     * @return \stdClass
     */
    private function state(): \stdClass {
        global $DB;
        return $DB->get_record(userlogins::STATE, [], '*', MUST_EXIST);
    }

    /**
     * The first count equals today's query, and every total is sent once it ends.
     */
    public function test_first_count_matches_and_sends_all(): void {
        $this->log([5, 5, 6, 7, 7, 7, 8]);
        $this->run_task();
        $this->assertSame([], $this->stored(), 'the newest ids wait UPPER_DELAY before they are counted');
        $this->age_upper();
        $result = $this->run_task();
        $this->assertGreaterThan(2, $result['blocks'], 'the log was read in several blocks');
        $this->assertSame($this->expected(), $this->stored());
        $this->assertSame($this->expected(), $this->drain(), 'every total sent at the end of the first count');
        $this->assertSame('current', userlogins::status()['state']);
    }

    /**
     * Nothing is sent while a count from zero is running.
     */
    public function test_nothing_is_sent_during_a_count_from_zero(): void {
        global $DB;
        $this->log([5, 6]);
        $this->run_task();
        $this->age_upper();
        // The count cannot reach its target yet.
        $max = (int)$DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}');
        $DB->set_field(userlogins::STATE, 'passtarget', $max + 1000, []);
        $this->run_task();
        $this->assertSame($this->expected(), $this->stored());
        $this->assertSame([], $this->drain());
        $this->assertSame('first_count', userlogins::status()['state']);
    }

    /**
     * New logins are added to the totals, and only the changed totals are sent.
     */
    public function test_new_logins_add_and_only_changes_are_sent(): void {
        $this->log([5, 6, 7]);
        $this->settle();
        $this->drain();
        $this->log([6, 6, 9]);
        $this->run_task();
        $this->run_task();
        $this->assertSame([5 => 1, 6 => 1, 7 => 1], $this->stored(), 'new ids are not counted before UPPER_DELAY');
        $this->settle();
        $this->assertSame($this->expected(), $this->stored());
        $this->assertSame([6 => 3, 9 => 1], $this->drain());
    }

    /**
     * A recount request replaces wrong totals with a new count from zero, then sends them all.
     */
    public function test_recount_request(): void {
        global $DB;
        $this->log([5, 6]);
        $this->settle();
        $this->drain();
        // Totals that differ from the log, as a repair would find them.
        $DB->set_field(userlogins::TABLE, 'logins', 99, ['userid' => 5]);
        userlogins::request_recount();
        $this->run_task();
        $this->assertSame($this->expected(), $this->stored());
        $this->assertSame($this->expected(), $this->drain());
        $this->assertSame((int)$this->state()->resetreq, (int)$this->state()->resetdone);
    }

    /**
     * A re-send request, and the targeted re-fetch and backfill paths, never read the log.
     */
    public function test_requests_from_other_paths(): void {
        $this->log([5]);
        $this->settle();
        $this->drain();
        $before = $this->state();
        $this->assertTrue(exporter::request_derived(userlogins::ENTITY));
        $this->assertSame((int)$before->reshipreq + 1, (int)$this->state()->reshipreq);
        $this->run_task();
        $this->assertSame([5 => 1], $this->drain(), 'every total sent again');

        $before = $this->state();
        ob_start();
        $rows = exporter::export_entity_range(userlogins::ENTITY, 'phpunit-batch', null, 1, time());
        ob_end_clean();
        $this->assertSame(0, $rows, 'a re-fetch reads nothing itself');
        $this->assertSame((int)$before->resetreq, (int)$this->state()->resetreq, 'a re-fetch does not recount');
        $this->assertSame((int)$before->reshipreq + 1, (int)$this->state()->reshipreq, 'it asks for a re-send');

        // The targeted re-fetch path (stop on refusal), as when it names the entity and is retried.
        for ($i = 0; $i < 2; $i++) {
            ob_start();
            $rows = exporter::export_entity_range(userlogins::ENTITY, 'phpunit-batch', null, 1, time(), true);
            ob_end_clean();
            $this->assertSame(0, $rows);
        }
        $this->assertSame((int)$before->resetreq, (int)$this->state()->resetreq, 'a retried re-fetch never recounts');
        $this->assertSame((int)$before->reshipreq + 3, (int)$this->state()->reshipreq);
        $def = exporter::registry_with_overrides()[userlogins::ENTITY];
        $this->assertSame('absent', exporter::sweep_kind($def), 'the verification sweep does not send it');
    }

    /**
     * A recount request during a count from zero is not recorded, so repeated requests
     * cannot restart the count.
     */
    public function test_recount_request_during_a_count_is_absorbed(): void {
        global $DB;
        $this->log([5, 6]);
        $this->settle();
        $max = (int)$DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}');
        $DB->set_field(userlogins::STATE, 'passtarget', $max + 1000, []);
        $before = (int)$this->state()->resetreq;
        $this->assertFalse(userlogins::request_recount());
        $this->assertSame($before, (int)$this->state()->resetreq);
        $DB->set_field(userlogins::STATE, 'passtarget', 0, []);
        $this->assertTrue(userlogins::request_recount());
        $this->assertSame($before + 1, (int)$this->state()->resetreq);
    }

    /**
     * A run that stops early records why, as a lang string, and the next normal run
     * clears it.
     */
    public function test_problem_is_shown_until_a_normal_run(): void {
        $this->log([5]);
        $this->settle();
        $this->assertSame('', userlogins::status()['error']);
        set_config('siteid', '', 'local_intellistream');
        $this->assertSame('nodestination', $this->run_task()['status']);
        $this->assertSame(
            get_string('status_userlogins_problem_nodestination', 'local_intellistream'),
            userlogins::status()['error']
        );
        set_config('siteid', 'phpunit-logins-site', 'local_intellistream');
        $this->assertSame('ok', $this->run_task()['status']);
        $this->assertSame('', userlogins::status()['error']);

        // A failure keeps its message, shortened, inside the lang string.
        $note = new \ReflectionMethod(userlogins::class, 'note_problem');
        $note->setAccessible(true);
        $note->invoke(null, 'failed', ['error' => str_repeat('x', 600)]);
        $this->assertSame(
            get_string('status_userlogins_problem_failed', 'local_intellistream', ['error' => str_repeat('x', 500)]),
            userlogins::status()['error']
        );
    }

    /**
     * A flush of more users than one statement takes, some with a total and some without,
     * gives the same totals as the single query.
     */
    public function test_flush_over_several_chunks(): void {
        set_config('ulmaxwidth', 100000, 'local_intellistream');
        $first = range(1001, 1000 + userlogins::CHUNK + 300);
        $this->log($first);
        $this->settle();
        $this->assertSame($this->expected(), $this->stored());
        // More than a chunk with a total and more than a chunk without: both write paths
        // cross a chunk, and the chunks mix the two.
        $this->log(array_merge(array_slice($first, 0, userlogins::CHUNK + 1), range(5001, 5000 + userlogins::CHUNK + 1)));
        $this->settle();
        $this->assertSame($this->expected(), $this->stored());
        $this->assertSame(count($first) + userlogins::CHUNK + 1, count($this->stored()));
    }

    /**
     * A flush whose state moved under it (a request or an erasure) rolls back.
     */
    public function test_flush_rolls_back_when_the_state_moved(): void {
        $this->log([5]);
        $this->settle();
        $before = $this->stored();
        $st = $this->state();
        $flush = new \ReflectionMethod(userlogins::class, 'flush');
        $flush->setAccessible(true);
        $expect = ['generation' => (int)$st->generation - 1, 'lastid' => (int)$st->lastid, 'resetreq' => (int)$st->resetreq];
        try {
            $flush->invokeArgs(null, [[5 => 10], (int)$st->lastid + 5, 10, &$expect]);
            $this->fail('the flush must not commit');
        } catch (\RuntimeException $e) {
            $this->assertSame(userlogins::CHANGED_CODE, $e->getCode());
        }
        $this->assertSame($before, $this->stored());
        $this->assertSame((int)$st->lastid, (int)$this->state()->lastid);
    }

    /**
     * The upper limit never moves down, and an empty log is idle, never a reset.
     */
    public function test_upper_never_lowers_and_empty_log_is_idle(): void {
        global $DB;
        $this->log([5]);
        $this->settle();
        $st = $this->state();
        $DB->delete_records('logstore_standard_log');
        $this->age_upper();
        $this->run_task();
        $after = $this->state();
        $this->assertSame((int)$st->upper, (int)$after->upper);
        $this->assertSame((int)$st->resetdone, (int)$after->resetdone);
        $this->assertSame(0, (int)$after->restartat, 'an empty log is not read as ids that restarted');
        $this->assertSame((int)$st->lastid, (int)$after->lastid);
        $this->assertSame([5 => 1], $this->stored(), 'totals do not fall when the log is purged');
    }

    /**
     * A highest id seen lower than the current limit never lowers it.
     */
    public function test_upper_ignores_a_lower_reading(): void {
        global $DB;
        $this->log([5, 6]);
        $this->settle();
        $upper = (int)$this->state()->upper;
        $DB->set_field(userlogins::STATE, 'nextupper', 1, []);
        $this->age_upper();
        $this->run_task();
        $this->assertSame($upper, (int)$this->state()->upper);
    }

    /**
     * The newest log rows deleted: counting continues after the highest id left, and no
     * login is counted twice.
     */
    public function test_newest_rows_deleted_counts_nothing_twice(): void {
        global $DB;
        $this->log([5]);
        $this->settle();
        $keep = (int)$DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}');
        $this->log([6]);
        $this->settle();
        $DB->delete_records_select('logstore_standard_log', 'id > :k', ['k' => $keep]);
        $this->run_task();
        $this->assertGreaterThan(0, (int)$this->state()->restartat, 'seen once: wait');
        $DB->execute('UPDATE {' . userlogins::STATE . '} SET restartat = restartat - :d', ['d' => userlogins::UPPER_DELAY]);
        $this->run_task();
        $this->assertSame($keep, (int)$this->state()->lastid);
        $this->log([7]);
        $this->settle();
        $this->assertSame([5 => 1, 6 => 1, 7 => 1], $this->stored());
    }

    /**
     * The log emptied and its ids restarted: the new rows are counted from the first id; totals stay.
     */
    public function test_log_emptied_ids_restart(): void {
        global $DB;
        $this->log([5, 6]);
        $this->settle();
        $DB->delete_records('logstore_standard_log');
        $DB->get_manager()->reset_sequence('logstore_standard_log');
        $this->time = time() + 60;
        $this->log([5]);
        $this->assertLessThan((int)$this->state()->lastid, (int)$DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}'));
        $this->run_task();
        $DB->execute('UPDATE {' . userlogins::STATE . '} SET restartat = restartat - :d', ['d' => userlogins::UPPER_DELAY]);
        $this->run_task();
        $this->assertSame(0, (int)$this->state()->lastid);
        $this->settle();
        $this->assertSame([5 => 2, 6 => 1], $this->stored());
    }

    /**
     * Privacy: the user is found and erased, and a flush that read the state before the
     * erasure then rolls back. The provider's own methods are in privacy/provider_test.php.
     */
    public function test_privacy(): void {
        $this->log([5, 6]);
        $this->settle();
        $contexts = privacy\provider::get_contexts_for_userid(5)->get_contextids();
        $this->assertSame([\context_system::instance()->id], array_map('intval', $contexts));
        $st = $this->state();
        $gen = (int)$st->generation;
        $expect = ['generation' => $gen, 'lastid' => (int)$st->lastid, 'resetreq' => (int)$st->resetreq];
        userlogins::delete_users([5]);
        $this->assertSame([6 => 1], $this->stored());
        $this->assertSame($gen + 1, (int)$this->state()->generation);
        $flush = new \ReflectionMethod(userlogins::class, 'flush');
        $flush->setAccessible(true);
        try {
            $flush->invokeArgs(null, [[5 => 3], (int)$st->lastid, 10, &$expect]);
            $this->fail('a flush that began before the erasure must not commit');
        } catch (\RuntimeException $e) {
            $this->assertSame(userlogins::CHANGED_CODE, $e->getCode());
        }
        $this->assertSame([6 => 1], $this->stored(), 'the erased user is not written back');
        userlogins::delete_all();
        $this->assertSame([], $this->stored());
        $this->assertGreaterThan((int)$this->state()->resetdone, (int)$this->state()->resetreq, 'counted again');
    }

    /**
     * Unpaired: counts, sends nothing; idle while the standard log store is off.
     */
    public function test_unpaired_and_logstore_off(): void {
        $this->log([5]);
        set_config('siteid', '', 'local_intellistream');
        $this->settle();
        $this->assertSame([5 => 1], $this->stored());
        $this->assertSame([], $this->drain());
        set_config('enabled_stores', '', 'tool_log');
        $this->assertSame('logstore_off', $this->run_task()['status']);
    }

    /**
     * A failed block halves the width at once, also when the run's time ends before the
     * next flush: the next run starts from the halved width, not the one that failed.
     */
    public function test_failed_block_width_is_kept(): void {
        global $DB;
        $this->log([5]);
        $this->settle();
        $this->log([6, 6, 7]);
        $this->age_upper();
        $this->run_task();
        $this->age_upper();
        $DB->set_field(userlogins::STATE, 'width', 7, []);
        // A block that fails after the run's time is used up, as a statement a server
        // limit stops.
        $failing = new class extends userlogins {
            /**
             * Fail as a statement that a server limit stops.
             *
             * @param int $lo
             * @param int $hi
             * @return array
             */
            protected static function read_block(int $lo, int $hi): array {
                usleep(300000);
                throw new \dml_read_exception('phpunit: block stopped');
            }
        };
        ob_start();
        try {
            $result = $failing::run(0.2);
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $result['blocks']);
        $this->assertSame(3, (int)$this->state()->width, 'the halved width was saved without a flush');
        $this->assertSame([5 => 1], $this->stored(), 'nothing from the failed block was counted');

        $this->run_task();
        $this->assertSame($this->expected(), $this->stored(), 'the next run counts from the saved width');
    }

    /**
     * A block that fails at the smallest width fails the run, and the status page shows it.
     */
    public function test_failed_block_at_the_smallest_width_fails_the_run(): void {
        global $DB;
        $this->log([5]);
        $this->age_upper();
        $this->run_task();
        $this->age_upper();
        $DB->set_field(userlogins::STATE, 'width', 3, []);
        $failing = new class extends userlogins {
            /**
             * Always fail.
             *
             * @param int $lo
             * @param int $hi
             * @return array
             */
            protected static function read_block(int $lo, int $hi): array {
                throw new \dml_read_exception('phpunit: block stopped');
            }
        };
        ob_start();
        try {
            $failing::run(600.0);
            $this->fail('the run must fail');
        } catch (\dml_read_exception $e) {
            $this->assertStringContainsString('phpunit: block stopped', $e->debuginfo);
        } finally {
            ob_end_clean();
        }
        $this->assertSame(3, (int)$this->state()->width);
        $this->assertStringContainsString(get_string('dmlreadexception', 'error'), userlogins::status()['error']);
    }

    /**
     * A round of sending that stops (a full buffer) resumes next run after the last user
     * it sent, and sends nobody twice.
     */
    public function test_stopped_round_resumes_from_its_cursor(): void {
        global $DB;
        $this->log([5, 6, 7, 8]);
        $this->settle();
        $this->drain();
        userlogins::request_reship();

        // A closed buffer file over half the cap: the round starts but sends nothing.
        set_config('maxbuffergb', 1, 'local_intellistream');
        set_config('backfillgate', 0, 'local_intellistream');
        $big = config::buffer_dir() . '/events-1-1-phpunit-h' . buffer::host_id() . '.jsonl.closed';
        $fh = fopen($big, 'c');
        ftruncate($fh, 600 * 1024 * 1024);
        fclose($fh);
        buffer::measure(config::buffer_dir());
        try {
            $result = $this->run_task();
        } finally {
            @unlink($big);
            buffer::measure(config::buffer_dir());
        }
        $this->assertSame('backpressure', $result['status']);
        $this->assertSame(0, $result['shipped']);
        $st = $this->state();
        $this->assertGreaterThanOrEqual(0, (int)$st->shipfrom, 'the round is kept');
        $this->assertNotEmpty(userlogins::status()['error'], 'the status page shows why');

        // As if users 5 and 6 were sent before the stop.
        $DB->set_field(userlogins::STATE, 'shipcursor', 6, []);
        $result = $this->run_task();
        $this->assertSame('ok', $result['status']);
        $this->assertSame([7 => 1, 8 => 1], $this->drain(), 'sent from the user after the cursor');
        $this->assertSame(-1, (int)$this->state()->shipfrom, 'the round is complete');
        $this->assertSame('', userlogins::status()['error']);
    }

    /**
     * Paired with a new site id: every total is sent again, once.
     */
    public function test_new_site_id_sends_every_total(): void {
        $this->log([5, 6, 6]);
        $this->settle();
        $this->drain();
        $this->run_task();
        $this->assertSame([], $this->drain(), 'nothing changed, nothing sent');

        set_config('siteid', 'phpunit-logins-site-2', 'local_intellistream');
        $this->run_task();
        $this->assertSame($this->expected(), $this->drain(), 'every total, for the new site id');
        $this->assertSame(sha1('phpunit-logins-site-2'), (string)$this->state()->sitekey);
        $this->run_task();
        $this->assertSame([], $this->drain(), 'once');
    }
}
