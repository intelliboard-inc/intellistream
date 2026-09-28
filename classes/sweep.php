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
 * The verification sweep run by the 15-minute task.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Walks every entity once per CYCLE seconds, a slice per run, so that everything the
 * timestamp lane cannot see (tables with no change timestamp, changes that do not move
 * one, deletes) still reaches the warehouse — the work the daily full snapshot used to
 * do in one burst.
 *
 * Per entity a PASS reads the table in fixed blocks of change_ledger::BLOCK_SIZE ids,
 * WINDOW_BLOCKS blocks at a time. Each window, in one transaction with the entity's
 * position: its census page is written, its fingerprints are staged. When the pass
 * reaches the end of the table the census manifest is written. The next pass may start
 * CYCLE seconds after this one started.
 *
 * Only blocks whose fingerprint differs from the one last confirmed delivered are sent
 * (change_ledger); the census goes every pass, in compact pages, so deletes reach the
 * warehouse daily at a cost of about one bit per id.
 *
 * Pacing: each run takes its share of the work still due (remaining blocks divided by
 * the runs left in a cycle), bounded by a time budget, and interleaves entities one
 * window at a time so a small table is never stuck behind a large one.
 */
class sweep {
    /** State table. */
    const TABLE = 'local_intellistream_sweep';

    /** Blocks per save window (a census page holds at most this many blocks' ids). */
    const WINDOW_BLOCKS = 50;

    /** Seconds between the starts of two passes of the same entity. */
    const CYCLE = 86400;

    /** Budget floor: the sweep always gets at least this many seconds. */
    const MIN_BUDGET = 60;

    /** @var int[]|null The refresh task's start times while run() is running, else null. */
    private static $runstarts = null;

    /** Default budget per run, seconds (setting `sweepbudget`). */
    const DEFAULT_BUDGET = 240;

    /** Consecutive failures at one position before the entity is parked. */
    const MAX_FAILURES = 3;

    /** How long a parked entity waits before it is retried. */
    const PARK_SECONDS = 3600;

    /**
     * A whole-table entity this long past its daily due time runs first in a run, not
     * from the budget the windows leave, so a site behind schedule still sends it.
     */
    const ATOMIC_GRACE = 21600;

    /**
     * How long a whole-table entity waits after repeated failures: longer than a window's
     * park, because each attempt reads the whole table (userlogins groups the logstore)
     * and an overdue one runs first in every run.
     */
    const ATOMIC_PARK_SECONDS = 21600;

    /** Lock lifetime for a whole-table run, which on a large site can outlast 15 minutes. */
    const ATOMIC_LOCK_SECONDS = 3600;

    /** Config key: when each failing entity's id-only census was last sent, JSON by entity. */
    const IDCENSUS_KEY = 'sweep_idcensus';

    /** Config key: when the InForm schema catalogue was last sent. */
    const INFORM_KEY = 'sweep_inform_time';

    /**
     * Blocks per save window: WINDOW_BLOCKS, or the hidden `sweep_window_blocks`
     * (1..WINDOW_BLOCKS) — operational tuning, and lets a smoke test force many windows
     * on a small table.
     *
     * @return int
     */
    public static function window_blocks(): int {
        $n = (int)config::get('sweep_window_blocks', self::WINDOW_BLOCKS);
        return ($n >= 1 && $n <= self::WINDOW_BLOCKS) ? $n : self::WINDOW_BLOCKS;
    }

    /**
     * How many blocks an entity should cover in this run so its pass ends in time.
     *
     * A pass should finish within CYCLE (less a margin) of its start, so the next one can
     * start on time. The share is what is left divided by the runs left before that
     * deadline — it grows as the deadline nears, so a pass started late, or slowed by a
     * backlog, catches up instead of drifting. Never below one window.
     *
     * @param int $left Blocks the pass still has to cover.
     * @param int $passstart When the pass started (now, for one about to start).
     * @param int $now
     * @return int
     */
    public static function target_blocks(int $left, int $passstart, int $now): int {
        $deadline = $passstart + (int)(self::CYCLE * 0.9);
        $runs = max(1, self::runs_between($now, $deadline));
        return max(self::window_blocks(), (int)ceil($left / $runs));
    }

