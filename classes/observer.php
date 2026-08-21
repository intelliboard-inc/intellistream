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
 * Event observer (hot path) for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Receives every Moodle event and appends a serialized record to the buffer.
 *
 * Hot path. Does no database queries and no network calls. Any failure is
 * swallowed so capture can never break a host page render.
 */
class observer {
    /**
     * Capture a single Moodle event.
     *
     * @param \core\event\base $event
     */
    public static function capture(\core\event\base $event): void {
        if (!config::enabled()) {
            return;
        }

        // Trackadmin=0 — drop events whose actor has the site-admin role.
        // Reads $event->userid (no DB) and compares against $CFG->siteadmins
        // (a comma-separated string already cached by Moodle's config). Hot
        // path, so we avoid is_siteadmin() which queries mdl_role_assignments.
        if (!config::trackadmin_enabled()) {
            $uid = (int)($event->userid ?? 0);
            if ($uid > 0) {
                global $CFG;
                $admins = isset($CFG->siteadmins) ? explode(',', (string)$CFG->siteadmins) : [];
                if (in_array((string)$uid, $admins, true)) {
                    return;
                }
            }
        }

        try {
            global $CFG;

            $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

            // Request context. getremoteaddr() reads $_SERVER (no DB) and
            // respects Moodle's reverse-proxy config; the user agent comes from
            // core's accessor and is bounded to keep the payload small. In a
            // CLI/cron context there is no HTTP request, so both may be null —
            // that is expected and harmless.
            $useragent = self::user_agent();

            $payload = [
                'id'                     => \core\uuid::generate(),
                'site_id'                => config::site_id(),
                'captured_at'            => clock::now(),
                'record_type'            => 'event',
                'event_name'             => '\\' . get_class($event),
                'event_data'             => $event->get_data(),
                'moodle_version'         => $CFG->version ?? null,
                'moodle_site_identifier' => $CFG->siteidentifier ?? null,
                'plugin_version'         => config::plugin_version(),
                'client_ip'              => getremoteaddr(),
                'user_agent'             => $useragent,
            ];

            $json = json_encode($payload, $flags);
            if ($json === false) {
                return;
            }

            // Oversized payload: drop event_data, keep the envelope, flag it.
            if (strlen($json) > buffer::MAX_EVENT_BYTES) {
                $payload['event_data'] = null;
                $payload['truncated'] = true;
                $json = json_encode($payload, $flags);
                if ($json === false) {
                    return;
                }
            }

            buffer::append($json . "\n");
        } catch (\Throwable $e) {
            // Capture must never break the host page render.
            return;
        }
    }

    /**
     * Bounded user-agent string for the capture payload, or null when absent.
     *
     * Uses core's accessor rather than reading $_SERVER directly. Note core
     * returns FALSE (not null) when the header is missing, so it is normalised
     * here: the shipped `user_agent` field must stay null in that case, because
     * the ETL writes this value straight into the raw log table
     * (enterprise-etl/load_raw.py) and a `false` would land there as a value
     * rather than a NULL.
     *
     * @return string|null
     */
    public static function user_agent(): ?string {
        $useragent = \core_useragent::get_user_agent_string();
        if (!is_string($useragent) || $useragent === '') {
            return null;
        }
        return (strlen($useragent) > 1024) ? substr($useragent, 0, 1024) : $useragent;
    }
}
