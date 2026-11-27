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
 * Event observers for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Every event observer of the plugin (see db/events.php).
 *
 * capture() receives every Moodle event and appends a serialized record to the
 * buffer, with no database queries and no network calls.
 *
 * The exception callbacks record error-signalling events (failed logins, password
 * policy and web service failures) as `exceptions` records: Moodle has no generic
 * error event and a plugin must not register a global error handler.
 *
 * The entity callbacks snapshot a changed row of a table that has no change
 * timestamp (course_modules, course_completions, user_info_*, grade_letters), so it
 * is sent within about a minute instead of at the next daily verification sweep.
 * Deletes are reconciled by the daily census. Every callback catches every failure.
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
                'event_data'             => self::normalize_event_data($event->get_data()),
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
     * Event data with booleans cleared from the numeric fields.
     *
     * Moodle core puts false (not null) in `objectid` for events that act on no
     * object, for example \tool_admin_presets\event\presets_listed. The
     * reporting service writes these fields into numeric columns, where a boolean
     * fails the insert for the whole batch. `other` is left alone: it is free-form
     * and lands in a text column. Null (not 0) is the replacement because 0 looks
     * like a real Moodle id. Takes the array so it is testable without a database.
     *
     * @param array $data as returned by \core\event\base::get_data()
     * @return array
     */
    public static function normalize_event_data(array $data): array {
        $numeric = ['objectid', 'userid', 'courseid', 'relateduserid', 'contextid',
                    'contextlevel', 'contextinstanceid', 'edulevel', 'anonymous',
                    'timecreated'];
        foreach ($numeric as $key) {
            if (array_key_exists($key, $data) && is_bool($data[$key])) {
                $data[$key] = null;
            }
        }
        return $data;
    }

    /**
     * Bounded user-agent string for the capture payload, or null when absent.
     *
     * Uses core's accessor rather than reading $_SERVER directly. Core returns
     * false (not null) when the header is missing, so it is normalised here: the
     * shipped `user_agent` field must be null in that case, because the reporting
     * service stores this value as is and a `false` would land as a value rather
     * than a NULL.
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

    /**
     * Memoised `exceptions` datatype switch for this request; null = not yet read.
     *
     * @var bool|null
     */
    protected static $datatypeenabled = null;

    /**
     * `\core\event\user_login_failed`
     *
     * @param \core\event\base $event
     */
    public static function user_login_failed($event): void {
        // No stack trace: this fires from core's login handler, so the trace is
        // identical on every occurrence and has no diagnostic value, while it
        // would be most of the record's size on a capture path an
        // unauthenticated caller can drive.
        try {
            self::record_event($event, 'login_failed', 'User login failed', false);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * `\core\event\user_password_policy_failed`
     *
     * @param \core\event\base $event
     */
    public static function user_password_policy_failed($event): void {
        try {
            self::record_event($event, 'password_policy_failed', 'Password rejected by policy');
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * `\core\event\webservice_function_called`
     *
     * Buffered only when the event carries an exception payload --
     * successful WS calls are noise for an exceptions feed.
     *
     * @param \core\event\base $event
     */
    public static function webservice_function_called($event): void {
        try {
            $data = $event->get_data();
            $other = is_array($data['other'] ?? null) ? $data['other'] : [];
            if (empty($other['exception'])) {
                return;
            }

            $message = 'Web service exception';
            if (is_array($other['exception']) && !empty($other['exception']['message'])) {
                $message = (string)$other['exception']['message'];
            } else if (is_string($other['exception'])) {
                $message = (string)$other['exception'];
            }

            self::record_event($event, 'webservice_exception', $message);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * `\core\event\webservice_login_failed`
     *
     * @param \core\event\base $event
     */
    public static function webservice_login_failed($event): void {
        try {
            self::record_event($event, 'webservice_login_failed', 'Web service login failed');
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Drop the memoised datatype switch.
     *
     * Called by config_repository::save()/delete() for the same reason
     * exporter::reset_registry_cache() is: a write must be visible to a later read
     * in the same request. Tests also call it between fixtures.
     *
     * @return void
     */
    public static function reset_datatype_cache(): void {
        self::$datatypeenabled = null;
    }

    /**
     * Whether the `exceptions` datatype is switched on.
     *
     * webhook_commands::save_datatype_config() can disable `exceptions` (it is a
     * log datatype, so the cannot_disable_required guard does not apply). The
     * usual enforcement point does not cover it: `exceptions` is not in
     * exporter::registry(), so apply_overrides_to_registry() cannot remove it.
     * So the observer checks the switch itself.
     *
     * Memoised per request. This runs on the failed-login path, which is reachable
     * by an unauthenticated caller, so it must not add a query per attempt. A
     * missing row counts as enabled (config_repository::is_enabled()).
     *
     * @return bool
     */
    protected static function datatype_enabled(): bool {
        if (self::$datatypeenabled === null) {
            try {
                self::$datatypeenabled = (new repositories\config_repository())
                    ->is_enabled(datatypes\exceptions_datatype::ENTITY);
            } catch (\Throwable $e) {
                // Fail open: a database error should not silently stop error
                // capture.
                self::$datatypeenabled = true;
            }
        }
        return self::$datatypeenabled;
    }

    /**
     * Build the buffer record and append it.
     *
     * Best-effort everywhere: missing fields default to null/'' rather
     * than abort.
     *
     * @param \core\event\base|object $event
     * @param string $kind  Short category label.
     * @param string $message Human-readable message.
     * @param bool $wanttrace Whether to attach a stack trace. False for events
     *        whose trace is always the same code path and carries no diagnostic
     *        value, where it is pure buffer cost.
     */
    protected static function record_event(
        $event,
        string $kind,
        string $message,
        bool $wanttrace = true
    ): void {
        if (!config::enabled()) {
            return;
        }
        if (!self::datatype_enabled()) {
            return;
        }
        // Rate limit before building the record, so a throttled attempt does not
        // pay for a backtrace either. Applies to every kind, not just the login
        // ones: webservice_login_failed is also reachable with no session (a bad
        // token is enough), so limiting only user_login_failed would leave an
        // equivalent unauthenticated path open.
        if (!exception_quota::allow()) {
            return;
        }

        try {
            global $CFG;

            $data = method_exists($event, 'get_data') ? (array)$event->get_data() : [];

            // Resolve a Moodle event class name; fall back to the kind label
            // when we somehow got a non-event object.
            $eventclass = is_object($event) ? '\\' . get_class($event) : $kind;
            if (!is_string($eventclass) || $eventclass === '\\') {
                $eventclass = $kind;
            }

            // Stack trace: use a debug_backtrace bounded to keep the record
            // well under the buffer's MAX_EVENT_BYTES cap. We strip args to
            // avoid leaking any caller-passed secrets (passwords on
            // user_login_failed, WS tokens, etc.).
            //
            // Skipped when $wanttrace is false. For a failed login the trace is
            // always core's own login code path, identical every time, so it tells
            // an operator nothing while being the bulk of the record.
            $trace = '';
            if ($wanttrace) {
                try {
                    $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
                    $lines = [];
                    foreach ($frames as $i => $f) {
                        $file = $f['file'] ?? '';
                        $line = $f['line'] ?? '';
                        $fn   = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
                        $lines[] = "#{$i} {$file}({$line}): {$fn}";
                    }
                    $trace = implode("\n", $lines);
                    if (strlen($trace) > 8192) {
                        $trace = substr($trace, 0, 8192);
                    }
                } catch (\Throwable $e) {
                    $trace = '';
                }
            }

            // URL of the request that failed. qualified_me() is core's accessor
            // for this: it honours $CFG->sslproxy and Moodle's reverse-proxy
            // config (which a raw $_SERVER read does not) and returns false
            // under CLI, where there is no request.
            //
            // Sensitive query parameters are stripped before the URL is stored.
            // This observer fires on webservice_function_called, so on a site
            // that accepts a token in the query string a failing call would
            // otherwise ship `?wstoken=...` off-site inside the exceptions
            // record. The stack trace above is already captured with
            // DEBUG_BACKTRACE_IGNORE_ARGS for the same reason.
            $url = self::request_url();

            $payload = [
                'id'             => \core\uuid::generate(),
                'site_id'        => config::site_id(),
                'captured_at'    => clock::now(),
                'plugin_version' => config::plugin_version(),
                'moodle_version' => isset($CFG->version) ? (int)$CFG->version : null,
                'record_type'    => datatypes\exceptions_datatype::RECORD_TYPE,
                'entity'         => datatypes\exceptions_datatype::ENTITY,
                'error_class'    => $eventclass,
                'error_message'  => self::truncate($message, 4096),
                'error_file'     => '',
                'error_line'     => null,
                'stack_trace'    => $trace,
                'userid'         => isset($data['userid']) ? (int)$data['userid'] : null,
                'courseid'       => isset($data['courseid']) ? (int)$data['courseid'] : null,
                'url'            => $url,
            ];

            buffer::append_record($payload);
        } catch (\Throwable $e) {
            // Capture must never break the host page render.
            return;
        }
    }

    /**
     * Query parameters never allowed to leave the site inside a captured URL.
     *
     * `wstoken`/`token` are the web-service credential; `sesskey` is Moodle's
     * CSRF token; the rest cover login and password-reset flows that put a
     * credential in the query string.
     */
    protected const SENSITIVE_URL_PARAMS = [
        'wstoken', 'token', 'sesskey', 'password', 'newpassword', 'key', 'secret',
    ];

    /**
     * Current request URL with sensitive query parameters removed.
     *
     * @return string Empty string when there is no HTTP request (CLI/cron).
     */
    protected static function request_url(): string {
        try {
            $full = qualified_me();
            if ($full === false || !is_string($full) || $full === '') {
                return '';
            }

            $url = new \moodle_url($full);
            $url->remove_params(self::SENSITIVE_URL_PARAMS);
            $clean = $url->out(false);

            return self::truncate($clean, 2048);
        } catch (\Throwable $e) {
            // A URL we cannot parse must not cost us the whole exception record,
            // and must not be stored unscrubbed either.
            return '';
        }
    }

    /**
     * Bounded string trim used for free-form error messages.
     *
     * @param string $s
     * @param int $max
     * @return string
     */
    protected static function truncate(string $s, int $max): string {
        return (strlen($s) > $max) ? substr($s, 0, $max) : $s;
    }

    /**
     * Activity (course module) created.
     *
     * @param \core\event\course_module_created $event
     * @return void
     */
    public static function course_module_created(\core\event\course_module_created $event): void {
        try {
            exporter::capture_entity_row('course_modules', $event->objectid);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Activity (course module) updated (renamed, moved, visibility/completion changed).
     *
     * @param \core\event\course_module_updated $event
     * @return void
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        try {
            exporter::capture_entity_row('course_modules', $event->objectid);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * A user completed a course.
     *
     * @param \core\event\course_completed $event
     * @return void
     */
    public static function course_completed(\core\event\course_completed $event): void {
        try {
            self::capture_completions($event);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Course completion state updated.
     *
     * @param \core\event\course_completion_updated $event
     * @return void
     */
    public static function course_completion_updated(\core\event\course_completion_updated $event): void {
        try {
            self::capture_completions($event);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Snapshot the course_completions row this event affects.
     *
     * User-scoped only: an event without `relateduserid` is skipped, because
     * matching on `course` alone would read and buffer a whole course's completion
     * rows while a teacher saves the completion settings. `course_completed` sets
     * `relateduserid`, so every actual completion is captured. A row that changes
     * without a per-user event (for example after a criteria change) is picked up
     * by the daily verification sweep, as `course_completions` has no watermark
     * column; an adhoc task would add a core-table write to the capture path.
     *
     * @param \core\event\base $event
     * @return void
     */
    private static function capture_completions(\core\event\base $event): void {
        $courseid = (int) $event->courseid;
        $userid = (int) ($event->relateduserid ?? 0);
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        exporter::capture_entity_match('course_completions', ['course' => $courseid, 'userid' => $userid]);
    }

    /**
     * New user: capture that user's profile-field values.
     *
     * @param \core\event\user_created $event
     * @return void
     */
    public static function user_created(\core\event\user_created $event): void {
        try {
            exporter::capture_entity_match('user_info_data', ['userid' => $event->objectid]);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * User updated: profile-field values may have changed.
     *
     * @param \core\event\user_updated $event
     * @return void
     */
    public static function user_updated(\core\event\user_updated $event): void {
        try {
            exporter::capture_entity_match('user_info_data', ['userid' => $event->objectid]);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Custom profile field definition created.
     *
     * @param \core\event\user_info_field_created $event
     * @return void
     */
    public static function user_info_field_created(\core\event\user_info_field_created $event): void {
        try {
            exporter::capture_entity_row('user_info_field', $event->objectid);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Custom profile field definition updated.
     *
     * @param \core\event\user_info_field_updated $event
     * @return void
     */
    public static function user_info_field_updated(\core\event\user_info_field_updated $event): void {
        try {
            exporter::capture_entity_row('user_info_field', $event->objectid);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Profile field category created.
     *
     * @param \core\event\user_info_category_created $event
     * @return void
     */
    public static function user_info_category_created(\core\event\user_info_category_created $event): void {
        try {
            exporter::capture_entity_row('user_info_category', $event->objectid);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Profile field category updated.
     *
     * @param \core\event\user_info_category_updated $event
     * @return void
     */
    public static function user_info_category_updated(\core\event\user_info_category_updated $event): void {
        try {
            exporter::capture_entity_row('user_info_category', $event->objectid);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Grade letter (grade-to-letter boundary) created.
     *
     * @param \core\event\grade_letter_created $event
     * @return void
     */
    public static function grade_letter_created(\core\event\grade_letter_created $event): void {
        try {
            exporter::capture_entity_row('grade_letters', $event->objectid);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Grade letter updated.
     *
     * @param \core\event\grade_letter_updated $event
     * @return void
     */
    public static function grade_letter_updated(\core\event\grade_letter_updated $event): void {
        try {
            exporter::capture_entity_row('grade_letters', $event->objectid);
        } catch (\Throwable $e) {
            self::report_failure($e);
        }
    }

    /**
     * Report a failure of an event callback without breaking the page.
     *
     * Moodle's event dispatcher catches exceptions but not errors, so every
     * callback catches \Throwable itself and reports it here, the same way as the
     * dispatcher: with the stack trace, and not while an upgrade runs.
     *
     * @param \Throwable $e
     * @return void
     */
    private static function report_failure(\Throwable $e): void {
        global $CFG;
        if (!empty($CFG->upgraderunning)) {
            return;
        }
        debugging('local_intellistream: event capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
    }
}
