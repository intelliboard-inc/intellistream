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
 * Adhoc task: run (or resume) one recovery window in the background.
 *
 * Custom data: a new window {from, to, courseid, max_rows, rows_per_hour, confirm_large}, or
 * {resume: request_id}. Each execution works for at most RUN_SECONDS, then re-queues itself
 * with {resume} while the run is paused, so a long window never holds one cron process
 * for hours. When another run holds the window lock it re-queues itself unchanged. The chain
 * ends when the run completes, is stopped, or is refused.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

use local_intellistream\window_tool;

/**
 * Background recovery window run.
 */
class window_recovery_adhoc_task extends \core\task\adhoc_task {
    /** Work per execution, then re-queue. */
    const RUN_SECONDS = 1500;

    /** Delay before a paused run resumes (gives ship_events time to drain the buffer). */
    const RESUME_DELAY = 300;

    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_window_recovery', 'local_intellistream');
    }

    /**
     * Queue a new window run.
     *
     * @param array $data from, to, courseid, max_rows, rows_per_hour, confirm_large, and
     *                    optionally request_id (minted by the caller, e.g. the webhook)
     */
    public static function queue(array $data): void {
        $task = new self();
        $task->set_custom_data((object)$data);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Run one bounded slice of the window; re-queue while it is paused.
     */
    public function execute() {
        \local_intellistream\config::refresh();
        $data = (array)$this->get_custom_data();
        $opts = [
            'resume' => (string)($data['resume'] ?? ''),
            'request_id' => (string)($data['request_id'] ?? ''),
            'from' => (int)($data['from'] ?? 0),
            'to' => (int)($data['to'] ?? 0),
            'courseid' => !empty($data['courseid']) ? (int)$data['courseid'] : null,
            'max_rows' => (int)($data['max_rows'] ?? window_tool::DEFAULT_MAX_ROWS),
            // Null on a resume: the run keeps the pace it started with.
            'rows_per_hour' => isset($data['rows_per_hour']) ? (int)$data['rows_per_hour'] : null,
            'confirm_large' => !empty($data['confirm_large']),
            'max_seconds' => self::RUN_SECONDS,
            // Read one slice quota, then pause: the run re-queues after RESUME_DELAY instead of
            // sleeping in a cron slot.
            'yield' => self::RESUME_DELAY,
            'output' => function ($m) {
                mtrace($m);
            },
        ];
        $state = window_tool::run($opts);
        mtrace('recovery window: ' . $state['status']
            . (isset($state['request_id']) ? ' ' . $state['request_id'] : '')
            . (!empty($state['reason']) ? ' -- ' . $state['reason'] : ''));
        if (!empty($state['paused']) && !empty($state['request_id'])) {
            $next = new self();
            $next->set_custom_data((object)['resume' => $state['request_id']]);
            $next->set_next_run_time(time() + self::RESUME_DELAY);
            \core\task\manager::queue_adhoc_task($next);
        } else if (!empty($state['busy'])) {
            // Another run holds the site-wide window lock (a CLI run, or a second cron
            // runner on another slice): try this same new run / resume again later, so it
            // is not lost.
            $next = new self();
            $next->set_custom_data((object)$data);
            $next->set_next_run_time(time() + self::RESUME_DELAY);
            \core\task\manager::queue_adhoc_task($next);
        }
    }
}
