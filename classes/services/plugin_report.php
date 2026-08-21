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
 * Plugin-config + Moodle-env + scheduled-task snapshot for the control plane's
 *
 * admin monitoring panels, plus config/cron write validation.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\services;

use local_intellistream\config;

/**
 * Read + validate the plugin's own configuration for the control-plane admin.
 *
 * PUSH parity for IntelliData's "Moodle config" / "Plugin config" / "Cron config"
 * admin panels: instead of a pull web service (a push site has no WS token), the
 * control plane asks over the signed control webhook (get_plugin_config action)
 * and this class assembles the answer live from the plugin's OWN admin settings
 * page + Moodle's task tables — nothing hand-listed, so new settings/tasks appear
 * automatically.
 *
 * The shape returned by {@see config_snapshot} is exactly what supernova-admin's
 * PluginDataFormatter::prepareMoodleData() consumes for a classic Moodle (type 1)
 * connection, so the admin UI renders identically with no admin/frontend change.
 */
class plugin_report {
    /**
     * Assemble the full monitoring snapshot.
     *
     * @return array{moodleconfig:array,pluginconfig:array,pluginversion:string,cronconfig:array}
     */
    public static function config_snapshot(): array {
        $defs = self::setting_defs();

        // Group the settings in page order under their heading titles, producing
        // pluginconfig[0] = {title, items:[ {grouptitle, items:[ field,... ]} ]}.
        $groups = [];
        $order = [];
        foreach ($defs as $d) {
            $g = $d['grouptitle'];
            if (!isset($groups[$g])) {
                $groups[$g] = [];
                $order[] = $g;
            }
            $raw = get_config(config::COMPONENT, $d['name']);
            if ($raw === false || $raw === null) {
                $raw = '';
            }
            if ($d['secret']) {
                // Never let a stored secret leave the site — show presence only.
                $value = ($raw !== '' ) ? '••••••' : '';
            } else if ($d['subtype'] === 'checkbox') {
                $value = (int) $raw;
            } else {
                $value = is_scalar($raw) ? (string) $raw : '';
            }
            $groups[$g][] = [
                'name'    => $d['name'],
                'title'   => $d['title'],
                'subtype' => $d['subtype'],
                'value'   => $value,
            ];
        }

        $items = [];
        foreach ($order as $g) {
            $items[] = ['grouptitle' => $g, 'items' => $groups[$g]];
        }
        $pluginconfig = [];
        if ($items) {
            $pluginconfig[] = [
                'title' => get_string('pluginname', config::COMPONENT),
                'items' => $items,
            ];
        }

        return [
            'moodleconfig'  => self::moodle_config(),
            'pluginconfig'  => $pluginconfig,
            'pluginversion' => config::plugin_version(),
            'cronconfig'    => self::cron_registry(),
            // Ship-proof for the control-plane ingest-key revoke gate: the fingerprint of
            // the access key the shipper LAST SUCCESSFULLY shipped with (never the secret), plus when.
            // The control plane revokes the previous key only once this fingerprint matches the new
            // key's — proof the rotated credential is actually in use, immune to a stale config cache.
            'ship_proof'    => [
                'accesskey_fp' => (string) (get_config(config::COMPONENT, 'last_ship_accesskey_fp') ?: ''),
                'last_ship_ok' => (int) (get_config(config::COMPONENT, 'last_ship_ok_time') ?: 0),
            ],
            // The exportable entity registry keys, so the control plane can offer
            // the full list (e.g. the targeted re-fetch picker) instead of a
            // hardcoded subset. In registry order (user, course, ... first).
            'entities'      => array_keys(\local_intellistream\exporter::registry_with_overrides()),
            // When this site's caches were last purged. Read-only — the
            // control plane only reports it; nothing here purges anything.
            'cache_status'  => self::cache_status(),
        ];
    }

