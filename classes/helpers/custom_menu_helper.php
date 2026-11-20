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
 * Two surfaces, one mechanism each:
 *
 *  - The **navigation tree / flat navigation** node (under My Profile) is added
 *    unconditionally for a permitted user, via local_intellistream_extend_navigation.
 *
 *  - The **theme menu bar** entry is opt-in behind the `custommenuitem` setting,
 *    because it changes site-wide navigation chrome. It is placed by appending to
 *    $CFG->custommenuitems, which core reads when it builds the menu. The append is
 *    IN-MEMORY, for the current request only, and happens behind the same capability
 *    check, which is what keeps the entry role-aware: set_config() is never called on
 *    custommenuitems, so nothing is written to the site's theme settings and a user
 *    who may not open the dashboard never has the line added on their request.
 *
 * One mechanism on every supported release, deliberately. An earlier version placed
 * the entry through \core\hook\navigation\primary_extend on 4.3+ and used the $CFG
 * append only on 4.1/4.2. That hook is dispatched from
 * \core\navigation\views\primary::initialise(), which a theme is free never to
 * call: core still supports building the menu bar from $CFG->custommenuitems via
 * $OUTPUT->custom_menu() in 4.5, and theme_classic does exactly that. On such a site
 * the hook never fired and the $CFG path had already been skipped by its version
 * test, so the setting placed the entry NOWHERE. Reported from the field on a 4.4
 * site running Classic, where the only visible effect of switching the setting on was
 * a developer-debug notice.
 *
 * The version test could not be repaired in place: whether primary::initialise() will
 * run for this request is not knowable at extend_navigation() time, which is when the
 * append has to happen. Since the $CFG path works on every release from 3.9 to 4.5
 * and under both theme styles, it is now the only path, and the primary_extend
 * registration is gone with it. The cost is stated rather than hidden: the per-render
 * core-config mutation applies on all releases, where it used to apply on 4.1/4.2
 * only.
 *
 * A site that would rather manage the entry itself can still add it by hand under
 * Appearance > Advanced theme settings > Custom menu items — that persists, and is
 * visible to every user regardless of role. Nothing here removes or rewrites such
 * an entry: an earlier version stripped a matching line back out when the toggle
 * was off, which silently broke exactly that arrangement. If such an entry already
 * exists, this class defers to it rather than adding a second.
 *
 * Where the menu-bar entry is deliberately NOT placed, even with the setting on:
 * administration pages (`admin-*` pagetypes) — see add_menu_bar_item() for why that
 * one is not negotiable.
 *
 * The navigation-tree node is unaffected by all of this and by the setting.
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
                // Nothing to add, so touch nothing. The two navigation mutations
                // below used to run BEFORE this test, on every page render for
                // every user, whether or not the dashboard link was configured or
                // permitted. extend_navigation() is called from
                // global_navigation::initialise(), and make_inactive() recurses to
                // the navigation root clearing active_tree_node — so on a profile
                // page this plugin de-highlighted the whole active branch of
                // core's navigation while its own feature was switched off.
                return;
            }

            // Menu bar first, and independently: it must not be skipped just
            // because this page or theme has no My Profile root node.
            self::add_menu_bar_item();

            $mynode = $PAGE->navigation->find('myprofile', \navigation_node::TYPE_ROOTNODE);
            if (!$mynode) {
                // Navigation find() returns false when the node is absent (some
                // pages and themes have no myprofile root), and it was previously
                // dereferenced blind.
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
            // NEVER on an administration page. `custommenuitems` is a CORE setting,
            // and admin_setting::config_read() resolves core settings straight out
            // of $CFG rather than the database, so this in-memory line would appear
            // pre-filled in the Custom menu items textarea under Appearance. The
            // next "Save changes" on that page — for any unrelated reason — would
            // persist it, at which point core shows the link to EVERY user
            // including guests, with no capability check and no way for this
            // plugin to take it back. Measured on 3.9.24: the textarea rendered
            // the injected line while the stored value was still empty. The same
            // read path feeds the admin-presets export.
            //
            // The cost is that the menu-bar entry is absent while an administrator
            // is inside Site administration. That is the correct trade: a missing
            // shortcut on admin pages against silently rewriting a site's theme
            // configuration.
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
        // Only separate from something. Unconditionally prefixing the newline left a
        // leading blank line whenever the site had no custom menu of its own, which
        // is the common case. Core skips empty lines
        // (custom_menu::convert_text_to_menu_nodes() trims and continues), so this
        // was cosmetic rather than broken — but there is no reason to hand core a
        // value it has to clean up. Anything the administrator already had is left
        // exactly as it is, trailing whitespace included.
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
            // Compare PATHS, not raw strings. A substring test on the whole blob
            // matched any line that merely mentioned the page — an SSO redirector
            // such as "Login|/sso.php?next=/local/intellistream/lti.php" would
            // have suppressed the entry site-wide with nothing to show why.
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
