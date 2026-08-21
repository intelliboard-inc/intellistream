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
 * Page-dwell beacon endpoint for local_intellistream.
 *
 * Receives a navigator.sendBeacon POST from amd/src/dwell.js carrying the
 * time a user spent on a page, and appends a `page_dwell` record to the
 * shared on-disk buffer. Like the rest of the plugin it writes ZERO rows to
 * the Moodle database — it only validates the caller's existing session.
 * This is a lightweight beacon receiver, not a Moodle form/page. AJAX_SCRIPT
 * keeps Moodle from emitting page chrome or redirecting to login.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
// The beacon carries its own sesskey in the JSON body; do not also enforce
// the querystring sesskey check, which sendBeacon cannot satisfy.
define('NO_MOODLE_COOKIES', false);

require(__DIR__ . '/../../config.php');

// Always answer with a tiny body; the browser ignores beacon responses.
header('Content-Type: application/json; charset=utf-8');

/**
 * Emit a terminal JSON response and stop.
 *
 * @param int $httpstatus
 * @param string $result
 * @return void
 */
function local_intellistream_dwell_respond(int $httpstatus, string $result): void {
    if (!headers_sent()) {
        http_response_code($httpstatus);
    }
    echo json_encode(['result' => $result]);
    exit;
}

try {
    // Master + feature gate. Mirrors the observer's config::enabled() gate.
    if (!\local_intellistream\config::dwell_enabled()) {
        local_intellistream_dwell_respond(200, 'disabled');
    }

    // Beacons are POST-only.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        local_intellistream_dwell_respond(405, 'method');
    }

    // A valid Moodle session is required: no anonymous dwell records.
    if (!isloggedin() || isguestuser()) {
        local_intellistream_dwell_respond(200, 'noauth');
    }

    // Read the raw beacon body. sendBeacon sends a Blob, so the payload is in
    // php://input rather than $_POST.
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '' || strlen($raw) > 8192) {
        local_intellistream_dwell_respond(400, 'badbody');
    }

    $in = json_decode($raw, true);
    if (!is_array($in)) {
        local_intellistream_dwell_respond(400, 'badjson');
    }

    // Confirm the beacon belongs to this session. confirm_sesskey() only
    // reads the session; it performs no database write.
    $sesskey = isset($in['sesskey']) ? (string)$in['sesskey'] : '';
    if (!confirm_sesskey($sesskey)) {
        local_intellistream_dwell_respond(403, 'sesskey');
    }

    global $USER, $CFG;

    // Per-user rate limit. Every check above is a
    // gate on WHO may beacon, not HOW OFTEN, so an authenticated non-guest holding
    // a valid sesskey could append to the shared buffer without limit. That matters
    // because the buffer is shared: once it exceeds its cap,
    // shipper::enforce_disk_cap() drops the OLDEST unshipped files, so a flood by
    // one user evicts other users' captured events — the plugin's own alert string
    // calls those "permanently lost".
    //
    // Placed here deliberately: after the session and sesskey checks, so the
    // counter is keyed to a verified user and cannot be driven by an anonymous
    // caller, but before any buffer work.
    if (!\local_intellistream\dwell_quota::allow((int)$USER->id)) {
        local_intellistream_dwell_respond(200, 'throttled');
    }

    // Clamp the reported time to a plausible range. A negative value or a tab
    // left open for days is noise, not signal.
    $timespent = (int)($in['timespent_ms'] ?? 0);
    if ($timespent < 0) {
        $timespent = 0;
    }
    $max = \local_intellistream\config::dwell_max_ms();
    if ($timespent > $max) {
        $timespent = $max;
    }

    // P3b: discriminate between page_dwell and media_segment beacons.
    // Older clients send no record_type (page_dwell only); v0.5.9+ dwell.js
    // sets record_type='media_segment' for video / audio playback buckets.
    $recordtype = (string)($in['record_type'] ?? 'page_dwell');
    if (!in_array($recordtype, ['page_dwell', 'media_segment'], true)) {
        $recordtype = 'page_dwell';
    }
    if ($recordtype === 'media_segment' && !\local_intellistream\config::trackmedia_enabled()) {
        local_intellistream_dwell_respond(200, 'mediadisabled');
    }

    // Coarse page kind, constrained to the known vocabulary.
    $page = (string)($in['page'] ?? 'other');
    if (!in_array($page, ['course', 'module', 'user', 'site', 'other'], true)) {
        $page = 'other';
    }

    // For started_at, trust an RFC3339-looking client value, else stamp it now.
    $startedat = (string)($in['started_at'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $startedat)) {
        $startedat = \local_intellistream\clock::now();
    }

    // Context attribution is resolved SERVER-SIDE from the claimed contextid and
    // never trusted from the payload. Every other field
    // on this endpoint is validated or clamped, but contextid / contextlevel /
    // contextinstanceid / courseid were cast to int and stored as given — so any
    // authenticated user could attribute their dwell time to a course or activity
    // they cannot even see, silently corrupting the time-on-task analytics this
    // plugin exists to produce. The cast blocked injection; it did not make the
    // numbers true.
    //
    // Resolution order: look the context up in Moodle, derive level/instance/course
    // from the context record, and drop the whole attribution if the user cannot
    // access the resolved course. Dropping attribution keeps the dwell record (the
    // time was really spent) but records it as site-level rather than crediting it
    // to a course the user has no business in.
    $contextid = (int)($in['contextid'] ?? 0);
    $contextlevel = 0;
    $contextinstanceid = 0;
    $courseid = null;
    if ($contextid > 0) {
        $ctx = \context::instance_by_id($contextid, IGNORE_MISSING);
        if ($ctx) {
            $contextlevel = (int)$ctx->contextlevel;
            $contextinstanceid = (int)$ctx->instanceid;
            $coursectx = $ctx->get_course_context(false);
            if ($coursectx && (int)$coursectx->instanceid != SITEID) {
                $courseid = (int)$coursectx->instanceid;
            }
        } else {
            // Claimed a context that does not exist: discard the claim entirely.
            $contextid = 0;
        }
    }
    if ($courseid === null) {
        // No course derivable from the context (site-level page, or no contextid
        // sent by an older client): fall back to the claimed courseid, which is
        // access-checked below exactly like a derived one.
        $claimed = $in['courseid'] ?? null;
        $claimed = ($claimed === null || $claimed === '') ? null : (int)$claimed;
        if ($claimed !== null && $claimed > 0 && $claimed != SITEID) {
            $courseid = $claimed;
        }
    }
    if ($courseid !== null) {
        try {
            if (!can_access_course(get_course($courseid))) {
                $courseid = null;
                $contextid = 0;
                $contextlevel = 0;
                $contextinstanceid = 0;
            }
        } catch (\Throwable $e) {
            // Course does not exist. Keep the dwell, drop the attribution.
            $courseid = null;
            $contextid = 0;
            $contextlevel = 0;
            $contextinstanceid = 0;
        }
    }

    // Cap the relative URL length defensively.
    $url = (string)($in['url'] ?? '');
    if (strlen($url) > 2048) {
        $url = substr($url, 0, 2048);
    }

    // Request context. getremoteaddr() reads $_SERVER (no DB) and respects
    // Moodle's reverse-proxy config; the user agent comes from core's accessor
    // via the same helper the event observer uses (bounded, and normalised so a
    // missing header stays null rather than false). dwell.php always runs in an
    // HTTP request so both are normally present.
    $useragent = \local_intellistream\observer::user_agent();

    $dwellblock = [
        'userid'            => (int)$USER->id,
        'courseid'          => $courseid,
        'contextid'         => $contextid,
        'contextlevel'      => $contextlevel,
        'contextinstanceid' => $contextinstanceid,
        'page'              => $page,
        'url'               => $url,
        'timespent_ms'      => $timespent,
        'started_at'        => $startedat,
    ];
    // Fields used only by media_segment (clamped + bounded so beacons stay small).
    if ($recordtype === 'media_segment') {
        $mid = (string)($in['media_id'] ?? '');
        if (strlen($mid) > 255) {
            $mid = substr($mid, 0, 255);
        }
        $mkind = (string)($in['media_kind'] ?? 'video');
        if (!in_array($mkind, ['video', 'audio'], true)) {
            $mkind = 'video';
        }
        $dwellblock['media_id']      = $mid;
        $dwellblock['media_kind']    = $mkind;
        $dwellblock['media_pos_sec'] = max(0, (int)($in['media_pos_sec'] ?? 0));
        $dwellblock['bucket_sec']    = max(1, (int)($in['bucket_sec'] ?? 30));
    }

    $payload = [
        'id'             => \core\uuid::generate(),
        'site_id'        => \local_intellistream\config::site_id(),
        'captured_at'    => \local_intellistream\clock::now(),
        'plugin_version' => (int)\local_intellistream\config::plugin_version(),
        'moodle_version' => isset($CFG->version) ? (int)$CFG->version : null,
        'record_type'    => $recordtype,
        'client_ip'      => getremoteaddr(),
        'user_agent'     => $useragent,
        'dwell'          => $dwellblock,
    ];

    // Append to the same buffer the event observer writes to. No DB write.
    if (!\local_intellistream\buffer::append_record($payload)) {
        local_intellistream_dwell_respond(200, 'dropped');
    }

    local_intellistream_dwell_respond(200, 'ok');
} catch (\Throwable $e) {
    // Never surface an error to the beacon caller.
    local_intellistream_dwell_respond(200, 'error');
}