    /**
     * When this Moodle last had its caches fully purged.
     *
     * Purging the cache is a required step when an IT department or partner installs
     * the plugin, and until now there was no way to confirm it happened. Moodle fires
     * no event for it, so we read the marker core leaves behind instead — which means
     * this works retroactively and catches a purge performed by ANYONE through ANY
     * route (the web UI, `admin/cli/purge_caches.php`, or another plugin).
     *
     * Primary marker is `localcachedirpurged`: core sets it to time() in
     * purge_other_caches() (lib/moodlelib.php), which is only reached on a FULL purge —
     * a theme-only or lang-only purge never touches it.
     *
     * Fallback is the newest of the four revision counters. increment_revision_number()
     * (lib/datalib.php) also stores time(), so they date a purge too, but they
     * additionally bump on theme/language admin actions — hence a backstop only, used
     * when the primary marker is missing.
     *
     * Note the value dates a full cache invalidation, not specifically a human clicking
     * "Purge all caches": a completed plugin upgrade purges caches on its own
     * (upgrade_noncore() in lib/upgradelib.php). 0 means never purged.
     *
     * @return array{last_purge_at:int}
     */
    public static function cache_status(): array {
        $lastpurge = (int) (get_config('core', 'localcachedirpurged') ?: 0);

        if ($lastpurge <= 0) {
            foreach (['jsrev', 'themerev', 'langrev', 'templaterev'] as $rev) {
                $value = (int) (get_config('core', $rev) ?: 0);
                if ($value > $lastpurge) {
                    $lastpurge = $value;
                }
            }
        }

        // A negative revision (-1) is core's "development mode" sentinel, not a date.
        return ['last_purge_at' => max(0, $lastpurge)];
    }

    /**
     * Whether a config key may be written from the control plane: it must be a
     * real setting of THIS plugin, NOT secret-bearing, and NOT locally managed.
     * Rejecting unknown keys stops arbitrary config writes; rejecting secrets
     * stops overwriting keys the snapshot only ever shows redacted; rejecting
     * locally-managed keys stops the control plane setting values whose safety
     * depends on a constraint only the admin UI applies (see
     * {@see \local_intellistream\admin\locally_managed}).
     *
     * @param string $name
     * @return bool
     */
    public static function is_writable(string $name): bool {
        foreach (self::setting_defs() as $d) {
            if ($d['name'] === $name) {
                return !$d['secret'] && !$d['localonly'];
            }
        }
        return false;
    }

    /**
     * Build this plugin's own admin settings page, in isolation.
     *
     * settings.php expects two things from its caller: `$hassiteconfig`, the
     * capability gate, and `$ADMIN`, the tree it registers itself into. Supplying
     * both locally — the gate as true, the tree as a stub that swallows add() —
     * gives us the populated \admin_settingpage without touching the real admin
     * tree, without executing any other plugin's settings.php, and without
     * elevating the current user. The stub's add() is deliberately a no-op: we
     * want the page object that settings.php builds, not the registration.
     *
     * `require`, not `require_once`: the real admin tree may legitimately have
     * included this file earlier in the same request, and we still need our own
     * copy of $settings. The file declares no functions or classes, so
     * re-including it is safe.
     *
     * @return \admin_settingpage|null null if the file did not produce a page
     */
    private static function build_own_settings_page(): ?\admin_settingpage {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $hassiteconfig = true;
        $settings = null;
        $ADMIN = new class {
            /**
             * Swallow node registrations from the included settings.php.
             *
             * @param string $parent
             * @param mixed $node
             * @return void
             */
            public function add($parent, $node): void {
            }
        };
        try {
            require(__DIR__ . '/../../settings.php');
        } catch (\Throwable $e) {
            return null;
        }
        return ($settings instanceof \admin_settingpage) ? $settings : null;
    }

