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

/**
 * Per-user login totals, counted from the standard log store in id blocks.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Keeps each user's login count in the plugin's own table and ships it as the derived
 * `userlogins` entity ({id: user id, logins: total}), the row shape the warehouse has
 * always received.
 *
 * Moodle has no login counter. The count was one GROUP BY over the whole standard log,
 * a statement that grows with the log and on a large site never finished. Here the log
 * is read once in short blocks of ids, oldest first, and the logins found are added to
 * a stored total per user; after that each run reads only the ids written since the
 * last one. No statement reads more than one block.
 *
 * A total is the number of logins since the first count, never lower: a log purge does
 * not reduce it. Only a recount (the CLI, or a privacy erasure of every total) counts
 * again from what the log holds now. Other requests (a targeted re-fetch, a backfill, a
 * fingerprint reset) only send the totals again.
 *
 * Who writes what. Only the task (task\count_userlogins, never two at once) counts,
 * ships and resets. Others only ask, by raising a counter the task compares with what
 * it last acted on: `resetreq` (count everything again) and `reshipreq` (send every
 * total again). A privacy erasure deletes rows and raises `generation`. Every state
 * write names its columns, so two writers never overwrite each other's fields.
 *
 * Each flush is one transaction that starts by locking the state row. If `generation`,
 * `lastid` or `resetreq` moved since the run read them, the flush rolls back: the run
 * stops, and the next one counts those blocks again. A block is therefore added to the
 * totals exactly once.
 *
 * Shipping. During a count from zero (`passtarget` > 0) nothing is sent, so the
 * warehouse keeps the totals it has. When that count ends, every total is sent. After
 * that each run sends the totals a flush changed (`mark` above `shipmark`), and every
 * total once a day, which repairs a row lost after it left the site. A round of
 * sending pages by user id and resumes where it stopped.
 */
class userlogins {
    /** Totals table. */
    public const TABLE = 'local_intellistream_ulogin';

    /** One-row state table. */
    public const STATE = 'local_intellistream_ulstate';

    /** Registry key of the entity. */
    public const ENTITY = 'userlogins';

    /** The event counted. */
    public const EVENT = '\\core\\event\\user_loggedin';

    /** The log table read. */
    public const LOG = 'logstore_standard_log';

    /** Ids per block at the start (measured: a cold block of this width stays under 2 s on MySQL 5.7). */
    public const START_WIDTH = 250000;

    /** Seconds a block should take; the width moves towards it. */
    public const TARGET_SEC = 1.0;

    /** A block slower than this halves the width. */
    public const SLOW_SEC = 2.0;

    /** Smallest width (setting `ulminwidth`, CLI only). */
    public const MIN_WIDTH = 1000;

    /** Largest width (setting `ulmaxwidth`, CLI only). */
    public const MAX_WIDTH = 4000000;

    /**
     * Seconds before the highest log id seen becomes the upper limit of counting. An id
     * can be given to a row whose transaction commits later; ids are read only once they
     * are this old, so a late commit is not passed over. The limit moves at a run that
     * starts at least this long after the run that saw the id. With the task every 15
     * minutes and normal cron start jitter, a login is counted 15 to 45 minutes after it
     * happens.
     */
    public const UPPER_DELAY = 900;

    /**
     * Distinct users held in memory before a flush. Measured for this many users: 1.3 s to
     * insert and 1.9 s to update on MySQL 8.0, 0.8 s and 2.0 s on PostgreSQL 16.
     */
    public const FLUSH_USERS = 50000;

    /**
     * User ids per statement. A statement binds at most 2 values per user, which stays under
     * the 2100 parameters and 1000 rows of a VALUES list on SQL Server and the 1000 items
     * of an IN list on Oracle.
     */
    public const CHUNK = 500;

    /** Rows per page of sending. */
    public const SHIP_PAGE = 1000;

    /** Exception code: the state changed under the run (see flush()). */
    public const CHANGED_CODE = 409;

    /**
     * Whether counting can run on this site, or why not.
     *
     * @return string '' when it can, else a reason token: disabled, entity_off, no_logstore,
     *     logstore_off.
     */
    public static function idle_reason(): string {
        if (!config::enabled()) {
            return 'disabled';
        }
        if (!array_key_exists(self::ENTITY, exporter::registry_with_overrides())) {
            return 'entity_off';
        }
        if (!services\config_service::table_is_real(self::LOG)) {
            return 'no_logstore';
        }
        $stores = explode(',', (string)get_config('tool_log', 'enabled_stores'));
        if (!in_array('logstore_standard', array_map('trim', $stores), true)) {
            return 'logstore_off';
        }
        return '';
    }

