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
 * Adhoc task that runs (or resumes) the historical backfill on demand.
 *
 * Queued by the control webhook's reset_migration / reset_datatype handlers
 * (see {@see \local_intellistream\webhook_commands}) so the multi-minute
 * backfill runs in Moodle's adhoc runner rather than inline in the HTTP
 * request. Mirrors {@see set_lti_role_adhoc_task}. Custom data
 * `{only:[entity,...]}` restricts the run to those entities (empty = full run).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\task;

/**
 * Run the IntelliStream historical backfill (control-triggered).
 */
class run_backfill_adhoc_task extends \core\task\adhoc_task {
    /** Re-queues while it cannot start: back-off reaches an hour, so this is about a week. */
    const MAX_ATTEMPTS = 175;

    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_run_backfill', 'local_intellistream');
    }

    /**
     * Do the job. Exceptions bubble up so the adhoc runner retries.
     *
     * @return void
     */
    public function execute() {
        // Queued by a settings save or a webhook in another request; read what is saved now.
        \local_intellistream\config::refresh();
        $data = $this->get_custom_data();
        $only = (!empty($data->only) && is_array($data->only)) ? array_values($data->only) : [];

        $result = \local_intellistream\backfill::run($only);
        mtrace('local_intellistream: backfill run — ' . json_encode($result));

        // A new install's held tables wait for this campaign, so a run that stops short
        // queues the next one itself, backing off 1 min, 2, 4 … up to an hour. A run that
        // could not start (no destination yet, or another campaign holding the lock) does
        // the same for any request: a reset from the control plane has already cleared the
        // progress, so dropping the task here would leave nothing to re-run it. An unpaired
        // site is not retried: pairing it again may pair it to another connection, and a
        // new install's held backfill is queued again by the 15-minute task anyway.
        $reason = $result['reason'] ?? '';
        $waiting = !$only && \local_intellistream\backfill::gate_active()
            && in_array($reason, ['paused', 'incomplete'], true);
        $notstarted = in_array($reason, ['no_destination', 'busy'], true);
        if (empty($result['complete']) && ($waiting || $notstarted)) {
            // A run that moved forward starts the back-off again, so a long campaign is
            // never cut off by the cap below; only a run that cannot start counts up.
            $attempt = !empty($result['rows']) ? 1 : (int)($data->attempt ?? 0) + 1;
            if ($attempt > self::MAX_ATTEMPTS) {
                mtrace('local_intellistream: backfill still cannot start after ' . self::MAX_ATTEMPTS
                    . ' attempts (about a week); giving up — request it again once the site is ready.');
                return;
            }
            $next = new self();
            $next->set_custom_data(['only' => $only, 'held' => (int)!empty($data->held), 'attempt' => $attempt]);
            $next->set_next_run_time(time() + min(3600, 60 * (2 ** min($attempt - 1, 6))));
            \core\task\manager::queue_adhoc_task($next);
        }
    }
}