    /**
     * Coerce and type-check a control-plane value before it is written.
     *
     * `is_writable()` answers WHICH keys may be written; this answers WHAT may be
     * stored in one. The two are separate concerns and were previously only half
     * covered: set_plugin_config wrote `(string)$value` straight through, so the
     * setting's declared PARAM_* type — which binds only the admin form — never
     * applied. A non-URL could land in `endpoint`, a non-numeric in
     * `loadgatefactor`.
     *
     * A value the type filter has to ALTER is not a valid value of that type, so
     * it is rejected rather than silently stored in its mutated form: a caller
     * that sent something wrong should be told, not quietly corrected.
     *
     * @param string $name  setting name, already checked by is_writable()
     * @param mixed  $value raw scalar from the webhook payload
     * @return string|null the value to store, or null if it is not valid for
     *         this setting's declared type (or the setting is unknown)
     */
    public static function clean_for_write(string $name, $value): ?string {
        foreach (self::setting_defs() as $d) {
            if ($d['name'] !== $name) {
                continue;
            }
            if ($d['subtype'] === 'checkbox') {
                // Checkboxes store '0'/'1'; accept any truthy scalar spelling.
                return (string) (int) (bool) (int) $value;
            }
            $raw = (string) $value;
            if ($d['subtype'] === 'select') {
                // The option list is this setting's type. Reject anything outside
                // it (returning null makes the caller answer 422) rather than
                // storing a value the select could never have produced.
                $choices = is_array($d['choices'] ?? null) ? $d['choices'] : [];
                return in_array($raw, array_map('strval', $choices), true) ? $raw : null;
            }
            if ($d['paramtype'] === null || $d['paramtype'] === PARAM_RAW) {
                return $raw;
            }
            $clean = clean_param($raw, $d['paramtype']);
            return ((string) $clean === $raw) ? $raw : null;
        }
        return null;
    }

    /**
     * Introspect the plugin's admin settings page into a flat, ordered list of
     * field definitions. Derived entirely from settings.php — no field is named
     * here — so any setting added later is picked up automatically.
     *
     * @return array<int,array{name:string,title:string,subtype:string,paramtype:?string,choices:?string[],secret:bool,localonly:bool,grouptitle:string}>
     */
    private static function setting_defs(): array {
        $defs = [];

        // Built from THIS plugin's settings.php against a local $ADMIN stub — not
        // from admin_get_root(). Every plugin's settings.php is wrapped in
        // `if ($hassiteconfig)`, so Moodle only populates the real admin tree for a
        // holder of moodle/site:config; the control webhook authenticates by HMAC
        // and runs with no session (NO_MOODLE_COOKIES), so this used to elevate to
        // the site admin via \core\session\manager::set_user() just to read a
        // config list.
        //
        // That is safe in itself, but its SCOPE is not: admin_get_root(true, true)
        // executes EVERY installed plugin's settings.php as a site admin from a
        // request with no logged-in user, which is a very broad side-effect surface
        // for a config read, and any future caller of this class would inherit the
        // elevation with no obvious signal. Including only our own file removes the
        // elevation entirely rather than justifying it.
        $page = self::build_own_settings_page();
        if (!($page instanceof \admin_settingpage)) {
            return $defs;
        }

        // Settings before the first heading fall under the plugin name.
        $group = self::plain(get_string('pluginname', config::COMPONENT));
        foreach ($page->settings as $setting) {
            if ($setting instanceof \admin_setting_heading) {
                $title = self::plain((string) $setting->visiblename);
                if ($title !== '') {
                    $group = $title;
                }
                continue;
            }
            // Display-only rows (e.g. the status-page link) are not settings.
            if ($setting instanceof \admin_setting_description) {
                continue;
            }
            $name = (string) $setting->name;
            if ($name === '') {
                continue;
            }
            // The declared PARAM_* type, where the setting has one. Only the form
            // applies it, so a webhook write bypassed it entirely until
            // clean_for_write() started consulting this.
            $paramtype = null;
            if ($setting instanceof \admin_setting_configtext && isset($setting->paramtype)) {
                $paramtype = $setting->paramtype;
            }

            // A select is neither a checkbox nor a configtext, so it used to fall
            // through as subtype 'text' with paramtype null — and clean_for_write()
            // returns the raw value unchanged for a null paramtype. A webhook write
            // to ltipagelayout therefore stored an arbitrary string with no check
            // against the option list. Harmless today, because core's
            // theme_config::layout_info_for_page() falls back to the standard
            // layout for an unknown key, but the gap would bite the moment a select
            // with a load-bearing value is added and not marked localonly. The
            // option list IS the type here, so carry it.
            $subtype = 'text';
            $choices = null;
            if ($setting instanceof \admin_setting_configcheckbox) {
                $subtype = 'checkbox';
            } else if ($setting instanceof \admin_setting_configselect) {
                $subtype = 'select';
                // Calling load_choices() populates ->choices on the subclasses that
                // build their options lazily; a plain configselect already has them.
                if (method_exists($setting, 'load_choices')) {
                    $setting->load_choices();
                }
                $choices = is_array($setting->choices) ? array_keys($setting->choices) : [];
            }

            $defs[] = [
                'name'       => $name,
                'title'      => self::plain((string) $setting->visiblename),
                'subtype'    => $subtype,
                'paramtype'  => $paramtype,
                'choices'    => $choices,
                'secret'     => ($setting instanceof \admin_setting_configpasswordunmask),
                'localonly'  => ($setting instanceof \local_intellistream\admin\locally_managed),
                'grouptitle' => $group,
            ];
        }
        return $defs;
    }

