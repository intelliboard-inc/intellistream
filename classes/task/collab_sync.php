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
 * captured by refresh_entities like any other entity and reaches the reporting
 * warehouse's Collaborate participation report.
 *
 * No-ops unless the three collab_* credentials are configured (feature is
 * opt-in, per-site). Each finished session is synced once (tracked in
 * local_intellistream_colsync). Follows the legacy IntelliBoard plugin's
 * bb_collaborate integration.
 */
class collab_sync extends \core\task\scheduled_task {
    /**
     * Maximum sessions synced per cron pass.
     *
     * Each session costs at least one outbound HTTPS call to Blackboard's cloud
     * API (plus one per page of participants), so a site enabling the feature with
     * years of Collaborate history must not sync it all in one cron pass.
     *
     * The backlog is spread, not lost: sessions are synced oldest-first and each
     * completed one is recorded in local_intellistream_colsync, so the next pass
     * resumes where this one stopped.
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
        if (!\local_intellistream\services\config_service::table_is_real('collaborate')) {
            return;
        }

        $client = new collab_client();
        if (!$client->has_credentials()) {
            // Opt-in: no creds -> skip silently (same as legacy checkConnection).
            return;
        }
        if (!$client->endpoint_is_https()) {
            mtrace('local_intellistream: collab_sync - the Collaborate API endpoint must start with https://; skipping.');
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
     * The attendees' externalUserId values that are ids of existing Moodle users.
     *
     * Collaborate sets externalUserId to the Moodle user id for Moodle users. Guests
     * and other non-numeric ids are left out, and so are numbers with no current user
     * and the site guest account.
     *
     * @param array $attendees Attendee records from the Collaborate API.
     * @return array Set of user ids as strings, as array keys.
     */
    private function moodle_user_ids(array $attendees): array {
        global $CFG, $DB;

        $guestid = isset($CFG->siteguest) ? (int)$CFG->siteguest : 0;
        $candidates = [];
        foreach ($attendees as $item) {
            $id = isset($item['externalUserId']) ? (string)$item['externalUserId'] : '';
            if ($id !== '' && ctype_digit($id) && (int)$id > 0 && (int)$id !== $guestid) {
                $candidates[(int)$id] = true;
            }
        }
        $found = [];
        // Chunked so a very large session stays under the bound-parameter limit of
        // every supported database (MSSQL allows about 2100 per statement).
        foreach (array_chunk(array_keys($candidates), 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'uid');
            foreach ($DB->get_fieldset_select('user', 'id', "id $insql AND deleted = 0", $params) as $id) {
                $found[(string)(int)$id] = true;
            }
        }
        return $found;
    }

    /**
     * Pull + store attendees for every instance of one session.
     *
     * @param collab_client $client
     * @param string $sessionuid
     */
    protected function sync_session(collab_client $client, string $sessionuid): void {
        global $DB;

        foreach ($client->get_session_instances($sessionuid) as $instance) {
            if (!isset($instance['id'])) {
                continue;
            }
            $attendees = $client->get_session_attendees($sessionuid, (string)$instance['id']);
            $moodleuserids = $this->moodle_user_ids($attendees);
            foreach ($attendees as $item) {
                if (!isset($item['userId']) || !isset($item['externalUserId'])) {
                    continue;
                }
                // Only attendees whose externalUserId is a Moodle user id on this site
                // are stored, so every row can be exported and erased with that user.
                $externaluserid = (string)$item['externalUserId'];
                if (!isset($moodleuserids[$externaluserid])) {
                    continue;
                }
                $row = (object)[
                    'sessionuid'       => $sessionuid,
                    'useruid'          => (string)$item['userId'],
                    'external_user_id' => (int)$externaluserid,
                    'role'             => isset($item['role']) ? (string)$item['role'] : null,
                    'display_name'     => isset($item['displayName']) ? (string)$item['displayName'] : null,
                    'first_join_time'  => 0,
                    'last_left_time'   => 0,
                    'duration'         => 0,
                    // Counts joins; after the loop one is subtracted (clamped at 0) to
                    // turn it into rejoins, so an attendee the Collaborate API returns
                    // with no attendance array gets 0, not -1.
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
