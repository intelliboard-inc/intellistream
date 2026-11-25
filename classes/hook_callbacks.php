<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
// Hook callbacks for local_intellistream.

namespace local_intellistream;

/**
 * Hook callbacks, plus the shared implementation behind them.
 *
 * Moodle 4.3 replaced the `before_footer` lib.php callback with the
 * `\core\hook\output\before_footer_html_generation` hook. Core's hook class
 * carries `#[\core\attribute\hook\replaces_callbacks('before_footer')]`, and its
 * process_legacy_callbacks() passes `migratedtohook: true`, so on any non-PHPUnit
 * 4.3+ site a plugin that still relies on the legacy callback triggers a
 * DEBUG_DEVELOPER warning on EVERY page render:
 *
 *   "Callback before_footer in local_intellistream component should be migrated
 *    to new hook callback"
 *
 * Both entry points are kept on purpose. version.php declares
 * `$plugin->requires = 2022112800` (Moodle 4.1), which predates the hook system
 * entirely — on 4.1/4.2 `db/hooks.php` is simply never read and the lib.php
 * callback is the only thing that works. On 4.3+ core ignores the legacy callback
 * precisely because the hook is registered, so exactly one of them runs on any
 * given site and the dwell module is never injected twice.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Hook callback (Moodle 4.3+). Registered in db/hooks.php.
     *
     * The parameter is type-hinted against a class that does not exist on 4.1/4.2,
     * which is safe: PHP resolves a parameter type only when the method is
     * actually invoked, and on those versions nothing ever invokes it.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     * @return void
     */
    public static function before_footer_html_generation(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        self::inject_dwell();
    }

    /**
     * Inject the page-dwell capture AMD module.
     *
     * Runs just before the footer, after $PAGE is fully populated (so the context
     * is known). Computes a coarse page descriptor and hands it to the dwell.js
     * AMD module, which times the visit and, on pagehide / tab-hide, beacons the
     * elapsed time to local/intellistream/dwell.php, where it becomes a
     * `page_dwell` buffer record.
     *
     * Gated on config::dwell_enabled() (itself gated on the master switch). Any
     * failure is swallowed: capture must never break a page render.
     *
     * @return void
     */
    public static function inject_dwell(): void {
        global $PAGE;

        try {
            if (!config::dwell_enabled()) {
                return;
            }

            // Only meaningful for a logged-in human session. Skip AJAX, CLI and
            // web-service requests, and the not-logged-in / guest cases.
            if (CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER) {
                return;
            }
            if (!isloggedin() || isguestuser()) {
                return;
            }
            if (!isset($PAGE) || !($PAGE instanceof \moodle_page)) {
                return;
            }

            $context = $PAGE->context;
            if (!$context) {
                return;
            }

            // Relative URL of the current page (path + query only — no host).
            $url = $PAGE->has_set_url() ? $PAGE->url : new \moodle_url('/');
            $relurl = $url->out_as_local_url(false);

            $params = [
                'endpoint'          => (new \moodle_url('/local/intellistream/dwell.php'))->out(false),
                'contextid'         => (int)$context->id,
                'contextlevel'      => (int)$context->contextlevel,
                'contextinstanceid' => (int)$context->instanceid,
                'courseid'          => isset($PAGE->course->id) ? (int)$PAGE->course->id : null,
                'page'              => self::page_kind($context),
                'url'               => $relurl,
                'sesskey'           => sesskey(),
                // P3b: media-segment tracking toggle + bucket size.
                'trackmedia'           => config::trackmedia_enabled() ? 1 : 0,
                'trackmediabucketsec'  => config::trackmedia_bucket_sec(),
            ];

            $PAGE->requires->js_call_amd('local_intellistream/dwell', 'init', [$params]);
        } catch (\Throwable $e) {
            // Capture must never break the host page render.
            return;
        }
    }

    /**
     * Coarse page kind derived from the context level.
     *
     * Keeps the dwell record's `page` field to a small, stable vocabulary so
     * downstream aggregation does not have to parse URLs.
     *
     * @param \context $context
     * @return string One of course|module|user|site|other.
     */
    public static function page_kind(\context $context): string {
        switch ($context->contextlevel) {
            case CONTEXT_MODULE:
                return 'module';
            case CONTEXT_COURSE:
                return 'course';
            case CONTEXT_USER:
                return 'user';
            case CONTEXT_SYSTEM:
            case CONTEXT_COURSECAT:
                return 'site';
            default:
                return 'other';
        }
    }
}
