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
 * Higher-level config service: merges admin overrides into the static registry.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\services;

use local_intellistream\exporter;
use local_intellistream\repositories\config_repository;

/**
 * Applies admin-supplied overrides on top of the built-in exporter registry.
 *
 * The built-in registry ({@see \local_intellistream\exporter::registry()})
 * defines table and columns for every built-in entity. This service lets an
 * admin disable an entity, override the SELECT column list of a built-in
 * entity, or add a new entity backed by a Moodle table outside the registry.
 *
 * Callers (the exporter, the scheduled tasks) should call
 * {@see \local_intellistream\exporter::registry_with_overrides()} rather than
 * `exporter::registry()` directly so these overrides take effect.
 */
class config_service {
    /** @var config_repository */
    protected $repo;

    /**
     * Take the override repository, or construct the default one.
     *
     * @param config_repository|null $repo Optional injected repository.
     */
    public function __construct(?config_repository $repo = null) {
        $this->repo = $repo ?: new config_repository();
    }

    /**
     * Merge admin overrides into the static exporter registry.
     *
     * Rules, in order, per datatype row found in `local_intellistream_config`:
     *   1. enabled = 0 -> drop the entry from the registry entirely.
     *   2. On a built-in registry row: an explicit custom_columns override
     *      replaces `columns`. Otherwise a row registered by discovery
     *      (`discovered=1`) exports the whole row (`*`) when the administrator has
     *      turned on `dynamicdiscoveryexport` (off by default).
     *   3. custom_table set and no built-in entry for this datatype -> add a new
     *      entry (`columns` falls back to `*`). A discovered row becomes an entry
     *      only with `dynamicdiscoveryexport` on; a table added by hand always does.
     *
     * Then, with `informfullrow` on (off by default), every built-in entity that
     * still has its curated column list exports the whole row
     * ({@see widen_to_full_rows()}). The input array is not mutated.
     *
     * @param array $registry Output of {@see exporter::registry()}.
     * @return array Effective registry with overrides applied.
     */
    public function apply_overrides_to_registry(array $registry): array {
        $rows = $this->repo->get_all();
        if (empty($rows)) {
            return self::widen_to_full_rows($registry, $registry, []);
        }

        $effective = $registry;

        foreach ($rows as $datatype => $rec) {
            $enabled = (int)($rec->enabled ?? 1) !== 0;
            $customcolumns = self::normalise_columns($rec->custom_columns ?? null);
            $customtable = !empty($rec->custom_table) ? trim((string)$rec->custom_table) : '';

            // 1) Disable.
            if (!$enabled) {
                unset($effective[$datatype]);
                continue;
            }

            if (isset($effective[$datatype])) {
                // 2) Existing (built-in) registry entry.
                if ($customcolumns !== null && $customcolumns !== '') {
                    // Explicit admin column override — but every token is
                    // validated against the live table schema first
                    // (SQL-injection guard). If nothing in the
                    // override is a real column, keep the built-in's curated
                    // columns rather than falling back to '*' (which would
                    // defeat the exporter's deliberate secret-column exclusion).
                    $safe = self::whitelist_columns($effective[$datatype]['table'], $customcolumns);
                    if ($safe !== null) {
                        $effective[$datatype]['columns'] = $safe;
                    }
                } else if (
                    !empty($rec->discovered) && self::dynamic_discovery_export_enabled()
                        && self::may_widen_to_whole_row($effective[$datatype]['table'] ?? '')
                ) {
                    // Discovered table that is also a curated built-in (e.g. a
                    // local_intellicart_* table): only when the administrator has
                    // turned on dynamicdiscoveryexport (off by default) is the whole
                    // row exported, so its dynamic-table copy has every live column.
                    // The row count is unchanged. With the setting off the entry
                    // keeps its curated columns and no extra data leaves the site.
                    $effective[$datatype]['columns'] = '*';
                }
                continue;
            }

            // 3) Brand-new admin-supplied entity. The backing table name is
            // admin free-text that lands as the {table} target of
            // $DB->get_recordset_select(), so it must be a real table before
            // it is trusted, and its columns are whitelisted.
            if (
                $customtable !== '' && self::table_is_real($customtable)
                    && !self::is_reserved($datatype, $customtable)
            ) {
                // A row auto-registered by discovery (discovered=1) only becomes a
                // live export entity once the administrator has opted whole-row
                // discovery export in; until then its candidate row stands but no
                // row data ships off-site. A table an admin added by hand
                // (discovered=0) is an explicit choice and is unaffected.
                if (!empty($rec->discovered) && !self::dynamic_discovery_export_enabled()) {
                    continue;
                }
                $safe = ($customcolumns !== null && $customcolumns !== '')
                    ? self::whitelist_columns($customtable, $customcolumns)
                    : null;
                $effective[$datatype] = [
                    'table'   => $customtable,
                    'columns' => $safe ?? '*',
                ];
            }
        }

        return self::widen_to_full_rows($effective, $registry, $rows);
    }