    /**
     * Seconds this run may spend, and whether that had to be raised to the floor.
     *
     * The refresh task never overlaps itself (Moodle's task lock), so the budget is kept
     * under its own interval, less what the timestamp lane already used this run.
     *
     * @param float $spent Seconds the run has already used.
     * @return array{0:float, 1:bool} [budget, starved]
     */
    public static function budget(float $spent): array {
        $setting = (int)config::get('sweepbudget', self::DEFAULT_BUDGET);
        $setting = max(self::MIN_BUDGET, $setting);
        $cap = self::interval() - 120 - $spent;
        $budget = min($setting, $cap);
        if ($budget < self::MIN_BUDGET) {
            return [(float)self::MIN_BUDGET, true];
        }
        return [(float)$budget, false];
    }

    /**
     * The refresh task's interval in seconds (default 15 minutes), from its cron schedule:
     * the shortest gap between two of its start times in a day, from the minute and hour
     * fields (Moodle's own parser, so hourly, minute lists and ranges all work). Not from
     * lastruntime: Moodle writes that when a run ENDS, so a gap to nextruntime is short by
     * the run's own length, and after a failure nextruntime is the fail delay. It bounds
     * one run's budget; how many runs are left is counted by runs_between().
     *
     * @return int
     */
    public static function interval(): int {
        $starts = self::schedule_starts();
        $n = count($starts);
        if ($n === 0) {
            return 900;
        }
        $gap = self::CYCLE;
        for ($i = 0; $i < $n; $i++) {
            $next = $i + 1 < $n ? $starts[$i + 1] : $starts[0] + self::CYCLE;
            $gap = min($gap, $next - $starts[$i]);
        }
        return max(60, $gap);
    }

    /**
     * How many times the refresh task is scheduled to start in [$from, $to): the actual
     * start times from its cron schedule, so an uneven schedule (every 7 minutes, or
     * only some hours) is paced by the runs it really gets. Days are the server's
     * days, as Moodle evaluates the schedule; day-of-week/month restrictions are
     * ignored. Falls back to one run per 900 s when the schedule cannot be read.
     *
     * @param int $from Unix seconds.
     * @param int $to Unix seconds.
     * @return int
     */
    public static function runs_between(int $from, int $to): int {
        if ($to <= $from) {
            return 0;
        }
        $starts = self::schedule_starts();
        if (!$starts) {
            return intdiv($to - $from, 900);
        }
        $tz = \core_date::get_server_timezone_object();
        $day = (new \DateTime('@' . $from))->setTimezone($tz)->setTime(0, 0);
        $runs = 0;
        while ($day->getTimestamp() < $to) {
            $midnight = $day->getTimestamp();
            foreach ($starts as $s) {
                $t = $midnight + $s;
                if ($t >= $from && $t < $to) {
                    $runs++;
                }
            }
            $day->modify('+1 day');
        }
        return $runs;
    }

    /**
     * The refresh task's daily start times, seconds after midnight, sorted: its minute and
     * hour fields through Moodle's own cron parser.
     *
     * @return int[] Empty when the schedule cannot be read.
     */
    private static function schedule_starts(): array {
        return self::$runstarts ?? self::read_schedule_starts();
    }

