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
 * Navigation setup for the embedded IntelliBoard dashboard link.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\helpers;

use local_intellistream\config;

/**
 * Adds the dashboard link to the navigation, and optionally to the menu bar.
 *
 * The navigation tree / flat navigation node (under My Profile) is added for every
 * permitted user, via local_intellistream_extend_navigation.
 *
 * The theme menu bar entry is opt-in behind the `custommenuitem` setting, because it
 * changes site-wide navigation. It is added by appending a line to
 * $CFG->custommenuitems in memory, for the current request only, behind the same
 * capability check, so the entry stays role-aware and nothing is written to the
 * site's theme settings. This one mechanism is used on every supported release: the
 * \core\hook\navigation\primary_extend hook only fires when the theme calls
 * \core\navigation\views\primary::initialise(), and themes such as theme_classic
 * build the menu bar from $CFG->custommenuitems instead.
 *
 * An entry the administrator added by hand under Custom menu items is never removed or
 * rewritten; if one exists, this class does not add a second. The menu-bar entry is
 * not placed on administration pages (see add_menu_bar_item()).
 */
class custom_menu_helper {
    /** @var string Navigation node key for the dashboard entry. */
    private const NODE_KEY = 'intellistream_lti';

    /**
     * Add the dashboard link to the navigation, for a user who may see it.
     *
     * @param \global_navigation $nav
     * @return void
     */
    public function setup($nav) {
        global $PAGE;

        try {
            if (!self::may_see_dashboard()) {
                // Nothing to add, so touch nothing: make_inactive() below recurses to
                // the navigation root clearing active_tree_node, which must not
                // happen for a user who does not get the link.
                return;
            }

            // Menu bar first, and independently: it must not be skipped just
            // because this page or theme has no My Profile root node.
            self::add_menu_bar_item();

            $mynode = $PAGE->navigation->find('myprofile', \navigation_node::TYPE_ROOTNODE);
            if (!$mynode) {
                // Navigation find() returns false when the node is absent (some
                // pages and themes have no myprofile root).
                return;
            }
            $mynode->collapse = true;
            $mynode->make_inactive();

            $name = self::menu_label();
            $url = self::dashboard_url();
            $nav->add($name, $url);
            $icon = new \pix_icon('i/area_chart', '', 'local_intellistream');
            $node = $mynode->add($name, $url, 0, null, self::NODE_KEY, $icon);
            $node->showinflatnavigation = true;
        } catch (\Throwable $e) {
            debugging('local_intellistream: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Menu-bar entry: append the dashboard line to $CFG->custommenuitems.
     *
     * In-memory only, for this request, on every supported release. See the class
     * docblock for why this is the single path rather than a per-version fallback.
     *
     * @return void
     */
    private static function add_menu_bar_item(): void {
        global $CFG, $PAGE;

        if (!config::lti_custom_menu_item()) {
            return;
        }
        if (isset($PAGE) && $PAGE instanceof \moodle_page && strpos((string)$PAGE->pagetype, 'admin-') === 0) {
            // Never on an administration page. `custommenuitems` is a core setting,
            // and admin_setting::config_read() resolves core settings from $CFG
            // rather than the database, so this in-memory line would appear
            // pre-filled in the Custom menu items textarea under Appearance (and in
            // the admin-presets export). The next "Save changes" on that page would
            // persist it, and core would then show the link to every user, guests
            // included, with no capability check. The cost is that the menu-bar
            // entry is absent inside Site administration.
            return;
        }

        if (self::listed_in_core_menu()) {
            // Either the administrator listed the dashboard themselves, or this
            // callback already ran for this request — global_navigation_for_ajax
            // also runs the local plugin callbacks, and core reads
            // custommenuitems twice per render (desktop and mobile). Both cases
            // want the same thing: leave the value alone.
            return;
        }
        $line = self::menu_label() . '|' . self::dashboard_url()->out(false);
        // Add a newline separator only when there is something to separate from, so a
        // site without a custom menu gets no leading blank line. Anything the
        // administrator already had is left exactly as it is, trailing whitespace
        // included.
        $existing = (string)($CFG->custommenuitems ?? '');
        $CFG->custommenuitems = $existing === '' ? $line : $existing . "\n" . $line;
    }

    /**
     * Whether the theme menu already links to the dashboard.
     *
     * Matched on the page path rather than on a whole "Title|URL" line, so an
     * administrator who used their own wording is still recognised. Read-only —
     * nothing here ever rewrites the core setting.
     *
     * @return bool
     */
    private static function listed_in_core_menu(): bool {
        global $CFG;

        $path = self::dashboard_url()->out_as_local_url(false);
        foreach (explode("\n", (string)($CFG->custommenuitems ?? '')) as $line) {
            $fields = explode('|', $line);
            if (count($fields) < 2) {
                // Text-only line: a parent item with no URL of its own.
                continue;
            }
            // Compare paths, not raw strings: a substring test would match any line
            // that merely mentions the page, such as an SSO redirector like
            // "Login|/sso.php?next=/local/intellistream/lti.php".
            $candidate = (string)parse_url(trim($fields[1]), PHP_URL_PATH);
            if ($candidate !== '' && substr($candidate, -strlen($path)) === $path) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the current user may open the embedded dashboard.
     *
     * @return bool
     */
    private static function may_see_dashboard(): bool {
        return isloggedin()
            && config::lti_tool_url() !== ''
            && has_capability('local/intellistream:viewlti', \context_system::instance());
    }

    /**
     * Label for the dashboard link.
     *
     * `ltititle` is PARAM_TEXT, so it can legitimately contain "|" or a newline —
     * both of which are structural in the custommenuitems format, where they would
     * corrupt the entry or inject an extra menu line. Strip them here rather than
     * at the point of use, so the menu bar and the navigation node agree.
     *
     * @return string
     */
    private static function menu_label(): string {
        $label = str_replace(['|', "\r", "\n"], '', config::lti_title());
        // A leading run of "-" is core's child-depth prefix
        // (custom_menu::convert_text_to_menu_nodes matches /^(\-*)/), so a title
        // of "-Analytics" would re-parent the dashboard under whichever item an
        // administrator happened to list above it, and "---" collapses to an empty
        // label. `ltititle` is webhook-writable, so that input is not admin-only.
        // "#" is stripped for the same reason: core reads "###" as a divider.
        $label = trim(ltrim(trim($label), "-#"));
        if ($label === '') {
            return get_string('ltimenutitle', 'local_intellistream');
        }
        // Escape before the label reaches a navigation node: core renders
        // primary-nav text through moremenu_children.mustache with {{{text}}}, i.e.
        // unescaped. The custommenuitems path is escaped by core on its way through
        // custom_menu, so only the navigation node strictly needs this — but
        // applying it in one place keeps both surfaces showing the same string.
        // Note ltititle is webhook-writable, so this input is not admin-only.
        return format_string($label, true, ['context' => \context_system::instance()]);
    }

    /**
     * URL of the embedded dashboard page.
     *
     * @return \moodle_url
     */
    private static function dashboard_url(): \moodle_url {
        return new \moodle_url('/local/intellistream/lti.php');
    }
}
