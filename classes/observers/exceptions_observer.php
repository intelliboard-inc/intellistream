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
 * Exceptions observer for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\observers;

use local_intellistream\buffer;
use local_intellistream\clock;
use local_intellistream\config;
use local_intellistream\datatypes\exceptions_datatype;
use local_intellistream\exception_quota;
use local_intellistream\repositories\config_repository;

/**
 * Captures error-signalling Moodle events as `exceptions` records.
 *
 * Why event-based (NOT set_error_handler)
 * ---------------------------------------
 * Moodle expressly forbids plugins from registering global PHP error/
 * exception handlers: it owns its own handlers (lib/setuplib.php) and a
 * plugin grabbing the handler would change debug-display behaviour and
 * mask Moodle's own logging. We therefore listen for the Moodle events
 * that signal a failure already detected and surfaced by core / a
 * subsystem, and snapshot those.
 *
 * Why these events
 * ----------------
 * Moodle does NOT publish a generic "an error happened" event (there is
 * no `\core\event\unknown_logged` -- checked against Moodle 4.1 core).
 * The narrowest set of events that all carry a failure signal:
 *
 *   - \core\event\user_login_failed
 *       Fired on every failed login attempt. Always-a-failure.
 *   - \core\event\user_password_policy_failed
 *       Fired when a password change is rejected by site policy.
 *       Always-a-failure.
 *   - \core\event\webservice_function_called
 *       Fired for every WS call (success OR failure). The event's
 *       `other['exception']` field is populated on failure (Moodle sets
 *       it from the throwable when the WS dispatcher catches one). We
 *       gate on its presence so this observer only buffers the failures.
 *   - \core\event\webservice_login_failed
 *       WS-token login rejection. Always-a-failure.
 *
 * This is a deliberate superset of "PHP error" -- it covers the actual
 * user-visible operational errors a site operator wants to see in the
 * exceptions feed, which raw PHP warnings / notices in lib code generally
 * are not.
 *
 * Hot path
 * --------
 * Like the existing event observer, this code does no DB writes and no
 * network calls, and swallows every error so capture can never break a
 * host page render.
 *
 * @copyright 2026 IntelliBoard / IntelliStream
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exceptions_observer {
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
        // byte-identical on every occurrence and carried no diagnostic value,
        // while being most of the record's size on the one capture path an
        // unauthenticated caller can drive.
        self::record_event($event, 'login_failed', 'User login failed', false);
    }

    /**
     * `\core\event\user_password_policy_failed`
     *
     * @param \core\event\base $event
     */
    public static function user_password_policy_failed($event): void {
        self::record_event($event, 'password_policy_failed', 'Password rejected by policy');
    }

    /**
     * `\core\event\webservice_function_called`
     *
     * Buffered ONLY when the event carries an exception payload --
     * successful WS calls are noise for an exceptions feed.
     *
     * @param \core\event\base $event
     */
    public static function webservice_function_called($event): void {
        try {
            $data = $event->get_data();
            $other = is_array($data['other'] ?? null) ? $data['other'] : [];
        } catch (\Throwable $e) {
            return;
        }

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
    }

    /**
     * `\core\event\webservice_login_failed`
     *
     * @param \core\event\base $event
     */
    public static function webservice_login_failed($event): void {
        self::record_event($event, 'webservice_login_failed', 'Web service login failed');
    }

    /**
     * Drop the memoised datatype switch.
     *
     * Called by config_repository::save()/delete() for the same reason
     * exporter::reset_registry_cache() is: a write must be visible to a later read
     * in the same request. There is no production path today that disables the
     * datatype and then logs an exception within one request, but relying on that
     * staying true is exactly the assumption that produced the bug this method
     * exists to fix. Also the hook the smoke test uses between fixtures.
     *
     * @return void
     */
    public static function reset_datatype_cache(): void {
        self::$datatypeenabled = null;
    }

    /**
     * Whether the `exceptions` datatype is switched on.
     *
     * This observer used to check only config::enabled(), which made the
     * per-datatype switch a lie. webhook_commands::save_datatype_config() permits
     * disabling `exceptions` (it is a log datatype, so the cannot_disable_required
     * guard does not fire), writes `enabled = 0`, and get_datatype_config then
     * reports `status: 0` / `enableexport: 0` — while records carrying userid,
     * request URL and a stack trace kept flowing off-site. The usual enforcement
     * point does not cover it either: `exceptions` is not in exporter::registry(),
     * so apply_overrides_to_registry()'s unset() of a disabled datatype is a no-op
     * for this one. So the observer has to ask directly.
     *
     * Memoised per request. This runs on the failed-login path, which is reachable
     * by an unauthenticated caller, so it must not add a query per attempt. A
     * missing row counts as enabled (config_repository::is_enabled()), so a site
     * that has never touched the setting behaves exactly as before.
     *
     * @return bool
     */
    protected static function datatype_enabled(): bool {
        if (self::$datatypeenabled === null) {
            try {
                self::$datatypeenabled = (new config_repository())
                    ->is_enabled(exceptions_datatype::ENTITY);
            } catch (\Throwable $e) {
                // Fail OPEN, matching the pre-existing behaviour: a database
                // hiccup should not silently stop error capture.
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
        // Rate limit BEFORE building the record, so a throttled attempt does not
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
            // Skipped entirely when $wanttrace is false. For a failed login the
            // trace is always core's own login code path, identical every time, so
            // it told an operator nothing while being the bulk of the record — the
            // single biggest contributor to the amplification the quota above now
            // bounds.
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
                'record_type'    => exceptions_datatype::RECORD_TYPE,
                'entity'         => exceptions_datatype::ENTITY,
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
}