    /**
     * Read the refresh task's daily start times from its schedule.
     *
     * @return int[] Empty when the schedule cannot be read.
     */
    private static function read_schedule_starts(): array {
        try {
            $task = \core\task\manager::get_scheduled_task('\\local_intellistream\\task\\refresh_entities');
            return $task ? self::starts_of($task) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * A scheduled task's daily start times, seconds after midnight, sorted.
     *
     * @param \core\task\scheduled_task $task
     * @return int[]
     */
    public static function starts_of(\core\task\scheduled_task $task): array {
        try {
            $minutes = $task->eval_cron_field($task->get_minute(), 0, 59);
            $hours = $task->eval_cron_field($task->get_hour(), 0, 23);
        } catch (\Throwable $e) {
            return [];
        }
        $starts = [];
        foreach ($hours as $h) {
            foreach ($minutes as $m) {
                $starts[] = (int)$h * 3600 + (int)$m * 60;
            }
        }
        $starts = array_values(array_unique($starts));
        sort($starts);
        return $starts;
    }

    /**
     * Health of the sweep, for the status page and get_status.
     *
     * `overdue` counts entities whose last complete pass started more than a cycle
     * (plus one run) ago, or that never completed one — the signal that deletes and
     * timestamp-less tables are not being kept current for them.
     *
     * @return array{entities:int, overdue:int, parked:int, oldest_hours:float,
     *     refresh_disabled:int, gate_held:int, unconfirmed:int}
     */
    public static function status(): array {
        global $DB;
        $now = time();
        $limit = self::CYCLE + self::interval();
        $overdue = 0;
        $parked = 0;
        $oldest = 0.0;
        $rows = $DB->get_records(self::TABLE, null, '', 'id, entity, lastpassstart, parkeduntil');
        foreach ($rows as $r) {
            $age = (int)$r->lastpassstart > 0 ? ($now - (int)$r->lastpassstart) : null;
            if ($age === null || $age > $limit) {
                $overdue++;
            }
            if ((int)$r->parkeduntil > $now) {
                $parked++;
            }
            if ($age !== null) {
                $oldest = max($oldest, $age / 3600.0);
            }
        }
        $task = $DB->get_record_select('task_scheduled', 'classname = :a OR classname = :b', [
            'a' => '\\local_intellistream\\task\\refresh_entities',
            'b' => 'local_intellistream\\task\\refresh_entities',
        ], 'disabled', IGNORE_MULTIPLE);
        $gate = get_config(config::COMPONENT, backfill::GATE_KEY);
        $gatelist = is_string($gate) ? json_decode($gate, true) : null;
        return [
            'entities' => count($rows),
            'overdue' => $overdue,
            'parked' => $parked,
            'oldest_hours' => round($oldest, 1),
            'refresh_disabled' => ($task && (int)$task->disabled) ? 1 : 0,
            'gate_held' => (is_array($gatelist) && (int)config::get('backfillgate', 1)) ? count($gatelist) : 0,
            'unconfirmed' => change_ledger::unconfirmed(),
        ];
    }

    /**
     * One run of the sweep.
     *
     * @param float $spent Seconds the calling run has already used (timestamp lane).
     * @return array{status:string, windows:int, blocks:int, rows:int, dropped:int, completed:int,
     *     waiting:int, budget:float, starved:bool}
     */
    public static function run(float $spent = 0.0): array {
        // Settings and markers are written in web requests (webhook, privacy, pull) and
        // read here in cron: drop this process's cached copy once per run. The schedule is
        // read once for the run, not once per entity.
        config::refresh();
        self::$runstarts = self::read_schedule_starts();
        try {
            return self::run_sweep($spent);
        } finally {
            self::$runstarts = null;
        }
    }

    /**
     * The body of run().
     *
     * @param float $spent
     * @return array See run().
     */
    private static function run_sweep(float $spent): array {
        $summary = ['status' => 'ok', 'windows' => 0, 'blocks' => 0, 'rows' => 0, 'dropped' => 0, 'completed' => 0,
            'waiting' => 0, 'budget' => 0.0, 'starved' => false];
        if (!change_ledger::active()) {
            $summary['status'] = 'no_destination';
            return $summary;
        }
        $start = microtime(true);
        [$budget, $starved] = self::budget($spent);
        $summary['budget'] = $budget;
        $summary['starved'] = $starved;
        if ($starved) {
            mtrace('local_intellistream: verification sweep — the timestamp lane used most of this run; '
                . 'the sweep gets only its minimum ' . self::MIN_BUDGET . ' s.');
        }

        exporter::reset_instance_name_cache();
        $registry = exporter::registry_with_overrides();
        $now = time();
        $states = self::load_states($registry);

        // Build the queue: passes in progress first, then passes due, oldest first. Each
        // entity carries its own block target for this run (see target_blocks()).
        // Whole-table entities run after the windows, from what budget is left; overdue ones
        // (ATOMIC_GRACE) run before them, so a site behind schedule still sends them.
        $queue = [];
        $atomic = [];
        $overdue = [];
        $quota = 0;
        foreach ($registry as $entity => $def) {
            if (backfill::sweep_blocked((string)$entity)) {
                continue;
            }
            $kind = exporter::sweep_kind($def);
            if ($kind === 'absent') {
                continue;
            }
            $st = $states[$entity] ?? self::new_state((string)$entity);
            $inpass = (int)$st->passstart > 0;
            $due = $inpass || ((int)$st->lastpassstart === 0 || $now >= (int)$st->lastpassstart + self::CYCLE);
            if (!$due || (int)$st->parkeduntil > $now) {
                continue;
            }
            if ($kind === 'atomic') {
                if ($now >= (int)$st->lastpassstart + self::CYCLE + self::ATOMIC_GRACE) {
                    $overdue[(string)$entity] = $st;
                } else {
                    $atomic[(string)$entity] = $st;
                }
                continue;
            }
            try {
                $max = exporter::sweep_max_block($def);
            } catch (\Throwable $e) {
                continue;
            }
            $left = max(0, $max - ($inpass ? (int)$st->nextblock : 0) + 1);
            $target = self::target_blocks($left, $inpass ? (int)$st->passstart : $now, $now);
            $queue[(string)$entity] = [$def, $st, $inpass ? (int)$st->passstart : (int)$st->lastpassstart, $target];
            $quota += $target;
        }
        uasort($queue, function ($a, $b) {
            return $a[2] <=> $b[2];
        });
        self::run_atomics($overdue, $registry, $summary, $start, $budget);

        $done = [];   // Blocks each entity has done this run.
        while ($queue) {
            foreach (array_keys($queue) as $entity) {
                if ((microtime(true) - $start) >= $budget) {
                    break 2;
                }
                if (($done[$entity] ?? 0) >= $queue[$entity][3]) {
                    unset($queue[$entity]);   // Its share for this run is done.
                    continue;
                }
                if (!exporter::sweep_backpressure_ok()) {
                    $summary['status'] = 'backpressure';
                    mtrace('local_intellistream: verification sweep — the buffer is over half its cap and one '
                        . 'shipping pass could not drain it; the sweep continues next run.');
                    break 2;
                }
                [$def, $st] = $queue[$entity];
                $r = self::step($entity, $def, $st);
                $summary['windows']++;
                $summary['blocks'] += $r['blocks'];
                // A window always advances by its size, even over an id gap, so count
                // the blocks it covered: a sparse table must still move towards its end.
                $done[$entity] = ($done[$entity] ?? 0) + max(1, self::window_blocks());
                $summary['rows'] += $r['rows'];
                $summary['dropped'] += $r['dropped'];
                $summary['waiting'] += $r['waiting'] ? 1 : 0;
                if ($r['done']) {
                    if ($r['completed']) {
                        $summary['completed']++;
                    }
                    unset($queue[$entity]);
                }
            }
        }

        self::run_atomics($atomic, $registry, $summary, $start, $budget);

        self::daily_items($registry);
        $summary['elapsed'] = round(microtime(true) - $start, 1);
        $later = count($queue) + count($overdue) + count($atomic);
        mtrace(sprintf(
            'local_intellistream: verification sweep — %d window(s), %d block(s), %d row(s) sent, '
            . '%d row(s) refused for good (too large or not encodable), %d pass(es) completed in %.1f s '
            . '(budget %.0f s, quota %d blocks)%s%s.',
            $summary['windows'],
            $summary['blocks'],
            $summary['rows'],
            $summary['dropped'],
            $summary['completed'],
            $summary['elapsed'],
            $budget,
            $quota,
            $later ? ', ' . $later . ' entit(y/ies) continue next run' : '',
            $summary['waiting'] ? ', ' . $summary['waiting'] . ' wait(s) for their last pass to ship' : ''
        ));
        return $summary;
    }

    /**
     * One window for one entity, with everything it implies: starting the pass, the
     * census page, the fingerprints, the position, finishing the pass, or a failure.
     *
     * @param string $entity
     * @param array $def
     * @param \stdClass $st
     * @return array{done:bool, completed:bool, waiting:bool, blocks:int, rows:int, dropped:int}
     */
    private static function step(
        string $entity,
        array $def,
        \stdClass $st
    ): array {
        global $DB;
        $none = ['done' => true, 'completed' => false, 'waiting' => false, 'blocks' => 0, 'rows' => 0, 'dropped' => 0];
        $lock = self::lock($entity);
        if ($lock === false) {
            return $none;   // Held by the backfill.
        }
        try {
            if ((int)$st->passstart === 0 && !self::begin_pass($entity, $st)) {
                return ['waiting' => true] + $none;
            }
            $r = exporter::sweep_window(
                $entity,
                $def,
                (int)$st->nextblock,
                self::window_blocks(),
                (string)$st->passbatch,
                (int)$st->passgen
            );

            if ($r['status'] === 'end') {
                self::finish_pass($entity, $st);
                return ['completed' => true] + $none;
            }
            if ($r['status'] === 'stopped') {
                mtrace("local_intellistream: {$entity} — the buffer refused a record it may take later; "
                    . 'this window is redone next run.');
                return ['rows' => $r['rows']] + $none;
            }
            if ($r['status'] === 'error') {
                self::fail($entity, $def, $st, (string)$r['error']);
                return $none;
            }

            $page = (int)$st->pageno + 1;
            $pageok = exporter::sweep_census_page(
                $entity,
                (int)$st->passgen % 2,
                (string)$st->passbatch,
                $page,
                $r['ids']
            );
            $before = clone $st;
            $tx = $DB->start_delegated_transaction();
            try {
                change_ledger::stage($entity, $r['entries'], (int)$st->passstart);
                $clk = self::clk_read((string)$st->clkstate);
                $st->clkstate = self::clk_write([
                    $clk[0] + $r['clk'][0],
                    $clk[1] + $r['clk'][1],
                    $clk[3] ? $clk[2] : max($clk[2], $r['clk'][2]),
                    $clk[3] || $r['clk'][3],
                ]);
                $st->nextblock = $r['nextblock'];
                $st->pageno = $page;
                $st->idcount = (int)$st->idcount + count($r['ids']);
                $st->pagesok = ($pageok && (int)$st->pagesok) ? 1 : 0;
                $st->emittedend = time();
                $st->failures = 0;
                $st->lasterror = null;
                self::save($st);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                // Put the position back as it was before this window, so fail() below
                // does not save an advance whose fingerprints were rolled back. Roll back
                // first (it rethrows), so fail() records the failure outside the aborted
                // transaction and the entity can be parked.
                foreach (get_object_vars($before) as $k => $v) {
                    $st->$k = $v;
                }
                $tx->rollback($e);
            }
            if (!$pageok) {
                mtrace("local_intellistream: {$entity} — census page {$page} was not written; "
                    . 'this pass will not send a census manifest.');
            }
            return ['done' => false, 'blocks' => $r['blocks'], 'rows' => $r['rows'], 'dropped' => $r['dropped']] + $none;
        } catch (\Throwable $e) {
            self::fail($entity, $def, $st, $e->getMessage());
            return $none;
        } finally {
            $lock->release();
        }
    }

    /**
     * Start a new pass: settle the previous pass's fingerprints, then reset the position.
     * Not started while the previous pass's buffer files are still waiting to ship
     * (change_ledger::settle() 'wait'): asked again next run.
     *
     * @param string $entity
     * @param \stdClass $st
     * @return bool Whether the pass started.
     */
    private static function begin_pass(string $entity, \stdClass $st): bool {
        if (change_ledger::settle($entity, (int)$st->pendstart, (int)$st->pendend) === 'wait') {
            return false;
        }
        $st->passstart = time();
        $st->scanstarted = clock::now();
        $st->passbatch = \core\uuid::generate();
        $st->nextblock = 0;
        $st->pageno = 0;
        $st->idcount = 0;
        $st->pagesok = 1;
        $st->clkstate = self::clk_write([0, 0, 0, false]);
        $st->emittedend = 0;
        $st->failures = 0;
        $st->lasterror = null;
        self::save($st);
        return true;
    }

    /**
     * The table is exhausted: send the census manifest, record the clockless
     * observation, and remember this pass for the next one.
     *
     * @param string $entity
     * @param \stdClass $st
     * @return void
     */
    private static function finish_pass(string $entity, \stdClass $st): void {
        $reason = null;
        $written = false;
        if ((int)$st->pagesok) {
            $written = exporter::sweep_census_manifest(
                $entity,
                (string)$st->passbatch,
                (int)$st->pageno,
                (int)$st->idcount,
                (string)$st->scanstarted,
                $reason
            );
            if (!$written) {
                mtrace("local_intellistream: {$entity} — census manifest NOT written ("
                    . ($reason === buffer::REFUSED_PERMANENT ? 'over the per-record size cap' : 'the buffer could not take it now')
                    . '); delete reconciliation waits for the next pass.');
            }
        } else {
            mtrace("local_intellistream: {$entity} — a census page failed, so no manifest for this pass "
                . '(the reconciler never acts on a partial census).');
        }
        $clk = self::clk_read((string)$st->clkstate);
        exporter::sweep_note_clockless($entity, $clk[0], $clk[2]);
        $st->lastpassstart = (int)$st->passstart;
        $st->lastcomplete = time();
        $st->pendstart = (int)$st->passstart;
        $st->pendend = time();
        $st->passgen = (int)$st->passgen + 1;
        $st->passstart = 0;
        $st->passbatch = null;
        self::save($st);
    }

    /**
     * Run whole-table entities (userlogins groups the whole logstore) while budget is
     * left, under the entity lock and the backpressure check like any window. Those run
     * are removed from $list; what is left continues next run.
     *
     * @param array $list Entity => state.
     * @param array $registry
     * @param array $summary The run summary (status set on backpressure).
     * @param float $start
     * @param float $budget
     * @return void
     */
    private static function run_atomics(array &$list, array $registry, array &$summary, float $start, float $budget): void {
        foreach ($list as $entity => $st) {
            if ((microtime(true) - $start) >= $budget || $summary['status'] === 'backpressure') {
                return;
            }
            if (!exporter::sweep_backpressure_ok()) {
                $summary['status'] = 'backpressure';
                return;
            }
            $lock = self::lock((string)$entity, self::ATOMIC_LOCK_SECONDS);
            if ($lock === false) {
                continue;   // Held by the backfill.
            }
            try {
                self::run_atomic((string)$entity, $registry, $st);
            } finally {
                $lock->release();
            }
            unset($list[$entity]);
        }
    }

    /**
     * Send an entity that cannot be walked in id blocks (derived, or no `id`) whole,
     * once per cycle, as the daily snapshot did.
     *
     * @param string $entity
     * @param array $registry
     * @param \stdClass $st
     * @return void
     */
    private static function run_atomic(string $entity, array $registry, \stdClass $st): void {
        $outcome = null;
        $st->passstart = time();
        try {
            exporter::export_entity($entity, \core\uuid::generate(), $registry, '', null, $outcome);
        } catch (\Throwable $e) {
            // As step(): one failing entity is counted and parked, never allowed to end the
            // run for every other entity in the queue.
            $outcome = ['complete' => false, 'error' => $e->getMessage()];
            mtrace("local_intellistream: {$entity} — verification sweep failed: " . $e->getMessage());
        }
        if (!empty($outcome['complete'])) {
            $st->lastpassstart = (int)$st->passstart;
            $st->lastcomplete = time();
            $st->failures = 0;
        } else {
            $st->failures = (int)$st->failures + 1;
            $st->lasterror = (string)($outcome['error'] ?? 'not complete');
            if ((int)$st->failures >= self::MAX_FAILURES) {
                $st->parkeduntil = time() + self::ATOMIC_PARK_SECONDS;
                $st->failures = 0;   // As fail(): the retry after the park gets its own attempts.
            }
        }
        $st->passstart = 0;
        try {
            self::save($st);
        } catch (\Throwable $e) {
            mtrace("local_intellistream: {$entity} — could not record the verification result: " . $e->getMessage());
        }
    }

    /**
     * A window failed. Retried from the same block next run; after MAX_FAILURES the
     * entity is parked for PARK_SECONDS. While it keeps failing an id-only census is sent
     * for it at most once per CYCLE, so delete reconciliation keeps working without a
     * full id list every park.
     *
     * @param string $entity
     * @param array $def
     * @param \stdClass $st
     * @param string $error
     * @return void
     */
    private static function fail(string $entity, array $def, \stdClass $st, string $error): void {
        $st->failures = (int)$st->failures + 1;
        $st->lasterror = \core_text::substr($error, 0, 1000);
        mtrace("local_intellistream: {$entity} — verification sweep failed at block {$st->nextblock} "
            . "(attempt {$st->failures}): {$error}");
        if ((int)$st->failures >= self::MAX_FAILURES) {
            $st->parkeduntil = time() + self::PARK_SECONDS;
            $st->failures = 0;
            mtrace("local_intellistream: {$entity} — parked for " . self::PARK_SECONDS . ' s.');
            $sent = json_decode((string)config::get(self::IDCENSUS_KEY, ''), true);
            $sent = is_array($sent) ? $sent : [];
            if (time() - (int)($sent[$entity] ?? 0) >= self::CYCLE) {
                try {
                    exporter::export_census(\core\uuid::generate(), [$entity => $def]);
                    $sent[$entity] = time();
                    set_config(self::IDCENSUS_KEY, json_encode($sent), config::COMPONENT);
                    mtrace("local_intellistream: {$entity} — id-only census sent (at most once a day while it fails).");
                } catch (\Throwable $e) {
                    mtrace("local_intellistream: {$entity} — id-only census failed too: " . $e->getMessage());
                }
            }
        }
        try {
            self::save($st);
        } catch (\Throwable $e) {
            mtrace("local_intellistream: {$entity} — could not record the failure: " . $e->getMessage());
        }
    }

    /**
     * Once per cycle: the InForm schema catalogue.
     *
     * @param array $registry
     * @return void
     */
    private static function daily_items(array $registry): void {
        $last = (int)config::get(self::INFORM_KEY, 0);
        if (time() - $last < self::CYCLE) {
            return;
        }
        try {
            exporter::export_inform_dyn_schema(\core\uuid::generate(), $registry);
            set_config(self::INFORM_KEY, time(), config::COMPONENT);
        } catch (\Throwable $e) {
            mtrace('local_intellistream: InForm schema catalogue failed (retried next run): ' . $e->getMessage());
        }
    }

    /**
     * Record an entity the historical backfill has just finished as if a pass had
     * covered it: its next pass is due CYCLE seconds after the backfill began reading
     * it, and the fingerprints the backfill staged are settled then like any pass's.
     *
     * @param string $entity
     * @param int $t0 When the backfill first read the entity.
     * @return void
     */
    public static function seed_from_backfill(string $entity, int $t0): void {
        global $DB;
        $st = $DB->get_record(self::TABLE, ['entity' => $entity]) ?: self::new_state($entity);
        if ((int)$st->passstart > 0) {
            return;   // A pass is under way; it settles on its own.
        }
        $st->lastpassstart = $t0;
        $st->lastcomplete = time();
        $st->pendstart = $t0;
        $st->pendend = time();
        self::save($st);
    }

    /**
     * Take the per-entity lock the historical backfill also takes. The database lock
     * factory expires a lock after its maximum lifetime: the sweep holds it for one
     * window, so a process killed mid-window frees it within minutes (a whole-table run
     * asks for an hour); the backfill holds it while it reads the whole entity and asks
     * for a day.
     *
     * @param string $entity
     * @param int $lifetime Seconds before the lock may expire.
     * @return \core\lock\lock|false
     */
    public static function lock(string $entity, int $lifetime = 900) {
        $factory = \core\lock\lock_config::get_lock_factory('local_intellistream');
        return $factory->get_lock('entity_' . $entity, 0, $lifetime);
    }

    /**
     * All state rows, keyed by entity; rows for entities no longer in the registry
     * are removed together with their fingerprints.
     *
     * @param array $registry
     * @return array<string, \stdClass>
     */
    private static function load_states(array $registry): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records(self::TABLE) as $row) {
            if (!array_key_exists($row->entity, $registry)) {
                $DB->delete_records(self::TABLE, ['id' => $row->id]);
                change_ledger::clear_entity((string)$row->entity);
                continue;
            }
            $out[$row->entity] = $row;
        }
        return $out;
    }

