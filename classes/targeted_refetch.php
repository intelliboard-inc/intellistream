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
 * Targeted, time-windowed entity re-fetch driver for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Drives a TARGETED re-fetch: re-emit a chosen set of entity types
 * for a chosen [from, to] time window into the buffer, then ship to S3.
 *
 * A repair tool for gaps — a missed window, a lagging table — distinct from the
 * whole-history {@see backfill}. It is:
 *  - STATELESS: it mints its own snapshot_batch and reads/writes NO watermark
 *    config (neither the incremental `cdc_state` nor the historical `backfill_*`),
 *    so normal capture (refresh_entities / daily_snapshot) is completely
 *    unaffected;
 *  - IDEMPOTENT downstream: deterministic uuid5 ids + the middleware content-hash
 *    dedup make re-emitting rows that already arrived harmless;
 *  - TIME-WINDOWED where possible: entities whose change-timestamp column the
 *    detector recognises are filtered to the window; entities with none (and
 *    derived aggregates) are re-emitted whole — see
 *    {@see exporter::export_entity_range()}.
 */
class targeted_refetch {
    /**
     * Run a targeted re-fetch.
     *
     * @param array $entities Registry keys to re-fetch; empty = all entities.
     * @param int $from Window start (epoch seconds), inclusive.
     * @param int $to Window end (epoch seconds), inclusive.
     * @return array{ran:bool, reason?:string, batch?:string, rows?:int,
     *               entities?:int, from?:int, to?:int, skipped?:array}
     */
    public static function run(array $entities, int $from, int $to): array {
        if (!config::enabled()) {
            mtrace('local_intellistream: disabled — targeted re-fetch skipped.');
            return ['ran' => false, 'reason' => 'disabled'];
        }
        if ($from <= 0 || $to <= 0 || $from >= $to) {
            // Require a strictly positive window; from === to is a zero-duration
            // no-op, not a valid re-fetch.
            mtrace("local_intellistream: targeted re-fetch skipped — invalid window "
                . "(from={$from}, to={$to}).");
            return ['ran' => false, 'reason' => 'invalid_window', 'from' => $from, 'to' => $to];
        }

        // MEMORY_EXTRA, matching the backfill lane. Both stream through the same
        // recordset code and hold one row at a time, so neither needs the 2 GB
        // that MEMORY_HUGE reserves — and on shared multi-tenant hosting that
        // reservation is taken per worker, from a limit the whole host shares.
        raise_memory_limit(MEMORY_EXTRA);

        $registry = exporter::registry_with_overrides();
        $known = array_keys($registry);
        $skipped = [];
        if (empty($entities)) {
            $names = $known; // Empty selection = all entities.
        } else {
            $names = array_values(array_intersect($known, $entities));
            $skipped = array_values(array_diff($entities, $known));
            if ($skipped) {
                mtrace('local_intellistream: targeted re-fetch — ignoring unknown entit'
                    . (count($skipped) === 1 ? 'y' : 'ies') . ': ' . implode(', ', $skipped));
            }
        }

        // A non-empty selection that resolved to NOTHING (every name unknown —
        // typo, or a removed entity) is a mis-fired run, not a no-op success.
        // Report it as NOT run (with the skipped list) so the caller logs
        // 'skipped' rather than a misleading 'complete' zero-work run.
        if (!empty($entities) && empty($names)) {
            mtrace('local_intellistream: targeted re-fetch skipped — no known entities in the '
                . 'selection: ' . implode(', ', $skipped));
            return ['ran' => false, 'reason' => 'all_entities_unknown', 'skipped' => $skipped];
        }

        // Own batch — deliberately NOT the historical-backfill campaign batch.
        $batch = \core\uuid::generate();
        $rows = 0;
        foreach ($names as $entity) {
            $rows += exporter::export_entity_range($entity, $batch, $registry, $from, $to);
        }

        // Flush the buffer and ship, mirroring backfill::run()'s terminal steps.
        buffer::flush();
        shipper::run();

        mtrace(sprintf(
            'local_intellistream: targeted re-fetch — %d row(s) across %d entit%s, '
                . 'window [%s .. %s] (batch %s).',
            $rows,
            count($names),
            count($names) === 1 ? 'y' : 'ies',
            userdate($from),
            userdate($to),
            $batch
        ));

        $out = [
            'ran'      => true,
            'batch'    => $batch,
            'rows'     => $rows,
            'entities' => count($names),
            'from'     => $from,
            'to'       => $to,
        ];
        if ($skipped) {
            $out['skipped'] = $skipped;
        }
        return $out;
    }
}
