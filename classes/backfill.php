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
 * Drives the one-time historical backfill as a RESUMABLE, run-once job.
 *
 * The backfill seeds the warehouse with the history that existed before the
 * event observer was switched on. It runs the whole registry in one pass
 * ("ship everything"), but records a per-entity keyset watermark
 * (`backfill_wm_<entity>`) and a per-entity done flag (`backfill_done_<entity>`)
 * in plugin config, so an interrupted run (timeout / OOM / reboot) is resumed
 * from where it stopped on the next operator-triggered run — never restarted
 * from zero.
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
     * Run (or resume) the backfill.
     *
     * @param array $only Restrict to these entity names (a partial run — never
     *                    emits the terminal census/schema, never marks complete).
     * @return array{complete:bool, reason?:string, entity?:string,
     *               entities_total:int, entities_done:int, rows:int, batch:string}
     */
    public static function run(array $only = []): array {
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

        // MEMORY_EXTRA (not HUGE/2G): every path here streams one row at a time
        // via get_recordset_select() and buffers to disk, so memory stays flat;
        // the raise only covers per-row JSON encode headroom on wide tables.
        raise_memory_limit(MEMORY_EXTRA);

        $batch = self::ensure_batch();
        $registry = exporter::registry_with_overrides();
        $names = array_keys($registry);
        $partial = !empty($only);
        if ($partial) {
            $names = array_values(array_intersect($names, $only));
        }

        $rows = 0;
        foreach ($names as $entity) {
            if (get_config(config::COMPONENT, self::KEY_PREFIX . 'done_' . $entity)) {
                continue; // Already exhausted on an earlier run.
            }
            try {
                if (self::is_keyset($registry[$entity])) {
                    $rows += exporter::export_entity_window(
                        $entity,
                        $batch,
                        $registry,
                        self::KEY_PREFIX . 'wm_' . $entity
                    );
                } else {
                    // Derived/aggregate entity (no monotonic id): export atomically.
                    $rows += exporter::export_entity($entity, $batch, $registry);
                }
            } catch (\Throwable $e) {
                // A keyset window threw (buffer stall or DB error): progress is
                // persisted in its watermark. Leave the entity NOT done, flush
                // what we have, and report paused so the operator re-runs.
                mtrace('local_intellistream: historical backfill paused on entity '
                    . $entity . ': ' . $e->getMessage());
                buffer::flush();
                shipper::run();
                return self::result(false, 'paused', $entity, $rows);
            }
            set_config(self::KEY_PREFIX . 'done_' . $entity, 1, config::COMPONENT);
        }

        // Terminal, complete-set-only steps: only for a FULL run in which every
        // registry entity is now done. A partial --entity run stops here so it
        // never emits an incomplete census (which the reconciler would treat as
        // authoritative) or half a schema catalog.
        if (!$partial && self::all_done($registry)) {
            exporter::export_inform_dyn_schema($batch, $registry);
            exporter::export_census($batch, $registry);
            buffer::flush();
            shipper::run();
            self::mark_complete();
            mtrace('local_intellistream: historical backfill complete.');
            return self::result(true, 'complete', null, $rows);
        }

        // Partial run, or more entities remain for a later resume.
        buffer::flush();
        shipper::run();
        return self::result(false, $partial ? 'partial' : 'incomplete', null, $rows);
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
     * do not and must be exported atomically.
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
        foreach (array_keys($registry) as $entity) {
            if (!get_config(config::COMPONENT, self::KEY_PREFIX . 'done_' . $entity)) {
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