    /**
     * Moodle environment block (matches the classic "Moodle config" panel).
     *
     * @return array{version:string,release:string,cronenabled:bool,moodleworkplace:bool,dbtype:string,totaraversion:string}
     */
    private static function moodle_config(): array {
        global $CFG;

        $cronenabled = true;
        $cron = get_config('core', 'cron_enabled');
        if ($cron !== false && $cron !== null) {
            $cronenabled = (bool) (int) $cron;
        }

        // Best-effort Workplace detection; no hard dependency on the component.
        $workplace = false;
        try {
            $tools = \core_component::get_plugin_list('tool');
            $workplace = array_key_exists('wp', $tools);
        } catch (\Throwable $e) {
            $workplace = false;
        }

        return [
            'version'         => (string) ($CFG->version ?? ''),
            'release'         => (string) ($CFG->release ?? ''),
            'cronenabled'     => $cronenabled,
            'moodleworkplace' => $workplace,
            'dbtype'          => (string) ($CFG->dbtype ?? ''),
            'totaraversion'   => isset($CFG->totara_version) ? (string) $CFG->totara_version : '',
            // The timezone Moodle schedules scheduled tasks in. cron_registry() reports
            // lastruntime/nextruntime as epochs, and without this the control plane can
            // only render them in ITS timezone — showing e.g. "hour 3" beside a next run
            // of 02:00 on a UTC+1 site. Sending the zone lets it render them consistently
            // with the cron fields right next to them.
            'timezone'        => (string) \core_date::get_server_timezone(),
        ];
    }

    /**
     * The plugin's scheduled-task registry, straight from Moodle's own
     * mdl_task_scheduled — the live schedule + last/next run + disabled state the
     * "Cron config" panel shows, with a per-task Edit that save_task applies.
     *
     * @return array<int,array{classname:string,minute:string,hour:string,day:string,month:string,dayofweek:string,disabled:int,faildelay:int,lastruntime:int,nextruntime:int}>
     */
    public static function cron_registry(): array {
        global $DB;

        $out = [];
        $rows = $DB->get_records('task_scheduled', ['component' => config::COMPONENT], 'classname ASC');
        foreach ($rows as $r) {
            $out[] = [
                'classname'   => (string) $r->classname,
                'minute'      => (string) $r->minute,
                'hour'        => (string) $r->hour,
                'day'         => (string) $r->day,
                'month'       => (string) $r->month,
                'dayofweek'   => (string) $r->dayofweek,
                'disabled'    => (int) $r->disabled,
                'faildelay'   => (int) ($r->faildelay ?? 0),
                'lastruntime' => (int) ($r->lastruntime ?? 0),
                'nextruntime' => (int) ($r->nextruntime ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Flatten a possibly-HTML setting label to a single plain-text line.
     *
     * @param string $s
     * @return string
     */
    private static function plain(string $s): string {
        return trim(preg_replace('/\s+/', ' ', strip_tags($s)));
    }
}
