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
 * Resumable historical-backfill driver for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Drives the one-time historical backfill as a RESUMABLE job, run in time-limited steps.
 *
 * The backfill seeds the warehouse with the history that existed before the
 * event observer was switched on. It walks the whole registry ("ship everything"),
 * over as many runs as it takes, and records a per-entity keyset watermark
 * (`backfill_wm_<entity>`) and a per-entity done flag (`backfill_done_<entity>`)
 * in plugin config, so a run that stops (its time limit, a full buffer, a timeout,
 * an OOM, a reboot) is resumed from where it stopped on the next run — never
 * restarted from zero.
 *
 * A run from a task (the scheduled task or the ad-hoc one) is limited to
 * run_limit_sec() and does not ship inline: it stops between two pages once that
 * time has passed, and the next run continues after the last row sent. It leaves the
 * final census to a run of its own. Two steps can still pass the limit, because they
 * cannot stop part-way: one page and the final census (every table's ids). A derived
 * entity (`userlogins`) is not read here: its own task is asked to send it again.
 * The setting `backfillstop` (CLI only) stops task runs and their ad-hoc chain. To resume
 * from the saved positions, clear it, then enable the `historical_backfill` task (off by
 * default) or run the CLI backfill; a new install's held backfill is queued again by the
 * 15-minute task. The CLI backfill runs to the end and ships inline.
 *
 * Ordering: keyset entities first (streamed past their watermark, with buffer
 * backpressure), then the terminal, complete-set-only steps (InForm dynamic
 * schema catalog + delete-reconciliation census). A partial `--entity` run
 * skips the terminal steps and never marks the campaign complete.
 *
 * State (all in `mdl_config_plugins`, component local_intellistream):
 *   backfill_batch          one stable snapshot_batch UUID for the campaign
 *   backfill_wm_<entity>    last id durably buffered for a keyset entity
 *   backfill_done_<entity>  1 when that entity's scan is exhausted (sticky)
 *   backfill_complete       1 when the full campaign has finished (sticky)
 *
 * Idempotency downstream is unchanged: deterministic uuid5 ids + the
 * middleware's content-hash dedup make the small resume overlap harmless.
 */
class backfill {
    /** Config-key prefixes owned by the backfill (cleared by reset()). */
    const KEY_PREFIX = 'backfill_';

    /**
     * Config key: JSON list of the entities a NEW install holds back from the 15-minute
     * task until the backfill has finished them. Written once, at install (never on an
     * upgrade), only ever shrinks, and is outside KEY_PREFIX so no reset re-arms it.
     */
    const GATE_KEY = 'gate_entities';

    /** Runs without watermark progress after which an entity is released. */
    const MAX_NO_PROGRESS = 3;

    /**
     * Seconds a backfill run from a task may read before it stops for the next run
     * (the setting `backfillrunsec` overrides it, see run_limit_sec()). Checked between
     * pages and before each entity, so a run can pass it by up to one page.
     */
    public const RUN_LIMIT_SEC = 600;

    /**
     * Seconds a backfill run from a task may read: RUN_LIMIT_SEC, or the CLI-only setting
     * `backfillrunsec` when it holds a whole number (0 reads one page per run), at most
     * a day.
     *
     * @return int
     */
    public static function run_limit_sec(): int {
        $value = config::get('backfillrunsec', null);
        $sec = ($value !== null && preg_match('/^\s*\d+\s*$/', (string)$value)) ? (int)$value : self::RUN_LIMIT_SEC;
        return min($sec, DAYSECS);
    }

    /**
     * Whether task runs of the backfill are switched off: the CLI-only setting
     * `backfillstop`. A task run then reads nothing, its ad-hoc chain ends and the
     * 15-minute task queues none; the CLI backfill still runs. To resume from the saved
     * positions, clear the setting, then enable the `historical_backfill` task or run the
     * CLI backfill; a new install's held backfill is queued again by the 15-minute task.
     *
     * @return bool
     */
    public static function stopped(): bool {
        return (bool)(int)config::get('backfillstop', 0);
    }

    /**
     * Seconds a lock of this run may live before the database lock factory expires it:
     * three days for the CLI, which can read for days in one process, and a day (at least
     * twice the run limit) for a task run. A task run is limited, but its census step is
     * not, and a lock that expired under it would let a second run start the same
     * campaign. Under that lock factory, a task run that is killed
     * holds the campaign until its lock expires.
     *
     * @param int|null $timelimit As run().
     * @return int
     */
    public static function lock_lifetime(?int $timelimit): int {
        return $timelimit === null ? 3 * DAYSECS : max(DAYSECS, 2 * $timelimit);
    }

    /**
     * Run (or resume) the backfill.
     *
     * @param array $only Restrict to these entity names (a partial run — never
     *                    emits the terminal census/schema, never marks complete).
     * @param int|null $timelimit Seconds this run may read (run_limit_sec() from a
     *                    task), after which it stops with reason `timelimit` and does
     *                    not ship inline; null runs to the end (the CLI).
     * @return array{complete:bool, reason?:string, entity?:string,
     *               entities_total:int, entities_done:int, rows:int, batch:string}
     */
    public static function run(array $only = [], ?int $timelimit = null): array {
        if ($timelimit !== null) {
            $timelimit = max(0, min($timelimit, DAYSECS));
            if (self::stopped()) {
                mtrace('local_intellistream: historical backfill switched off (setting backfillstop) — '
                    . 'nothing read; clear the setting to resume.');
                return self::result(false, 'stopped');
            }
        }
        if (!config::enabled()) {
            mtrace('local_intellistream: disabled — historical backfill skipped.');
            return self::result(false, 'disabled');
        }
        if (self::is_complete()) {
            mtrace('local_intellistream: historical backfill already complete — nothing to do.');
            return self::result(true, 'already_complete');
        }
        // Refuse to start while unpaired. buffer::append_record() rejects every
        // record when site_id is empty, so a backfill run here would scan every
        // table, be refused on every row, and — now that a refusal is classified
        // rather than discarded — look like an endless run of PERMANENT refusals.
        // Stopping before the first read is the only correct answer: an unpaired
        // site is a configuration state that gets fixed, not a property of the data.
        if (config::site_id() === '') {
            mtrace('local_intellistream: site id not set (unpaired) — historical backfill '
                . 'not started. Pair the site first; nothing has been scanned and no '
                . 'watermark has moved.');
            return self::result(false, 'unpaired');
        }
        if (!config::destination_ready()) {
            mtrace('local_intellistream: destination not set (endpoint, bucket, access key and '
                . 'secret key, or a pull export) — historical backfill not started. Nothing has '
                . 'been scanned and no watermark has moved.');
            return self::result(false, 'no_destination');
        }

        // One campaign at a time: the scheduled task, "Run now", the CLI and a queued
        // ad-hoc run can otherwise start together and read the same site twice over.
        // A CLI campaign on a large site can run for days in one process, and the
        // database lock factory expires a lock after its maximum lifetime.
        $campaign = \core\lock\lock_config::get_lock_factory('local_intellistream')
            ->get_lock('backfill_campaign', 0, self::lock_lifetime($timelimit));
        if ($campaign === false) {
            mtrace('local_intellistream: historical backfill already running — this run does nothing.');
            return self::result(false, 'busy');
        }
        try {
            return self::run_locked($only, $timelimit === null ? null : time() + $timelimit, $timelimit);
        } finally {
            $campaign->release();
        }
    }

    /**
     * The body of run(), under the campaign lock.
     *
     * @param array $only See run().
     * @param int|null $deadline Unix time after which no further page or entity is read;
     *                 null runs to the end and ships inline.
     * @param int|null $timelimit The run's limit in seconds, as run().
     * @return array See run().
     */
    private static function run_locked(array $only, ?int $deadline, ?int $timelimit = null): array {
        // MEMORY_EXTRA (not HUGE/2G): every path here streams one row at a time
        // via get_recordset_select() and buffers to disk, so memory stays flat;
        // the raise only covers per-row JSON encode headroom on wide tables.
        raise_memory_limit(MEMORY_EXTRA);
        // Moodle cron runs every due task in one process: start from cold name
        // maps so this run reads current activity titles, not another task's.
        // The maps are budget-capped (exporter::INSTANCE_NAME_TOTAL_MAX) so they
        // do not undermine the flat-memory premise of MEMORY_EXTRA above.
        exporter::reset_instance_name_cache();

        $batch = self::ensure_batch();
        $registry = exporter::registry_with_overrides();
        $names = array_keys($registry);
        $partial = !empty($only);
        if ($partial) {
            $names = array_values(array_intersect($names, $only));
        }

        $rows = 0;
        $released = self::released();
        // Only a run without a time limit (the CLI) ships inline. An inline pass is not
        // the scheduled task, so it never bypasses the load gate, and on a busy host it
        // only lengthened the run; the ship_events task sends what a task's run buffered.
        $inline = $deadline === null;
        $worked = false; // An entity was read in this run.
        foreach ($names as $entity) {
            if (get_config(config::COMPONENT, self::KEY_PREFIX . 'done_' . $entity)) {
                continue; // Already exhausted on an earlier run.
            }
            if (isset($released[$entity])) {
                continue; // Given up on after making no progress; see note_no_progress().
            }
            if ($deadline !== null && $worked && time() >= $deadline) {
                // Not started: the next run begins with this entity. Only after this run read
                // one, so every run that stops here has moved the campaign forward.
                mtrace("local_intellistream: historical backfill — run time limit reached; '{$entity}' "
                    . 'and the entities after it continue on the next run.');
                self::hand_off($inline);
                return self::result(false, 'timelimit', $entity, $rows);
            }
            // The verification sweep takes the same lock, so the two never work on one
            // entity at the same time. Busy means the sweep holds it: try next run.
            $lock = sweep::lock($entity, self::lock_lifetime($timelimit));
            if ($lock === false) {
                mtrace("local_intellistream: historical backfill — '{$entity}' is being verified right now; "
                    . 'it is picked up on the next run.');
                self::hand_off($inline);
                return self::result(false, 'incomplete', $entity, $rows);
            }
            $worked = true;
            $wmkey = self::KEY_PREFIX . 'wm_' . $entity;
            $wmbefore = (int)get_config(config::COMPONENT, $wmkey);
            if (get_config(config::COMPONENT, self::KEY_PREFIX . 't0_' . $entity) === false) {
                // Taken before the entity's first read: the 15-minute lane later starts
                // just below it, so a row changed while the backfill read it is sent.
                set_config(self::KEY_PREFIX . 't0_' . $entity, time(), config::COMPONENT);
            }
            // New install only: fingerprint what is sent, so the sweep's first pass over
            // this entity does not send it again (see exporter::export_entity_window()).
            $seed = self::is_keyset($registry[$entity]) && self::gated($entity) && change_ledger::active();
            $buffered = 0;
            try {
                if (self::is_keyset($registry[$entity])) {
                    exporter::export_entity_window(
                        $entity,
                        $batch,
                        $registry,
                        self::KEY_PREFIX . 'wm_' . $entity,
                        $seed,
                        $deadline,
                        $buffered
                    );
                    $rows += $buffered;
                } else {
                    // Derived entity (no table of its own to read): its task keeps it and
                    // is asked here to send all of it again (userlogins: every total, at
                    // the end of a count from zero if one is running). Nothing is read
                    // here, so the entity is done.
                    if (!exporter::request_derived($entity)) {
                        throw new \RuntimeException("no task keeps the derived entity '{$entity}'.");
                    }
                    if ($entity === userlogins::ENTITY) {
                        // The request waits for that task: show when it will not act on it.
                        $ul = userlogins::status();
                        mtrace("local_intellistream: historical backfill — '{$entity}' is sent by its own task "
                            . "(state {$ul['state']}" . ($ul['task_disabled'] ? ', task disabled' : '') . ').');
                    }
                }
            } catch (\Throwable $e) {
                // Rows the stopped entity buffered before it stopped are this run's too.
                $rows += $buffered;
                // A keyset window threw (buffer stall, DB error, or the run time limit):
                // progress is persisted in its watermark. Leave the entity NOT done, flush
                // what we have, and report paused (or timelimit) so the next run resumes.
                if ($e->getCode() !== exporter::RUN_LIMIT_CODE) {
                    mtrace('local_intellistream: historical backfill paused on entity '
                        . $entity . ': ' . $e->getMessage());
                }
                // A full buffer is a state of the site, not a stall of this entity: it
                // does not count towards releasing the entity (which would let the
                // campaign finish without it), unless the watermark moved anyway.
                $wmafter = (int)get_config(config::COMPONENT, $wmkey);
                if ($e->getCode() !== exporter::REFUSED_TRANSIENT_CODE || $wmafter > $wmbefore) {
                    self::note_no_progress($entity, $wmbefore, $wmafter);
                }
                $lock->release();
                self::hand_off($inline);
                if ($e->getCode() === exporter::RUN_LIMIT_CODE) {
                    // Progress, not a stall: note_no_progress() above saw the watermark move.
                    return self::result(false, 'timelimit', $entity, $rows);
                }
                return self::result(false, 'paused', $entity, $rows);
            }
            $lock->release();
            if ($seed) {
                sweep::seed_from_backfill($entity, (int)get_config(config::COMPONENT, self::KEY_PREFIX . 't0_' . $entity));
            }
            set_config(self::KEY_PREFIX . 'done_' . $entity, 1, config::COMPONENT);
            set_config(self::KEY_PREFIX . 'lastprogress', time(), config::COMPONENT);
            unset_config(self::KEY_PREFIX . 'noprog_' . $entity, config::COMPONENT);
            exporter::seed_incremental_watermark(
                $entity,
                (int)get_config(config::COMPONENT, self::KEY_PREFIX . 't0_' . $entity) - 1
            );
            self::ungate($entity);
        }

        // Terminal, complete-set-only steps: only for a FULL run in which every
        // registry entity is now done. A partial --entity run stops here so it
        // never emits an incomplete census (which the reconciler would treat as
        // authoritative) or half a schema catalog.
        if (!$partial && self::all_done($registry)) {
            if ($deadline !== null && $worked) {
                // The final census reads every table's ids and cannot stop part-way: it
                // starts a run of its own, with the run's whole time ahead of it.
                mtrace('local_intellistream: historical backfill — every table is done; the final census '
                    . 'runs on the next run.');
                self::hand_off($inline);
                return self::result(false, 'timelimit', null, $rows);
            }
            // Complete only once the terminal records were written. A census or
            // schema catalog the buffer refused for now would otherwise never be
            // sent: complete is sticky, and every later run returns at once.
            $refusedbefore = buffer::transient_refusals();
            exporter::export_inform_dyn_schema($batch, $registry);
            exporter::export_census($batch, $registry);
            self::hand_off($inline);
            if (buffer::transient_refusals() !== $refusedbefore) {
                mtrace('local_intellistream: historical backfill — every table is done, but the buffer '
                    . 'refused part of the final census for now; it is sent again on the next run.');
                return self::result(false, 'incomplete', null, $rows);
            }
            self::mark_complete();
            unset_config(self::GATE_KEY, config::COMPONENT);
            mtrace('local_intellistream: historical backfill complete.');
            return self::result(true, 'complete', null, $rows);
        }

        // Partial run, or more entities remain for a later resume.
        self::hand_off($inline);
        return self::result(false, $partial ? 'partial' : 'incomplete', null, $rows);
    }

    /**
     * End of a run: close this process's buffer file so its rows can be shipped, and
     * ship them now only when the run ships inline (see run_locked()).
     *
     * @param bool $inline
     * @return void
     */
    private static function hand_off(bool $inline): void {
        buffer::flush();
        if ($inline) {
            shipper::run();
        }
    }

    /**
     * Snapshot of backfill progress for the CLI --status flag and status page.
     *
     * @return array
     */
    public static function status(): array {
        $registry = exporter::registry_with_overrides();
        $names = array_keys($registry);
        $done = 0;
        $watermarks = [];
        foreach ($names as $entity) {
            if (get_config(config::COMPONENT, self::KEY_PREFIX . 'done_' . $entity)) {
                $done++;
            }
            $wm = (int)get_config(config::COMPONENT, self::KEY_PREFIX . 'wm_' . $entity);
            if ($wm > 0) {
                $watermarks[$entity] = $wm;
            }
        }
        return [
            'complete'       => self::is_complete(),
            'entities_total' => count($names),
            'entities_done'  => $done,
            'batch'          => (string)get_config(config::COMPONENT, self::KEY_PREFIX . 'batch'),
            'watermarks'     => $watermarks,
        ];
    }

    /**
     * Clear ALL backfill state (watermarks, done flags, batch, complete) so the
     * next run starts a fresh campaign from zero. Used by `--restart`.
     */
    public static function reset(): void {
        $all = (array)get_config(config::COMPONENT);
        foreach (array_keys($all) as $key) {
            if (strpos($key, self::KEY_PREFIX) === 0) {
                unset_config($key, config::COMPONENT);
            }
        }
    }

    /**
     * Arm the new-install hold: the 15-minute task and the sweep leave these entities to
     * the historical backfill until it has finished each one. Called from db/install.php
     * only. Entities added to the registry later (custom datatypes, discovered tables)
     * are never held.
     *
     * @return void
     */
    public static function arm_gate(): void {
        $entities = array_keys(exporter::registry());
        set_config(self::GATE_KEY, json_encode(array_values($entities)), config::COMPONENT);
    }

    /**
     * Whether an entity is still held back for the historical backfill.
     *
     * @param string $entity
     * @return bool
     */
    public static function gated(string $entity): bool {
        if (!(int)config::get('backfillgate', 1)) {
            return false;   // Kill switch: tasks run as if never held.
        }
        $list = self::gate_list();
        return isset($list[$entity]);
    }

    /**
     * Whether the verification sweep must leave an entity alone this run.
     *
     * @param string $entity
     * @return bool
     */
    public static function sweep_blocked(string $entity): bool {
        return self::gated($entity);
    }

    /**
     * Whether the new-install hold is still in force for any entity.
     *
     * @return bool
     */
    public static function gate_active(): bool {
        return (int)config::get('backfillgate', 1) && self::gate_list() !== [];
    }

    /**
     * Queue the backfill when a held install has none queued or running. Called by the
     * 15-minute task, so a site whose pairing path queued nothing (CLI config, a host
     * where the first ad-hoc run was lost) still starts.
     *
     * @param bool $pulling True from the pull web service: a pull-only site has no
     *        destination to wait for, and its puller calling is the signal.
     * @return bool Whether a run was queued.
     */
    public static function ensure_queued(bool $pulling = false): bool {
        global $DB;
        if (
            !self::gate_active() || self::is_complete() || self::stopped() || !config::enabled()
            || config::site_id() === ''
        ) {
            return false;
        }
        // Only once the records can leave the site: with the destination incomplete the
        // backfill would fill the buffer with nothing to drain it. A pull-only site has no
        // destination here, so its first pull is the signal instead ($pulling).
        if (!$pulling && !config::destination_ready()) {
            return false;
        }
        $queued = $DB->record_exists_select('task_adhoc', 'classname = :a OR classname = :b', [
            'a' => '\\local_intellistream\\task\\run_backfill_adhoc_task',
            'b' => 'local_intellistream\\task\\run_backfill_adhoc_task',
        ]);
        if ($queued) {
            return false;
        }
        $task = new \local_intellistream\task\run_backfill_adhoc_task();
        $task->set_custom_data(['only' => [], 'held' => 1]);
        \core\task\manager::queue_adhoc_task($task, true);
        // Reachable on a web request (the pull service, the webhook, a settings save).
        buffer::trace('local_intellistream: historical backfill queued (new install, held entities waiting).');
        return true;
    }

    /**
     * Settings-page callback for the pairing and destination settings (site id, endpoint,
     * bucket, access key, secret key): the moment the last of them is saved, a held new
     * install starts its historical backfill, instead of waiting for the next 15-minute
     * run. Never throws into the settings page.
     *
     * @return void
     */
    public static function on_destination_saved(): void {
        try {
            self::ensure_queued();
        } catch (\Throwable $e) {
            debugging(
                'local_intellistream: could not queue the historical backfill: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Progress of the historical backfill, for get_status (and so the control plane).
     *
     * @return array{complete:int, held:int, done:int, total:int, current:string, released:int,
     *     last_progress:string, stopped:int}
     */
    public static function progress(): array {
        $registry = exporter::registry_with_overrides();
        $released = self::released();
        $done = 0;
        $current = '';
        foreach (array_keys($registry) as $entity) {
            if (get_config(config::COMPONENT, self::KEY_PREFIX . 'done_' . $entity)) {
                $done++;
            } else if ($current === '' && !isset($released[$entity])) {
                $current = (string)$entity;
            }
        }
        $last = (int)get_config(config::COMPONENT, self::KEY_PREFIX . 'lastprogress');
        return [
            'complete' => self::is_complete() ? 1 : 0,
            'held' => self::gate_active() ? count(self::gate_list()) : 0,
            'done' => $done,
            'total' => count($registry),
            'current' => self::is_complete() ? '' : $current,
            'released' => count($released),
            'last_progress' => $last > 0 ? gmdate('Y-m-d\TH:i:s\Z', $last) : '',
            'stopped' => self::stopped() ? 1 : 0,
        ];
    }

    /**
     * The held entities, as a set.
     *
     * @return array<string, true>
     */
    private static function gate_list(): array {
        $raw = get_config(config::COMPONENT, self::GATE_KEY);
        $list = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($list) ? array_fill_keys(array_map('strval', $list), true) : [];
    }

    /**
     * Release one entity from the hold (its backfill finished, or it was given up on).
     *
     * @param string $entity
     * @return void
     */
    private static function ungate(string $entity): void {
        $list = self::gate_list();
        if (!isset($list[$entity])) {
            return;
        }
        unset($list[$entity]);
        if ($list === []) {
            unset_config(self::GATE_KEY, config::COMPONENT);
        } else {
            set_config(self::GATE_KEY, json_encode(array_keys($list)), config::COMPONENT);
        }
    }

    /**
     * Entities given up on, as a set.
     *
     * @return array<string, true>
     */
    private static function released(): array {
        $raw = get_config(config::COMPONENT, self::KEY_PREFIX . 'released');
        $list = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($list) ? array_fill_keys(array_map('strval', $list), true) : [];
    }

    /**
     * Count a run that stopped on an entity without moving its watermark. After
     * MAX_NO_PROGRESS in a row the entity is released: skipped by later backfill runs
     * (so the entities after it are reached) and freed for the 15-minute task and the
     * sweep, which then carry it like any other entity.
     *
     * @param string $entity
     * @param int $before Watermark when the run reached it.
     * @param int $after Watermark when it stopped.
     * @return void
     */
    private static function note_no_progress(string $entity, int $before, int $after): void {
        $key = self::KEY_PREFIX . 'noprog_' . $entity;
        if ($after > $before) {
            unset_config($key, config::COMPONENT);
            set_config(self::KEY_PREFIX . 'lastprogress', time(), config::COMPONENT);
            return;
        }
        $n = (int)get_config(config::COMPONENT, $key) + 1;
        if ($n < self::MAX_NO_PROGRESS) {
            set_config($key, $n, config::COMPONENT);
            return;
        }
        unset_config($key, config::COMPONENT);
        $released = self::released();
        $released[$entity] = true;
        set_config(self::KEY_PREFIX . 'released', json_encode(array_keys($released)), config::COMPONENT);
        self::ungate($entity);
        mtrace("local_intellistream: historical backfill — '{$entity}' made no progress in "
            . self::MAX_NO_PROGRESS . ' runs; released: the backfill moves on and the 15-minute task and '
            . 'verification sweep carry it from now on.');
    }

    /**
     * Whether the full campaign has finished. Sticky: only reset() clears it,
     * so an accidentally re-triggered task is a no-op rather than a re-export.
     *
     * @return bool
     */
    public static function is_complete(): bool {
        return (bool)(int)get_config(config::COMPONENT, self::KEY_PREFIX . 'complete');
    }

    /**
     * A keyset (id-resumable) entity is any non-derived registry entry. Every
     * non-derived entity is streamed `ORDER BY id ASC` by export_entity()
     * today, so all carry a monotonic id; derived aggregates (e.g. userlogins)
     * are not read here: their own task keeps them, and the backfill only asks it
     * to send every row again.
     *
     * @param array $def Registry entry.
     * @return bool
     */
    private static function is_keyset(array $def): bool {
        return empty($def['derived']);
    }

    /**
     * Mint (once) and return the campaign's stable snapshot_batch UUID. Reused
     * on every resume — safe because the middleware dedup ignores snapshot_batch.
     *
     * @return string
     */
    private static function ensure_batch(): string {
        $batch = get_config(config::COMPONENT, self::KEY_PREFIX . 'batch');
        if (!is_string($batch) || $batch === '') {
            $batch = \core\uuid::generate();
            set_config(self::KEY_PREFIX . 'batch', $batch, config::COMPONENT);
        }
        return $batch;
    }

    /**
     * True when every entity in the (effective) registry has its done flag set.
     *
     * @param array $registry
     * @return bool
     */
    private static function all_done(array $registry): bool {
        $released = self::released();
        foreach (array_keys($registry) as $entity) {
            if (!isset($released[$entity]) && !get_config(config::COMPONENT, self::KEY_PREFIX . 'done_' . $entity)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Mark the whole campaign complete (sticky).
     *
     * @return void
     */
    private static function mark_complete(): void {
        set_config(self::KEY_PREFIX . 'complete', 1, config::COMPONENT);
    }

    /**
     * Assemble a run() result array.
     *
     * @param bool $complete
     * @param string|null $reason
     * @param string|null $entity
     * @param int $rows
     * @return array
     */
    private static function result(
        bool $complete,
        ?string $reason = null,
        ?string $entity = null,
        int $rows = 0
    ): array {
        $status = self::status();
        $out = [
            'complete'       => $complete,
            'entities_total' => $status['entities_total'],
            'entities_done'  => $status['entities_done'],
            'rows'           => $rows,
            'batch'          => $status['batch'],
        ];
        if ($reason !== null) {
            $out['reason'] = $reason;
        }
        if ($entity !== null) {
            $out['entity'] = $entity;
        }
        return $out;
    }
}