    /**
     * With `informfullrow` on, export the whole row of every built-in entity that
     * still has its curated column list.
     *
     * A custom InForm report can select any column, not only the curated ones.
     * Credential columns stay out: exporter::strip_forbidden_columns() expands `*`
     * to the live columns minus the forbidden ones. Left alone: entities with
     * admin columns, entities already on `*`, derived entities, the messaging
     * entities (under `exportmessagebodies`) and `local_` plugin tables (under
     * `dynamicdiscoveryexport`).
     *
     * @param array $effective Registry after the per-row overrides.
     * @param array $builtin The built-in registry, before overrides.
     * @param array $rows Datatype config rows, keyed by datatype.
     * @return array
     */
    private static function widen_to_full_rows(array $effective, array $builtin, array $rows): array {
        if (!self::inform_full_row_enabled()) {
            return $effective;
        }
        foreach ($effective as $datatype => $def) {
            $custom = self::normalise_columns($rows[$datatype]->custom_columns ?? null);
            if (
                !isset($builtin[$datatype])
                    || !empty($def['derived'])
                    || ($def['columns'] ?? '*') === '*'
                    || ($custom !== null && $custom !== '')
                    || in_array($datatype, exporter::MESSAGE_ENTITIES, true)
                    || self::may_widen_to_whole_row($def['table'] ?? '')
            ) {
                continue;
            }
            $effective[$datatype]['columns'] = '*';
        }
        return $effective;
    }

    /**
     * Whether whole-row export of the built-in entities (`informfullrow`) is on.
     *
     * Off by default. Locally managed, so the control webhook cannot switch it on:
     * sending every column of the site's tables off-site is the site administrator's
     * decision.
     *
     * @return bool
     */
    public static function inform_full_row_enabled(): bool {
        return (bool)(int)get_config('local_intellistream', 'informfullrow');
    }

