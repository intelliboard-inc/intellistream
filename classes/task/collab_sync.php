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
 * This file is part of the local_intellistream plugin for Moodle.
 *
 * Scheduled task: pull Blackboard Collaborate per-user session attendance.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

use local_intellistream\collab\collab_client;

/**
 * Pulls per-user attendance for finished Collaborate sessions from the
 * Blackboard cloud API into local_intellistream_colpart. That table is then
 * captured by refresh_entities like any other entity and flows to the warehouse
 * collaborate_participation mart.
 *
 * No-ops unless the three collab_* credentials are configured (feature is
 * opt-in, per-site). Each finished session is synced once (tracked in
 * local_intellistream_colsync). Ported from the legacy IntelliBoard
 * bb_collaborate integration.
 */
class collab_sync extends \core\task\scheduled_task {
    /**
     * Maximum sessions synced per cron pass.
     *
     * Each session costs at least one outbound HTTPS call to Blackboard's cloud
     * API (plus one per page of participants), and the previous implementation
     * materialised EVERY unsynced finished session and worked the whole list in a
     * single run. On first enable against a site with years of Collaborate
     * history that is an unbounded third-party API hammering inside one cron pass.
     *
     * The backlog is not lost, only spread: sessions are synced oldest-first and
     * each completed one is recorded in local_intellistream_colsync, so the next
     * pass resumes exactly where this one stopped. At the default schedule that
     * drains 50 sessions per pass.
     */
    const MAX_SESSIONS_PER_RUN = 50;

    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_collab_sync', 'local_intellistream');
    }

    /**
     * Pull one bounded page of Collaborate attendance.
     *
     * No-ops when the Collaborate module is absent, so this is safe to leave
     * scheduled on a site that does not use it.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        // Collaborate module not installed -> nothing to do.
        if (!$DB->get_manager()->table_exists('collaborate')) {
            return;
        }

        $client = new collab_client();
        if (!$client->has_credentials()) {
            // Opt-in: no creds -> skip silently (same as legacy checkConnection).
            return;
        }
        if ($client->get_access_token() === '') {
            mtrace('local_intellistream: collab_sync - could not obtain Collaborate access token; skipping.');
            return;
        }

        // Finished sessions not yet synced, oldest first so a backlog drains in a
        // deterministic order across passes. Streamed with a recordset and capped
        // per run — see MAX_SESSIONS_PER_RUN.
        $now = time();
        $sql = "SELECT c.id, c.sessionuid
                  FROM {collaborate} c
             LEFT JOIN {local_intellistream_colsync} s ON s.sessionuid = c.sessionuid
                 WHERE c.sessionuid IS NOT NULL
                       AND c.sessionuid <> ''
                       AND c.timeend > 0
                       AND c.timeend <= :now
                       AND s.id IS NULL
              ORDER BY c.timeend ASC, c.id ASC";
        $rs = $DB->get_recordset_sql($sql, ['now' => $now], 0, self::MAX_SESSIONS_PER_RUN);

        $synced = 0;
        $seen = 0;
        try {
            foreach ($rs as $session) {
                $seen++;
                try {
                    $this->sync_session($client, $session->sessionuid);
                    if (!$DB->record_exists('local_intellistream_colsync', ['sessionuid' => $session->sessionuid])) {
                        $DB->insert_record('local_intellistream_colsync', (object)[
                            'sessionuid' => $session->sessionuid,
                            'timesynced' => time(),
                        ]);
                    }
                    $synced++;
                } catch (\Throwable $e) {
                    mtrace('local_intellistream: collab_sync - session ' . $session->sessionuid
                        . ' failed: ' . $e->getMessage());
                }
            }
        } finally {
            $rs->close();
        }

        if ($synced > 0) {
            mtrace("local_intellistream: collab_sync - synced attendance for {$synced} session(s).");
        }
        if ($seen >= self::MAX_SESSIONS_PER_RUN) {
            // Say so rather than truncating silently: an operator watching a first
            // enable needs to know work was deferred, not that it is finished.
            mtrace('local_intellistream: collab_sync - hit the per-run cap of '
                . self::MAX_SESSIONS_PER_RUN . ' session(s); the remaining backlog '
                . 'continues on the next cron pass.');
        }
    }

    /**
     * Pull + store attendees for every instance of one session.
     *
     * @param collab_client $client
     * @param string $sessionuid
     */
    private function sync_session(collab_client $client, string $sessionuid): void {
        global $DB;

        foreach ($client->get_session_instances($sessionuid) as $instance) {
            if (!isset($instance['id'])) {
                continue;
            }
            $attendees = $client->get_session_attendees($sessionuid, (string)$instance['id']);
            foreach ($attendees as $item) {
                if (!isset($item['userId']) || !isset($item['externalUserId'])) {
                    continue;
                }
                $row = (object)[
                    'sessionuid'       => $sessionuid,
                    'useruid'          => (string)$item['userId'],
                    'external_user_id' => (int)$item['externalUserId'],
                    'role'             => isset($item['role']) ? (string)$item['role'] : null,
                    'display_name'     => isset($item['displayName']) ? (string)$item['displayName'] : null,
                    'first_join_time'  => 0,
                    'last_left_time'   => 0,
                    'duration'         => 0,
                    // Counts JOINS, then one is subtracted after the loop to turn it
                    // into RE-joins. Seeding at -1 and incrementing per entry gave
                    // the right answer for an attendee with at least one attendance
                    // entry, but stored -1 for one the Collaborate API returns with
                    // no attendance array at all — a negative count of something
                    // that cannot be negative, which then flows downstream.
                    'rejoins'          => 0,
                    'timecreated'      => time(),
                ];
                foreach (($item['attendance'] ?? []) as $join) {
                    $row->duration += isset($join['duration']) ? (int)$join['duration'] : 0;
                    $row->rejoins += 1;
                    $joined = isset($join['joined']) ? strtotime($join['joined']) : 0;
                    $left = isset($join['left']) ? strtotime($join['left']) : 0;
                    if ($joined && ($row->first_join_time === 0 || $joined < $row->first_join_time)) {
                        $row->first_join_time = $joined;
                    }
                    if ($left && $left > $row->last_left_time) {
                        $row->last_left_time = $left;
                    }
                }
                // Joins -> rejoins. Clamped at 0 so an attendee with no attendance
                // entries reads as zero rejoins rather than minus one.
                $row->rejoins = max(0, $row->rejoins - 1);
                // Idempotent: replace any prior row for this (session, user).
                $DB->delete_records(
                    'local_intellistream_colpart',
                    ['sessionuid' => $sessionuid, 'external_user_id' => $row->external_user_id]
                );
                $DB->insert_record('local_intellistream_colpart', $row);
            }
        }
    }
}