    /**
     * Ask the task to count every login again from the oldest log id (the CLI only). The
     * warehouse keeps the current totals until the new count is complete.
     *
     * A count from zero that is in progress already gives that result, so the request
     * is not recorded then: a repeated request cannot restart the count again and again.
     * Before the first run there is nothing to record either: that run counts from zero.
     *
     * @return bool Whether a request was recorded.
     */
    public static function request_recount(): bool {
        global $DB;
        $before = (int)$DB->get_field(self::STATE, 'resetreq', [], IGNORE_MULTIPLE);
        $DB->execute('UPDATE {' . self::STATE . '} SET resetreq = resetreq + 1
                       WHERE passtarget = 0 OR lastid >= passtarget');
        return (int)$DB->get_field(self::STATE, 'resetreq', [], IGNORE_MULTIPLE) > $before;
    }

    /**
     * Ask the task to send every total again (fingerprint reset, historical backfill).
     *
     * @return void
     */
    public static function request_reship(): void {
        global $DB;
        $DB->execute('UPDATE {' . self::STATE . '} SET reshipreq = reshipreq + 1');
    }

    /**
     * Privacy erasure of some users' totals. A flush in progress rolls back, and its
     * blocks are read again: rows the log store's own erasure removed are then not
     * counted.
     *
     * @param int[] $userids
     * @return void
     */
    public static function delete_users(array $userids): void {
        global $DB;
        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->execute('UPDATE {' . self::STATE . '} SET generation = generation + 1');
            foreach (array_chunk($userids, self::CHUNK) as $chunk) {
                [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
                $DB->execute('DELETE FROM {' . self::TABLE . '} WHERE userid ' . $insql, $params);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Privacy erasure of every total. The task then counts again from what the log holds.
     *
     * @return void
     */
    public static function delete_all(): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->execute('UPDATE {' . self::STATE . '} SET generation = generation + 1, resetreq = resetreq + 1');
            $DB->execute('DELETE FROM {' . self::TABLE . '}');
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * One run: act on requests, count new log ids while the budget lasts, then send.
     *
     * @param float $budget Seconds this run may use.
     * @return array{status:string, blocks:int, ids:int, users:int, shipped:int}
     */
    public static function run(float $budget): array {
        $start = microtime(true);
        $summary = ['status' => 'ok', 'blocks' => 0, 'ids' => 0, 'users' => 0, 'shipped' => 0];
        $reason = self::idle_reason();
        if ($reason !== '') {
            $summary['status'] = $reason;
            mtrace("local_intellistream: login counts — idle ({$reason}).");
            return $summary;
        }

        try {
            $st = self::prepare();
            $max = self::read_max();
            $st = self::move_upper($st, $max);

            $inpass = self::in_pass($st);
            // A count from zero ships nothing, so it may use the whole budget. Otherwise a
            // share is left for sending what it changed.
            $countuntil = $start + ($inpass ? $budget : $budget * 0.8);
            try {
                self::count($st, $countuntil, $summary);
            } catch (\RuntimeException $e) {
                if ($e->getCode() !== self::CHANGED_CODE) {
                    throw $e;
                }
                mtrace('local_intellistream: login counts — a request or an erasure arrived during the run; '
                    . 'the blocks since the last flush are counted again next run.');
                $summary['status'] = 'changed';
                return $summary;
            }

            $st = self::load();
            $inpass = self::in_pass($st);
            if (config::destination_ready()) {
                $summary['shipped'] = self::ship($st, $start + $budget, $summary);
            } else {
                $summary['status'] = 'nodestination';
                mtrace('local_intellistream: login counts — site id or destination not set; nothing sent.');
            }
        } catch (\Throwable $e) {
            self::note_problem('failed', ['error' => $e->getMessage()]);
            throw $e;
        }
        if ($summary['status'] === 'ok') {
            if ($st->lasterror !== null) {
                self::set($st, ['lasterror' => null]);
            }
        } else {
            self::note_problem($summary['status']);
        }
        mtrace(sprintf(
            'local_intellistream: login counts — %d block(s), %d id(s), %d user(s) counted, %d total(s) sent in %.1f s%s.',
            $summary['blocks'],
            $summary['ids'],
            $summary['users'],
            $summary['shipped'],
            microtime(true) - $start,
            $inpass ? ' (count from zero in progress: nothing is sent until it ends)' : ''
        ));
        return $summary;
    }

    /**
     * The state, after acting on a missing row, a reset request, an unfinished purge and
     * a new site id.
     *
     * @return \stdClass
     */
    private static function prepare(): \stdClass {
        global $DB;
        $st = self::load();
        if ($st === null) {
            // First run, or the row was removed: whatever totals exist cannot be trusted.
            $top = (int)$DB->get_field_sql('SELECT MAX(mark) FROM {' . self::TABLE . '}');
            $DB->insert_record(self::STATE, (object)['generation' => $top, 'timemodified' => time()]);
            $st = self::reset();
        } else if ((int)$st->resetreq > (int)$st->resetdone) {
            $st = self::reset();
        }
        if ((int)$st->purgeto > 0) {
            // The reset committed first: a crash before this delete repeats it here.
            $DB->execute('DELETE FROM {' . self::TABLE . '} WHERE mark < :p', ['p' => (int)$st->purgeto]);
            self::set($st, ['purgeto' => 0]);
        }
        // A hash of the site id: the site id itself has no length limit.
        $siteid = config::site_id();
        if ($siteid !== '' && (string)$st->sitekey !== sha1($siteid)) {
            // Paired with another site id: every total must reach the new one.
            self::set($st, ['sitekey' => sha1($siteid)]);
            if (!self::in_pass($st)) {
                self::start_round($st, true);
            }
        }
        return $st;
    }

    /**
     * Start counting from zero: the state is committed first, then the old totals go
     * (prepare() repeats the delete if the process dies between the two).
     *
     * @return \stdClass The state after the reset.
     */
    private static function reset(): \stdClass {
        global $DB;
        $max = self::read_max();
        $transaction = $DB->start_delegated_transaction();
        try {
            $locked = self::lock();
            $gen = (int)$locked->generation + 1;
            self::set($locked, [
                'generation' => $gen,
                'purgeto' => $gen,
                'lastid' => 0,
                'passtarget' => $max === null ? 0 : $max,
                'resetdone' => (int)$locked->resetreq,
                'shipfrom' => -1,
                'shipcursor' => 0,
                'lasterror' => null,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        mtrace('local_intellistream: login counts — counting from zero' . ($max === null ? '' : " up to log id {$max}") . '.');
        return self::load();
    }

    /**
     * Raise the upper limit of counting to the highest id seen at least UPPER_DELAY ago,
     * and note the current highest id for later. The limit never moves up past a log id
     * that is gone.
     *
     * The highest id below what was counted, on two reads UPPER_DELAY apart, has two
     * causes, told apart by the oldest log row:
     *  - the log was emptied and its ids restarted (the oldest row is newer than the last
     *    flush): every row is new, so counting starts again from the first id;
     *  - only the newest rows were deleted (the oldest row is older): every row left was
     *    counted, so counting continues after the highest id left. Starting from the first
     *    id here would count every login a second time.
     * The totals stay in both cases.
     *
     * @param \stdClass $st
     * @param int|null $max Highest log id now; null when the log is empty.
     * @return \stdClass
     */
    private static function move_upper(\stdClass $st, ?int $max): \stdClass {
        $now = time();
        if ($max === null) {
            return $st;
        }
        if ($max < (int)$st->lastid) {
            if ((int)$st->restartat === 0) {
                self::set($st, ['restartat' => $now]);
            } else if ($now - (int)$st->restartat >= self::UPPER_DELAY) {
                if (self::read_oldest_time() > (int)$st->lastflushat) {
                    mtrace("local_intellistream: login counts — the log was emptied and its ids restarted (highest "
                        . "{$max}, counted to {$st->lastid}); counting starts from the first id, totals kept.");
                    $from = 0;
                } else {
                    mtrace("local_intellistream: login counts — the newest log rows were deleted (highest {$max}, "
                        . "counted to {$st->lastid}); counting continues after id {$max}, totals kept.");
                    $from = $max;
                }
                $wasinpass = self::in_pass($st);
                self::set($st, ['lastid' => $from, 'upper' => $from, 'nextupper' => 0, 'nextupperat' => 0,
                    'passtarget' => 0, 'restartat' => 0]);
                if ($wasinpass) {
                    // The rows the count from zero still had to read are gone: it is complete.
                    self::start_round($st, true);
                }
            }
            return $st;
        }
        $fields = [];
        if ((int)$st->restartat !== 0) {
            $fields['restartat'] = 0;
        }
        if ((int)$st->nextupper > 0 && $now - (int)$st->nextupperat >= self::UPPER_DELAY) {
            $fields['upper'] = max((int)$st->upper, (int)$st->nextupper);
            $fields['nextupper'] = 0;
            $st->nextupper = 0;
        }
        if ((int)$st->nextupper === 0) {
            $fields['nextupper'] = $max;
            $fields['nextupperat'] = $now;
        }
        if ($fields) {
            self::set($st, $fields);
        }
        return $st;
    }

    /**
     * Read blocks from `lastid` to `upper` until $until, adding the logins found to the
     * totals in flushes.
     *
     * @param \stdClass $st
     * @param float $until microtime() after which no block is started.
     * @param array $summary Updated in place.
     * @return void
     */
    private static function count(\stdClass $st, float $until, array &$summary): void {
        $expect = ['generation' => (int)$st->generation, 'lastid' => (int)$st->lastid,
            'resetreq' => (int)$st->resetreq];
        $lo = (int)$st->lastid;
        $upper = (int)$st->upper;
        if ($lo === 0 && $upper > 0) {
            $lo = max(0, self::read_min() - 1);
        }
        $width = self::clamp_width((int)$st->width > 0 ? (int)$st->width : self::START_WIDTH);
        $sums = [];
        $lastflush = microtime(true);
        $flushsec = 0.0;
        $blocksec = 0.0;
        while ($lo < $upper) {
            // Stop while a block and a flush still fit.
            if (microtime(true) + 2 * $blocksec + $flushsec >= $until) {
                break;
            }
            $hi = min($upper, $lo + $width);
            $t0 = microtime(true);
            try {
                $found = static::read_block($lo, $hi);
            } catch (\dml_exception $e) {
                if ($width <= self::min_width()) {
                    // The caller, run(), records the failure for the status page.
                    mtrace("local_intellistream: login counts — block ({$lo}, {$hi}] failed at the smallest width.");
                    self::set($st, ['width' => $width]);
                    throw $e;
                }
                // Saved now: a run whose time ends before the next flush must not start
                // the next run at the width that failed.
                $width = self::clamp_width(intdiv($width, 2));
                self::set($st, ['width' => $width]);
                $blocksec = microtime(true) - $t0;
                mtrace("local_intellistream: login counts — block ({$lo}, {$hi}] failed, width halved to {$width}: "
                    . $e->getMessage());
                continue;
            }
            $blocksec = microtime(true) - $t0;
            if ($blocksec > self::SLOW_SEC) {
                mtrace(sprintf(
                    'local_intellistream: login counts — block (%d, %d] took %.1f s; the width is halved.',
                    $lo,
                    $hi,
                    $blocksec
                ));
            }
            foreach ($found as $userid => $n) {
                $sums[(int)$userid] = ($sums[(int)$userid] ?? 0) + (int)$n;
            }
            $summary['blocks']++;
            $summary['ids'] += $hi - $lo;
            $lo = $hi;
            $width = $blocksec > self::SLOW_SEC
                ? self::clamp_width(intdiv($width, 2))
                : self::clamp_width((int)($width * min(2.0, self::TARGET_SEC / max($blocksec, 0.001))));
            if (count($sums) >= self::FLUSH_USERS || microtime(true) - $lastflush >= exporter::PAGE_SAVE_SEC) {
                $t1 = microtime(true);
                $summary['users'] += self::flush($sums, $lo, $width, $expect);
                $flushsec = microtime(true) - $t1;
                $sums = [];
                $lastflush = microtime(true);
            }
        }
        if ($lo > $expect['lastid'] || $sums) {
            $summary['users'] += self::flush($sums, $lo, $width, $expect);
        }
    }

    /**
     * Add $sums to the totals and move `lastid` to $lastid, in one transaction under the
     * state lock. Rolls back and throws CHANGED_CODE if the state moved under the run.
     *
     * @param array $sums User id => logins found in the blocks since the last flush.
     * @param int $lastid Highest id those blocks covered.
     * @param int $width Block width to keep for the next run.
     * @param array $expect generation, lastid, resetreq as this run last left them; updated.
     * @return int Users written.
     */
    private static function flush(array $sums, int $lastid, int $width, array &$expect): int {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $st = self::lock();
            foreach ($expect as $field => $value) {
                if ((int)$st->$field !== $value) {
                    throw new \RuntimeException("state {$field} changed", self::CHANGED_CODE);
                }
            }
            $gen = (int)$st->generation + 1;
            ksort($sums);
            foreach (array_chunk($sums, self::CHUNK, true) as $chunk) {
                self::write_chunk($chunk, $gen);
            }
            $fields = ['generation' => $gen, 'lastid' => $lastid, 'width' => $width, 'lastflushat' => time()];
            if ((int)$st->passtarget > 0 && $lastid >= (int)$st->passtarget) {
                // The count from zero is complete: send every total, as one round.
                $fields += ['passtarget' => 0, 'reshipdone' => (int)$st->reshipreq, 'shipfrom' => 0,
                    'shiptarget' => $gen, 'shipcursor' => 0, 'shipfull' => 1];
                mtrace('local_intellistream: login counts — the count from zero is complete; every total is sent now.');
            }
            self::set($st, $fields);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $expect['generation'] = $gen;
        $expect['lastid'] = $lastid;
        return count($sums);
    }

    /**
     * Add one chunk of counts: an UPDATE for the users that have a total, then an INSERT
     * for the others (one statement each, or one per user on Oracle).
     *
     * Every write to the totals goes through execute(), not insert_records() or
     * delete_records(): local_intellidata can replace the database driver to record each row
     * those methods write in a table it tracks, and a flush writes up to FLUSH_USERS rows.
     * The values are integers: cast here, or bound.
     *
     * On Oracle each user is one statement, as Moodle's own insert_records() does there:
     * Oracle has no multi-row VALUES list, and before 12c a CASE takes at most 255
     * arguments.
     *
     * @param array $chunk User id => logins to add.
     * @param int $gen Mark of this flush.
     * @return void
     */
    private static function write_chunk(array $chunk, int $gen): void {
        global $DB;
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($chunk), SQL_PARAMS_NAMED, 'u');
        $have = array_flip(array_map('intval', $DB->get_fieldset_select(self::TABLE, 'userid', "userid {$insql}", $inparams)));
        $existing = [];
        $new = [];
        foreach ($chunk as $userid => $n) {
            if (isset($have[$userid])) {
                $existing[(int)$userid] = (int)$n;
            } else {
                $new[(int)$userid] = (int)$n;
            }
        }
        $insert = 'INSERT INTO {' . self::TABLE . '} (userid, logins, mark) VALUES ';
        if ($DB->get_dbfamily() === 'oracle') {
            foreach ($existing as $userid => $n) {
                $DB->execute(
                    'UPDATE {' . self::TABLE . '} SET logins = logins + :n, mark = :mark WHERE userid = :u',
                    ['n' => $n, 'mark' => $gen, 'u' => $userid]
                );
            }
            foreach ($new as $userid => $n) {
                $DB->execute($insert . '(:u, :n, :mark)', ['u' => $userid, 'n' => $n, 'mark' => $gen]);
            }
            return;
        }
        if ($existing) {
            // Integer literals in the CASE: no bound value per user.
            $case = '';
            foreach ($existing as $userid => $n) {
                $case .= ' WHEN ' . $userid . ' THEN ' . $n;
            }
            [$havesql, $haveparams] = $DB->get_in_or_equal(array_keys($existing), SQL_PARAMS_NAMED, 'h');
            $DB->execute('UPDATE {' . self::TABLE . '} SET logins = logins + CASE userid' . $case . ' ELSE 0 END, '
                . "mark = :mark WHERE userid {$havesql}", ['mark' => $gen] + $haveparams);
        }
        if (!$new) {
            return;
        }
        // The mark is the same on every row, and a named placeholder may appear only once.
        $rows = [];
        $params = [];
        $i = 0;
        foreach ($new as $userid => $n) {
            $rows[] = "(:u{$i}, :n{$i}, {$gen})";
            $params["u{$i}"] = $userid;
            $params["n{$i}"] = $n;
            $i++;
        }
        $DB->execute($insert . implode(', ', $rows), $params);
    }

    /**
     * Send the totals of the current round while the time lasts, starting a round when
     * one is due: every total after a re-send request or once a day, else the totals
     * changed since the last round.
     *
     * @param \stdClass $st
     * @param float $until
     * @param array $summary status is set to backpressure when the buffer is too full.
     * @return int Totals sent.
     */
    private static function ship(\stdClass $st, float $until, array &$summary): int {
        global $DB, $CFG;
        if (self::in_pass($st)) {
            return 0;
        }
        if ((int)$st->shipfrom < 0) {
            if ((int)$st->reshipreq > (int)$st->reshipdone || time() - (int)$st->lastfullship >= sweep::CYCLE) {
                self::start_round($st, true);
            } else if ((int)$st->generation > (int)$st->shipmark) {
                self::start_round($st, false);
            } else {
                return 0;
            }
        }
        $siteid = config::site_id();
        $batch = \core\uuid::generate();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $sent = 0;
        while (microtime(true) < $until) {
            // Without shipping inline, as the verification sweep: the ship_events task drains
            // the buffer, and an inline pass would take its ship lock.
            if (!exporter::sweep_backpressure_ok(false)) {
                $summary['status'] = 'backpressure';
                mtrace('local_intellistream: login counts — the buffer is over half its cap; sending continues next run.');
                break;
            }
            // One page under the state lock, and its buffer file closed before the lock is
            // released. A privacy erasure locks the same row first (delete_users()), so it
            // waits for the page, and its buffer erasure then finds the page's records in
            // a closed file (it leaves a file that a live process writes alone).
            $done = false;
            $refused = false;
            $transaction = $DB->start_delegated_transaction();
            try {
                self::lock();
                $rows = $DB->get_records_select_menu(
                    self::TABLE,
                    'mark > :from AND mark <= :target AND userid > :cursor',
                    ['from' => (int)$st->shipfrom, 'target' => (int)$st->shiptarget, 'cursor' => (int)$st->shipcursor],
                    'userid ASC',
                    'userid, logins',
                    0,
                    self::SHIP_PAGE
                );
                if (!$rows) {
                    $fields = ['shipmark' => max((int)$st->shipmark, (int)$st->shiptarget), 'shipfrom' => -1,
                        'shipcursor' => 0];
                    if ((int)$st->shipfull) {
                        $fields['lastfullship'] = time();
                    }
                    self::set($st, $fields);
                    $done = true;
                } else {
                    $cursor = (int)$st->shipcursor;
                    $pagesent = 0;
                    foreach ($rows as $userid => $logins) {
                        $reason = null;
                        $payload = exporter::userlogins_payload(
                            $siteid,
                            $batch,
                            (int)$userid,
                            (int)$logins,
                            $pluginversion,
                            $moodleversion
                        );
                        $ok = buffer::append_record($payload, $reason);
                        if (!$ok && $reason === buffer::REFUSED_TRANSIENT) {
                            $refused = true;   // Sent again from this row next run.
                            break;
                        }
                        $pagesent += $ok ? 1 : 0;
                        $cursor = (int)$userid;
                    }
                    if ($pagesent > 0) {
                        buffer::flush();
                    }
                    self::set($st, ['shipcursor' => $cursor]);
                    $sent += $pagesent;
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            if ($done) {
                break;
            }
            if ($refused) {
                $summary['status'] = 'refused';
                mtrace('local_intellistream: login counts — the buffer refused a total it may take later; '
                    . 'sending continues from it next run.');
                break;
            }
        }
        return $sent;
    }

    /**
     * Begin a round of sending, at the current generation.
     *
     * @param \stdClass $st Updated in place.
     * @param bool $full Every total (true) or those changed since the last round.
     * @return void
     */
    private static function start_round(\stdClass $st, bool $full): void {
        $fields = ['shipfrom' => $full ? 0 : (int)$st->shipmark, 'shiptarget' => (int)$st->generation,
            'shipcursor' => 0, 'shipfull' => $full ? 1 : 0];
        if ($full) {
            $fields['reshipdone'] = (int)$st->reshipreq;
        }
        self::set($st, $fields);
    }

    /**
     * Whether a count from zero is in progress.
     *
     * @param \stdClass $st
     * @return bool
     */
    private static function in_pass(\stdClass $st): bool {
        return (int)$st->passtarget > 0 && (int)$st->lastid < (int)$st->passtarget;
    }

    /**
     * Logins per user in log ids ($lo, $hi], read in a transaction so a site with a read
     * replica answers from the primary.
     *
     * The form differs by database. MySQL and MariaDB use the contextid index merged with
     * the id range. On PostgreSQL the same SQL can turn, on a site where the system context
     * is a small share of the log, into a bitmap over every system-context row of the
     * whole log, so only the id range is in the WHERE there.
     *
     * Called through static:: so a subclass can replace the read (the tests make a block fail).
     *
     * @param int $lo
     * @param int $hi
     * @return array User id => logins.
     */
    protected static function read_block(int $lo, int $hi): array {
        global $DB;
        $sys = \context_system::instance()->id;
        if ($DB->get_dbfamily() === 'postgres') {
            $sql = 'SELECT userid, COUNT(*) FILTER (WHERE contextid = :sys AND eventname = :e) AS n
                      FROM {' . self::LOG . '}
                     WHERE id > :lo AND id <= :hi AND userid > 0
                  GROUP BY userid
                    HAVING COUNT(*) FILTER (WHERE contextid = :sys2 AND eventname = :e2) > 0';
            $params = ['sys' => $sys, 'e' => self::EVENT, 'lo' => $lo, 'hi' => $hi, 'sys2' => $sys, 'e2' => self::EVENT];
        } else {
            $sql = 'SELECT userid, COUNT(1) AS n
                      FROM {' . self::LOG . '}
                     WHERE contextid = :sys AND id > :lo AND id <= :hi AND eventname = :e AND userid > 0
                  GROUP BY userid';
            $params = ['sys' => $sys, 'lo' => $lo, 'hi' => $hi, 'e' => self::EVENT];
        }
        return self::on_primary(function () use ($DB, $sql, $params) {
            return $DB->get_records_sql_menu($sql, $params);
        });
    }

    /**
     * Run a read in its own transaction, so a site with a read replica answers from the
     * primary, and roll it back if the read fails (the failure is rethrown).
     *
     * @param callable $read
     * @return mixed What $read returns.
     */
    private static function on_primary(callable $read) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $out = $read();
            $transaction->allow_commit();
            return $out;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Highest log id, from the primary; null when the log is empty.
     *
     * @return int|null
     */
    private static function read_max(): ?int {
        global $DB;
        $max = self::on_primary(function () use ($DB) {
            return $DB->get_field_sql('SELECT MAX(id) FROM {' . self::LOG . '}');
        });
        return ($max === null || $max === false) ? null : (int)$max;
    }

    /**
     * When the oldest log row was written (0 when the log is empty).
     *
     * @return int
     */
    private static function read_oldest_time(): int {
        global $DB;
        return (int)$DB->get_field_sql('SELECT timecreated FROM {' . self::LOG . '}
                                        WHERE id = (SELECT MIN(id) FROM {' . self::LOG . '})');
    }

    /**
     * Lowest log id (0 when the log is empty).
     *
     * @return int
     */
    private static function read_min(): int {
        global $DB;
        return (int)$DB->get_field_sql('SELECT MIN(id) FROM {' . self::LOG . '}');
    }

    /**
     * Width within the floor and cap.
     *
     * @param int $width
     * @return int
     */
    private static function clamp_width(int $width): int {
        $max = max(self::min_width(), self::tuning('ulmaxwidth', self::MAX_WIDTH));
        return max(self::min_width(), min($max, $width));
    }

    /**
     * Smallest block width.
     *
     * @return int
     */
    private static function min_width(): int {
        return max(1, self::tuning('ulminwidth', self::MIN_WIDTH));
    }

    /**
     * A tuning value set from the CLI: its default when unset or not a whole number.
     *
     * @param string $name
     * @param int $default
     * @return int
     */
    private static function tuning(string $name, int $default): int {
        $value = config::get($name, null);
        return ($value !== null && preg_match('/^\s*\d+\s*$/', (string)$value)) ? (int)$value : $default;
    }

    /**
     * The state row, or null when there is none.
     *
     * @return \stdClass|null
     */
    private static function load(): ?\stdClass {
        global $DB;
        $st = $DB->get_record(self::STATE, [], '*', IGNORE_MULTIPLE);
        return $st ?: null;
    }

    /**
     * Lock the state row for the current transaction and return it as stored.
     *
     * @return \stdClass
     */
    private static function lock(): \stdClass {
        global $DB;
        $DB->execute('UPDATE {' . self::STATE . '} SET timemodified = :t', ['t' => time()]);
        return self::load();
    }

    /**
     * Write the named state columns only, and apply them to $st.
     *
     * @param \stdClass $st
     * @param array $fields Column => value.
     * @return void
     */
    private static function set(\stdClass $st, array $fields): void {
        global $DB;
        $fields['timemodified'] = time();
        $sets = [];
        $params = ['id' => (int)$st->id];
        foreach ($fields as $column => $value) {
            $sets[] = "{$column} = :{$column}";
            $params[$column] = $value;
            $st->$column = $value;
        }
        $DB->execute('UPDATE {' . self::STATE . '} SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    /**
     * Record why the last run did not end normally, for the status page: a lang key and
     * its parameters, shown by shipper::format_status_detail(). A failure to record it
     * does not hide the run's own outcome.
     *
     * @param string $problem failed, backpressure, refused or nodestination.
     * @param array $params Parameters of the string.
     * @return void
     */
    private static function note_problem(string $problem, array $params = []): void {
        try {
            $st = self::load();
            if ($st !== null) {
                foreach ($params as $name => $value) {
                    $params[$name] = \core_text::substr((string)$value, 0, 500);
                }
                $key = 'status_userlogins_problem_' . $problem;
                $detail = json_encode(['key' => $key, 'params' => $params], JSON_INVALID_UTF8_SUBSTITUTE);
                if ($detail === false) {
                    $detail = json_encode(['key' => $key, 'params' => array_fill_keys(array_keys($params), '?')]);
                }
                self::set($st, ['lasterror' => $detail, 'lasterrorat' => time()]);
            }
        } catch (\Throwable $e) {
            mtrace('local_intellistream: login counts — the problem could not be recorded: ' . $e->getMessage());
        }
    }

    /**
     * Health of the counter, for the status page and get_status.
     *
     * @return array{state:string, pass_percent:float, behind_ids:int, last_flush:int, last_full_ship:int,
     *     error:string, task_disabled:int} `error` is the last run's problem as text, or ''.
     */
    public static function status(): array {
        global $DB;
        $out = ['state' => '', 'pass_percent' => 0.0, 'behind_ids' => 0, 'last_flush' => 0, 'last_full_ship' => 0,
            'error' => '', 'task_disabled' => 0];
        $task = $DB->get_record_select('task_scheduled', 'classname = :a OR classname = :b', [
            'a' => '\\local_intellistream\\task\\count_userlogins',
            'b' => 'local_intellistream\\task\\count_userlogins',
        ], 'disabled', IGNORE_MULTIPLE);
        $out['task_disabled'] = ($task && (int)$task->disabled) ? 1 : 0;
        $reason = self::idle_reason();
        $st = self::load();
        if ($reason !== '' || $st === null) {
            $out['state'] = $reason !== '' ? $reason : 'not_started';
            return $out;
        }
        $max = (int)$DB->get_field_sql('SELECT MAX(id) FROM {' . self::LOG . '}');
        // Measured from the oldest log id, as count() starts there: a purged log's ids
        // below it are not work left.
        $first = max(0, self::read_min() - 1);
        $done = max($first, (int)$st->lastid);
        $out['behind_ids'] = max(0, $max - $done);
        $out['last_flush'] = (int)$st->lastflushat;
        $out['last_full_ship'] = (int)$st->lastfullship;
        $out['error'] = shipper::format_status_detail((string)$st->lasterror);
        if (self::in_pass($st)) {
            $out['state'] = 'first_count';
            // Rounded down, so a count that is not complete never shows 100.0.
            $span = max(1, (int)$st->passtarget - $first);
            $out['pass_percent'] = floor(1000.0 * min(1.0, ($done - $first) / $span)) / 10;
        } else {
            $out['state'] = (int)$st->shipfrom >= 0 ? 'sending' : 'current';
        }
        return $out;
    }
}
