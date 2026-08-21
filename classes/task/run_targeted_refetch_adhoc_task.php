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
 * Adhoc task that runs a targeted re-fetch on demand.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

/**
 * Adhoc task that runs a one-off targeted re-fetch.
 *
 * Queued by the "Targeted re-fetch" admin page (refetch.php) when an operator
 * submits a window + entity selection, so the (potentially multi-minute) job
 * runs in Moodle's adhoc runner on the next cron pass rather than blocking the
 * HTTP request. Mirrors {@see run_backfill_adhoc_task}.
 *
 * Custom data:
 *   entities : array of registry keys to re-fetch (empty = all entities)
 *   from     : window start, epoch seconds (inclusive)
 *   to       : window end, epoch seconds (inclusive)
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_targeted_refetch_adhoc_task extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_run_targeted_refetch', 'local_intellistream');
    }

    /**
     * Run the targeted re-fetch. Exceptions bubble up so the adhoc runner retries.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $entities = (!empty($data->entities) && is_array($data->entities))
            ? array_values($data->entities) : [];
        $from = isset($data->from) ? (int)$data->from : 0;
        $to = isset($data->to) ? (int)$data->to : 0;

        $result = \local_intellistream\targeted_refetch::run($entities, $from, $to);
        mtrace('local_intellistream: targeted re-fetch (page-triggered) — ' . json_encode($result));

        // Best-effort audit row (mirrors historical_backfill).
        try {
            $DB->insert_record('local_intellistream_logs', (object)[
                'type'        => 'targeted_refetch',
                'datatype'    => null,
                'action'      => !empty($result['ran']) ? 'complete' : 'skipped',
                'details'     => json_encode($result, JSON_UNESCAPED_SLASHES),
                'timecreated' => time(),
            ]);
        } catch (\Throwable $e) {
            // Best-effort: the re-fetch already ran and its result is in the mtrace
            // above; a missing audit row must not turn that into a task failure.
            mtrace('local_intellistream: targeted re-fetch audit row could not be '
                . 'written: ' . $e->getMessage());
        }
    }
}
