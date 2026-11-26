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
 * Library callbacks for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Footer callback: inject the page-dwell capture AMD module.
 *
 * LEGACY PATH, kept for releases before Moodle 4.4 — those have no footer hook (and
 * before 4.3 no hook system at all), so this is the only injection point that
 * works. From 4.4 core ignores this callback because the plugin registers
 * \core\hook\output\before_footer_html_generation instead, so the two never both
 * run. Both delegate to the same implementation; see
 * \local_intellistream\hook_callbacks.
 *
 * @return void
 */
function local_intellistream_before_footer() {
    \local_intellistream\hook_callbacks::inject_dwell();
}

/**
 * Top-of-body callback: write the page element that carries the page URL.
 *
 * Legacy path for releases without the
 * \core\hook\output\before_standard_top_of_body_html_generation hook (added in
 * Moodle 4.4). From 4.4 core ignores this callback because the plugin registers that
 * hook, so the two never both run. See \local_intellistream\hook_callbacks.
 *
 * @return string
 */
function local_intellistream_before_standard_top_of_body_html() {
    return \local_intellistream\hook_callbacks::dwell_element();
}

/**
 * Add the IntelliBoard LTI dashboard node to the navigation (under My Profile).
 *
 * The node is shown only when the LTI tool URL is configured and the current
 * user holds local/intellistream:viewlti (see custom_menu_helper::setup). Any
 * failure is swallowed: navigation must never break a page render.
 *
 * This is also where the optional menu-bar entry is added, on every supported
 * release. Unlike the footer callback above, core keeps calling this one on 4.4+ as
 * well, which is what makes it a usable single home for both surfaces.
 *
 * @param \global_navigation $nav
 * @return void
 */
function local_intellistream_extend_navigation(\global_navigation $nav) {
    try {
        (new \local_intellistream\helpers\custom_menu_helper())->setup($nav);
    } catch (\Throwable $e) {
        return;
    }
}

/**
 * FontAwesome icon map for the plugin's pix icons.
 *
 * @return string[]
 */
function local_intellistream_get_fontawesome_icon_map() {
    return [
        'local_intellistream:i/area_chart' => 'fa-area-chart',
    ];
}

/**
 * Send the JSON response of the page-dwell beacon (dwell.php) and stop.
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

/**
 * Clean the page URL of a dwell beacon.
 *
 * The browser sends moodle_url::out_as_local_url(), a root-relative URL. Anything
 * else becomes ''. The value is cleaned first and then cut to 2048 bytes: a cut
 * first could split a %XX escape, and the cleaned value is plain ASCII.
 *
 * @param string $url
 * @return string
 */
function local_intellistream_dwell_clean_url(string $url): string {
    $url = clean_param($url, PARAM_LOCALURL);
    return strlen($url) > 2048 ? substr($url, 0, 2048) : $url;
}

/**
 * Check the started_at value of a dwell beacon.
 *
 * The browser sends toISOString(), a whole ISO-8601 timestamp in UTC. A timestamp
 * with an offset is also accepted. Anything else returns null, and the caller uses
 * the server time.
 *
 * @param string $startedat
 * @return string|null
 */
function local_intellistream_dwell_clean_started_at(string $startedat): ?string {
    $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})\z/';
    return preg_match($pattern, $startedat) ? $startedat : null;
}

/**
 * Clean the media id of a media_segment beacon: the element id or the media URL.
 *
 * Markup is removed, and the value is cut to at most 255 bytes without splitting
 * a character.
 *
 * @param string $mediaid
 * @return string
 */
function local_intellistream_dwell_clean_media_id(string $mediaid): string {
    return core_text::str_max_bytes(clean_param($mediaid, PARAM_NOTAGS), 255);
}
