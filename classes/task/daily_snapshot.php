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
 * Daily-cadence variant of refresh_entities for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

/**
 * Daily variant of refresh_entities — same export_all() under the hood,
 * registered ENABLED at 03:00 (see db/tasks.php).
 *
 * Enabled by default because it is what makes the warehouse self-correcting.
 * The 15-minute incremental (`refresh_entities`) carries intra-day deltas by
 * change-timestamp watermark, but a watermark cannot see a row whose timestamp
 * did not move, and it cannot see a deletion at all. The daily full re-reads
 * every registry entity and the census enumerates primary keys, which is what
 * lets the downstream reconciler correct drift and remove deleted rows. Without
 * it the warehouse diverges silently and nothing detects it.
 *
 * The cost is real and worth stating: this streams every row of roughly 170
 * tables to the buffer once a day, so it is the largest single contributor to
 * buffer volume and S3 egress. It is bounded by `maxbuffergb` — and that is a
 * lossy bound, because once the cap is reached enforce_disk_cap() deletes the
 * oldest un-shipped files rather than applying backpressure. A site whose
 * shipper cannot keep pace with its daily snapshot needs a larger cap or a
 * narrower registry, not a smaller one.
 *
 * An operator who genuinely wants a cheaper cadence disables this and relies on
 * `refresh_entities` alone, accepting that deletions will not reconcile.
 * Leaving BOTH enabled is fine (it just runs a redundant snapshot at 03:00),
 * but leaving BOTH disabled silently breaks the snapshot-only fields
 * (user.lastaccess, current grade state, etc.) that the event observer cannot
 * capture.
 *
 * Registered for 03:00 daily — quiet hour on most Moodle sites.
 */
class daily_snapshot extends \core\task\scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_daily_snapshot', 'local_intellistream');
    }

    /**
     * Same call refresh_entities makes, just on a different cadence.
     */
    public function execute(): void {
        if (!\local_intellistream\config::enabled()) {
            mtrace('local_intellistream: disabled — daily snapshot skipped.');
            return;
        }

        $result = \local_intellistream\exporter::export_all();
        mtrace(sprintf(
            'local_intellistream: daily snapshot complete — %d row(s) across %d entit%s (batch %s).',
            $result['rows'],
            $result['entities'],
            $result['entities'] === 1 ? 'y' : 'ies',
            $result['batch']
        ));
    }
}