    /**
     * Turn the admin's free-form column list into a single comma-separated
     * string that the exporter can hand to $DB->get_recordset_select().
     *
     * Accepts newline-separated, comma-separated, or whitespace-separated
     * input. Empty entries are dropped. Returns null if there is nothing usable
     * so callers can detect "no override" cleanly.
     *
     * @param string|null $raw
     * @return string|null
     */
    public static function normalise_columns(?string $raw): ?string {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $parts = preg_split('/[\s,]+/', $raw);
        $clean = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $clean[] = $p;
            }
        }
        if (!$clean) {
            return null;
        }
        return implode(', ', $clean);
    }

    /**
     * Intersect a declared column list with the live schema of $table.
     *
     * Used by whitelist_columns() (admin free text spliced into a SELECT) and by
     * exporter::resolve_entity_columns(), which also reports the dropped names as
     * schema drift. An empty `missing` with null columns means "table unreadable"
     * (keep the declared list); a full `missing` means "no token is a real column".
     * $DB->get_columns() uses Moodle's `databasemeta` cache; a stale entry (e.g.
     * after a core upgrade without purge_caches.php) can report a live column missing.
     *
     * @param string $table Unprefixed Moodle table name.
     * @param string $columns Declared/normalised list ('*' passes through).
     * @return array{columns: ?string, missing: string[]} columns: safe ', '-joined
     *         canonical list, '*', or null when the table could not be
     *         introspected or no token matched. missing: lowercased declared
     *         tokens absent from the live table; empty only when introspection
     *         failed.
     */
    public static function intersect_columns(string $table, string $columns): array {
        global $DB;

        $columns = trim($columns);
        if ($columns === '') {
            return ['columns' => null, 'missing' => []];
        }
        if ($columns === '*') {
            return ['columns' => '*', 'missing' => []];
        }

        try {
            // Keyed by lowercase column name; values are database_column_info.
            $valid = array_change_key_case($DB->get_columns($table), CASE_LOWER);
        } catch (\Throwable $e) {
            // Introspection failed -> nothing to compare against.
            return ['columns' => null, 'missing' => []];
        }

        // An absent table does not throw: get_columns() returns an empty array.
        // Read naively, every declared column of an uninstalled optional plugin
        // (attendance, customcert, questionnaire...) would be reported missing. A
        // real table always has at least one column, so empty means absent or
        // unreadable — the same "cannot compare" case as a throw.
        if (!$valid) {
            return ['columns' => null, 'missing' => []];
        }

        $out = [];
        $missing = [];
        foreach (preg_split('/[\s,]+/', $columns) as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            $key = strtolower($tok);
            if (isset($valid[$key])) {
                // Emit the schema's canonical name, never the raw token.
                $out[$key] = $valid[$key]->name;
            } else {
                $missing[$key] = true;
            }
        }

        return [
            'columns' => $out ? implode(', ', $out) : null,
            'missing' => array_keys($missing),
        ];
    }

    /**
     * Strict identifier whitelist for an admin column override.
     *
     * The override string is admin free-text (a PARAM_RAW textarea) that is
     * handed verbatim to $DB->get_recordset_select()/get_records() as the
     * $fields/SELECT list, which Moodle does not parameterise or escape. Only
     * exact column names of $table (from the live schema) are kept, as canonical
     * names; anything else, including comment-obfuscated sub-select payloads, is
     * dropped. The dropped tokens are not reported, so attacker-controlled text
     * never reaches a diagnostic that is sent off-site.
     *
     * @param string $table  Unprefixed Moodle table name.
     * @param string $columns Raw/normalised override string ('*' passes through).
     * @return string|null Safe comma-separated column list, '*', or null when
     *         nothing usable remains (caller keeps the curated default).
     */
    protected static function whitelist_columns(string $table, string $columns): ?string {
        return self::intersect_columns($table, $columns)['columns'];
    }

    /**
     * Whether a discovered row may widen a curated registry entry to whole-row
     * ('*') export.
     *
     * Discovery registers under the bare table name, which is also the registry
     * key, so a prefix of `user` would replace core `user`'s password-free column
     * list with '*'. No core table is named `local_*`, so requiring that prefix
     * limits widening to local plugin tables. A non-curated table takes rule 3.
     * exporter::FORBIDDEN_COLUMNS also strips credentials from any resolved list.
     *
     * @param string $table Unprefixed Moodle table name.
     * @return bool
     */
    protected static function may_widen_to_whole_row(string $table): bool {
        return strpos($table, 'local_') === 0;
    }

    /**
     * Whether whole-row export of discovered (InForm) tables is switched on.
     *
     * Off by default on a new install. Discovery always builds the schema catalog
     * (no rows move until a table is activated in the IntelliBoard service), but
     * sending a discovered table's row data off-site needs the administrator's
     * opt-in: the `dynamicdiscoveryexport` setting, locally managed so the control
     * webhook cannot change it. While it is off, a discovered=1 row widens nothing
     * (rule 2) and adds no export entity (rule 3). On upgrade the value is set by
     * {@see seed_discovery_export()}.
     *
     * @return bool
     */
    public static function dynamic_discovery_export_enabled(): bool {
        return (bool)(int)get_config('local_intellistream', 'dynamicdiscoveryexport');
    }

    /**
     * Set dynamicdiscoveryexport for a site upgrading from a version below
     * 2026100100 (called at the top of db/upgrade.php, before any step).
     *
     * A value already stored is kept, whoever wrote it (an administrator, or an
     * interrupted run of this upgrade), so a resumed upgrade reaches the same
     * result. Otherwise the version being upgraded from decides:
     *  - 2026080313 and lower: 0, because export is never switched on without an
     *    administrator's choice. Versions from 2026062200 export enabled discovered
     *    tables without the setting, so a notice says how many tables stop.
     *  - Above 2026080313 and below 2026100100: those versions export discovered
     *    tables unconditionally, so 1 if a discovered table is enabled for export
     *    (nothing that ships stops), else 0.
     * From 2026100100 on, nothing is decided or written.
     *
     * @param int $oldversion The version being upgraded from.
     * @return int The value now stored (as it was, from 2026100100 on).
     */
    public static function seed_discovery_export(int $oldversion): int {
        global $DB;
        $stored = get_config('local_intellistream', 'dynamicdiscoveryexport');
        if ($oldversion >= 2026100100) {
            return (int)$stored;
        }
        if ($stored !== false) {
            return (int)$stored;
        }
        $value = 0;
        if ($oldversion > 2026080313) {
            $value = $DB->record_exists(config_repository::TABLE, ['discovered' => 1, 'enabled' => 1]) ? 1 : 0;
        } else if ($oldversion >= 2026062200) {
            // The discovered column exists from 2026062200; those versions export
            // these tables without the setting, so tell the administrator they stop.
            // Built-in entities keep exporting their curated columns, so they are not counted.
            $builtin = \local_intellistream\exporter::registry();
            $stopped = 0;
            foreach ($DB->get_fieldset_select(config_repository::TABLE, 'datatype', 'discovered = 1 AND enabled = 1') as $name) {
                $stopped += isset($builtin[$name]) ? 0 : 1;
            }
            if ($stopped > 0) {
                mtrace(get_string('upgrade_discovery_stopped', 'local_intellistream', (object)[
                    'count' => $stopped,
                    'setting' => get_string('dynamicdiscoveryexport', 'local_intellistream'),
                    'plugin' => get_string('pluginname', 'local_intellistream'),
                ]));
            }
        }
        set_config('dynamicdiscoveryexport', $value, 'local_intellistream');
        return $value;
    }

    /**
     * An admin-supplied entity may not use the standard logstore (it would read
     * the whole log from row 0 on every export; the recovery window tool reads it by
     * window) nor a `window_` datatype name (the pipeline keeps that prefix out of every
     * normal path, so its rows would never reach a report).
     *
     * @param string $datatype datatype name
     * @param string $table backing table
     * @return bool
     */
    public static function is_reserved(string $datatype, string $table): bool {
        return $table === 'logstore_standard_log' || strpos($datatype, 'window_') === 0;
    }

    /**
     * True when $table is a real table on this Moodle DB.
     *
     * Public because exporter::datatype_config_catalog() also hands a stored
     * `custom_table` to $DB->get_columns(), which does not escape the name on
     * Postgres. It is also the table-presence check for registry entries whose
     * plugin table may not be installed (attendance, customcert, BigBlueButton...).
     * Same lookup and cache as core database_manager::table_exists(), without
     * using the DDL manager outside db/upgrade.php.
     *
     * @param string $table Unprefixed Moodle table name.
     * @return bool
     */
    public static function table_is_real(string $table): bool {
        global $DB;
        try {
            $tables = $DB->get_tables();
            return isset($tables[$table]);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