    /**
     * A fresh, unsaved state row.
     *
     * @param string $entity
     * @return \stdClass
     */
    private static function new_state(string $entity): \stdClass {
        return (object)['id' => 0, 'entity' => $entity, 'passgen' => 0, 'passstart' => 0, 'scanstarted' => null,
            'passbatch' => null, 'nextblock' => 0, 'pageno' => 0, 'idcount' => 0, 'pagesok' => 1,
            'clkstate' => null, 'emittedend' => 0, 'lastpassstart' => 0, 'lastcomplete' => 0, 'pendstart' => 0,
            'pendend' => 0, 'failures' => 0, 'parkeduntil' => 0, 'lasterror' => null, 'timemodified' => 0];
    }

    /**
     * Insert or update a state row.
     *
     * @param \stdClass $st
     * @return void
     */
    private static function save(\stdClass $st): void {
        global $DB;
        $st->timemodified = time();
        if (empty($st->id)) {
            unset($st->id);
            $st->id = $DB->insert_record(self::TABLE, $st);
        } else {
            $DB->update_record(self::TABLE, $st);
        }
    }

    /**
     * Decode a clockless tally.
     *
     * @param string $raw
     * @return array{0:int,1:int,2:int,3:bool} [seen, delivered, max delivered id, gapped]
     */
    private static function clk_read(string $raw): array {
        $p = explode(',', $raw);
        return [(int)($p[0] ?? 0), (int)($p[1] ?? 0), (int)($p[2] ?? 0), !empty($p[3])];
    }

    /**
     * Encode a clockless tally.
     *
     * @param array $clk
     * @return string
     */
    private static function clk_write(array $clk): string {
        return (int)$clk[0] . ',' . (int)$clk[1] . ',' . (int)$clk[2] . ',' . ($clk[3] ? 1 : 0);
    }
}
