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
        \local_intellistream\exporter::reset_registry_cache();
        $data = $this->get_custom_data();
        $only = (!empty($data->only) && is_array($data->only)) ? array_values($data->only) : [];

        // Limited to backfill::run_limit_sec(), as the scheduled task; a run stopped by it queues the
        // next one below.
        $result = \local_intellistream\backfill::run($only, \local_intellistream\backfill::run_limit_sec());
        mtrace('local_intellistream: backfill run — ' . json_encode($result));

        // A run that stops short queues the next one itself, for any request: the campaign
        // a control-plane reset or a partial request starts has nothing else to continue it
        // (the scheduled task is off by default), and a time-limited run that pauses on a full
        // buffer is an ordinary stop. A new install's held chain follows the hold switch
        // (`backfillgate`), not the hold list: the list empties as the held entities finish,
        // before the census, and the census still has to run. The setting `backfillstop`
        // ends every chain (the run returns `stopped`). A run that could not start (no destination yet, or another
        // campaign holding the lock) is retried too: a reset from the control plane has
        // already cleared the progress. An unpaired site is not retried: pairing it again may
        // pair it to another connection, and a held backfill is queued again by the
        // 15-minute task anyway.
        $reason = $result['reason'] ?? '';
        $stoppedshort = in_array($reason, ['paused', 'incomplete', 'timelimit'], true)
            && (empty($data->held) || (int)\local_intellistream\config::get('backfillgate', 1));
        $notstarted = in_array($reason, ['no_destination', 'busy'], true);
        if (empty($result['complete']) && ($stoppedshort || $notstarted)) {
            $moved = self::moved($result);
            $attempt = $moved ? 1 : (int)($data->attempt ?? 0) + 1;
            if ($attempt > self::MAX_ATTEMPTS) {
                mtrace('local_intellistream: backfill still cannot start after ' . self::MAX_ATTEMPTS
                    . ' attempts (about a week); giving up — request it again once the site is ready.');
                return;
            }
            if ($this->superseded($only, !empty($data->held))) {
                mtrace('local_intellistream: another queued backfill run covers this request; '
                    . 'not queueing a second.');
                return;
            }
            $next = new self();
            $next->set_custom_data(['only' => $only, 'held' => (int)!empty($data->held), 'attempt' => $attempt]);
            $backoff = min(3600, 60 * (2 ** min($attempt - 1, 6)));
            // After a run that read, the next waits at least one run limit; a run that is
            // longer than the limit (a census, `userlogins`, a slow page) makes the share
            // of time the chain holds a cron runner larger than half.
            $delay = $notstarted ? $backoff : max($backoff, \local_intellistream\backfill::run_limit_sec());
            $next->set_next_run_time(time() + $delay);
            \core\task\manager::queue_adhoc_task($next);
        }
    }

    /**
     * Whether a run moved the campaign forward, which starts the back-off again, so a long
     * campaign is never cut off by MAX_ATTEMPTS. A run moved when it stopped at its time
     * limit (it does that only after a page that moved the watermark, or an entity it
     * finished) or buffered rows, also when it then paused on the buffer: a limited read
     * checks the buffer before its first page, so a run whose destination is down pauses
     * with 0 rows. A run that moved nothing counts up, 1 min, 2, 4 … to an hour, so a
     * stalled chain does not keep adding to its buffer.
     *
     * @param array $result What backfill::run() returned.
     * @return bool
     */
    public static function moved(array $result): bool {
        return ($result['reason'] ?? '') === 'timelimit' || !empty($result['rows']);
    }

    /**
     * Whether another backfill task that will run covers $only, so this chain does not
     * queue its next run: a full request covers everything, a partial one the same list.
     * Two chains would otherwise run in each other's gaps.
     *
     * Only a row that can still run counts: one that has not failed (`faildelay` 0; a
     * failed row may never run again, and from Moodle 4.4 one that used up its attempts
     * stays queued for weeks), held as this one is (turning the hold off ends a held
     * chain, not a request from the control plane). A row queued for later is the next
     * run of another chain. A row due now may be running, as this one is: of two such
     * rows the lower id continues, so they never both stop. This task's own row stays
     * queued while it runs and is excluded.
     *
     * @param array $only This request's entities ([] for all).
     * @param bool $held Whether this is a new install's held chain.
     * @return bool
     */
    private function superseded(array $only, bool $held): bool {
        global $DB;
        $me = (int)$this->get_id();
        $rows = $DB->get_records_select(
            'task_adhoc',
            '(classname = :a OR classname = :b) AND id <> :me AND faildelay = 0',
            ['a' => '\\' . self::class, 'b' => self::class, 'me' => $me],
            '',
            'id, customdata, nextruntime'
        );
        sort($only);
        $now = time();
        foreach ($rows as $row) {
            $data = json_decode((string)$row->customdata);
            if (empty($data->held) === $held) {
                continue;
            }
            $theirs = (!empty($data->only) && is_array($data->only)) ? array_values($data->only) : [];
            sort($theirs);
            if ($theirs !== [] && $theirs !== $only) {
                continue;
            }
            if ((int)$row->nextruntime > $now || ($me > 0 && (int)$row->id < $me)) {
                return true;
            }
        }
        return false;
    }
}
