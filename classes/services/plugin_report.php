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
 * Plugin config, Moodle environment and scheduled-task snapshot for the
 * IntelliBoard service's monitoring panels, plus config/cron write validation.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\services;

use local_intellistream\config;

/**
 * Read and validate the plugin's own configuration for the IntelliBoard service.
 *
 * The site has no web service token, so the IntelliBoard service asks over the
 * signed control webhook (get_plugin_config action) and this class builds the
 * answer from the plugin's own admin settings page and Moodle's task tables.
 * Nothing is hand-listed, so new settings and tasks appear automatically.
 * The shape of {@see config_snapshot} matches the "Moodle config", "Plugin config"
 * and "Cron config" panels the IntelliBoard service already shows for the legacy
 * IntelliBoard plugin.
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
            // Proof for ingest-key rotation: the fingerprint of the access key of the
            // last successful shipment (never the secret), plus when. The IntelliBoard service
            // revokes the previous key only when this fingerprint matches the new key's, which
            // proves the rotated credential is in use, even with a stale config cache.
            'ship_proof'    => [
                'accesskey_fp' => (string) (get_config(config::COMPONENT, 'last_ship_accesskey_fp') ?: ''),
                'last_ship_ok' => (int) (get_config(config::COMPONENT, 'last_ship_ok_time') ?: 0),
                // Non-zero means files reached object storage but could not be
                // removed here, so shipping is stopped. Reported alongside the
                // proof: last_ship_ok shows the new key worked, and this stops a
                // caller from reading that as "healthy".
                'undeletable'  => (int) (get_config(config::COMPONENT, 'last_undeletable_count') ?: 0),
            ],
            // The exportable entity registry keys, so the IntelliBoard service can offer
            // the full list (e.g. the targeted re-fetch picker) instead of a
            // hardcoded subset. In registry order (user, course, ... first).
            'entities'      => array_keys(\local_intellistream\exporter::registry_with_overrides()),
            // When this site's caches were last purged. Read-only — the
            // IntelliBoard service only reports it; nothing here purges anything.
            'cache_status'  => self::cache_status(),
        ];
    }

    /**
     * When this Moodle last had its caches fully purged.
     *
     * Purging caches is a required install step; this lets the IntelliBoard service
     * confirm it. Moodle fires no event for it, so this reads the marker core leaves,
     * which catches a purge by anyone through any route.
     *
     * Primary marker: `localcachedirpurged`, set to time() by purge_other_caches()
     * (lib/moodlelib.php), which only a full purge reaches. Fallback, when it is
     * missing: the newest of the four revision counters, which also store time() but
     * also change on theme/language admin actions.
     *
     * The value dates a full cache invalidation, not only a click on "Purge all
     * caches": a plugin upgrade also purges caches. 0 means never purged.
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
     * Whether a config key may be written remotely: it must be a real setting of
     * this plugin, not secret-bearing, and not locally managed.
     * Rejecting unknown keys stops arbitrary config writes; rejecting secrets
     * stops overwriting keys the snapshot only ever shows redacted; rejecting
     * locally-managed keys stops remote writes of values whose safety
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
     * both locally — the gate as true, the tree as a stub whose add() does
     * nothing — gives the populated \admin_settingpage without touching the real
     * admin tree, without running any other plugin's settings.php, and without
     * elevating the current user.
     *
     * `require`, not `require_once`: the real admin tree may have included this
     * file earlier in the same request, and a fresh copy of $settings is needed.
     * The file declares no functions or classes, so including it again is safe.
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
     * Coerce and type-check a remotely written value before it is stored.
     *
     * `is_writable()` answers which keys may be written; this answers what may be
     * stored in one. The setting's declared PARAM_* type binds only the admin
     * form, so a remote write applies it here; otherwise a non-URL could land in
     * `endpoint` or a non-numeric value in `loadgatefactor`.
     *
     * A value the type filter has to alter is not a valid value of that type, so
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
        // One webhook call can ask for the definitions several times (snapshot,
        // is_writable(), clean_for_write()); settings.php is included once per
        // request. The request cache is also cleared between PHPUnit tests.
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, config::COMPONENT, 'settingdefs');
        $cached = $cache->get('defs');
        if ($cached !== false) {
            return $cached;
        }
        $defs = [];

        // Built from this plugin's settings.php against a local $ADMIN stub, not
        // from admin_get_root(). Moodle populates the real admin tree only for a
        // holder of moodle/site:config, and the control webhook runs with no
        // session (NO_MOODLE_COOKIES); admin_get_root() would need the user elevated
        // to site admin and would run every installed plugin's settings.php.
        // Including only this plugin's file needs no elevation.
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
            // The declared PARAM_* type, where the setting has one. Only the admin
            // form applies it, so clean_for_write() applies it to webhook writes.
            $paramtype = null;
            if ($setting instanceof \admin_setting_configtext && isset($setting->paramtype)) {
                $paramtype = $setting->paramtype;
            }

            // A select has no paramtype, and clean_for_write() returns the raw value
            // unchanged for a null paramtype. The option list is the select's type,
            // so carry it and let clean_for_write() reject values outside it.
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
        $cache->set('defs', $defs);
        return $defs;
    }

    /**
     * Moodle environment block (matches the classic "Moodle config" panel).
     *
     * @return array{version:string,release:string,cronenabled:bool,moodleworkplace:bool,dbtype:string}
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
            // The timezone Moodle schedules scheduled tasks in. cron_registry() reports
            // lastruntime/nextruntime as epochs; with the zone, the IntelliBoard service
            // renders them consistently with the cron fields next to them.
            'timezone'        => (string) \core_date::get_server_timezone(),
            // The site's default language. `modules` is exported only from cron,
            // which resolves strings in this language, so it decides whether an
            // activity type name arrives as 'Foro' or 'Forum'. A language code is
            // not sensitive; it is already visible on any page of the site.
            'lang'            => (string) ($CFG->lang ?? ''),
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
