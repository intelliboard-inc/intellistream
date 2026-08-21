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

use local_intellistream\repositories\config_repository;

/**
 * Applies admin-supplied overrides on top of the built-in exporter registry.
 *
 * The built-in registry is the hard-coded array returned by
 * {@see \local_intellistream\exporter::registry()}. It defines table+columns for
 * every entity the adapter ships out of the box. This service lets an admin:
 *
 *  - disable an entity entirely (the registry row is dropped),
 *  - override the SELECT column list for a built-in entity, or
 *  - add a brand-new entity backed by a Moodle table that isn't in the
 *    built-in registry.
 *
 * Other agents — the exporter, the scheduled task, etc. — should call
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
     *   2. On an existing (built-in) registry row: an explicit custom_columns
     *      override replaces `columns` (comma- or newline-separated). Otherwise,
     *      if the row was auto-registered by discovery (`discovered=1`),
     *      `columns` is forced to `*` so the table's in_form_table_*
     *      dynamic-table version carries every live column (intellidata
     *      whole-table parity) without disturbing the curated commerce marts
     *      (load_raw selects named fields and ignores the extras).
     *   3. custom_table non-empty and no built-in registry entry exists for
     *      this datatype -> add a brand-new entry. `table` is custom_table,
     *      `columns` is custom_columns (falls back to `*`).
     *
     * The input array is not mutated; a new associative array is returned.
     *
     * @param array $registry Output of {@see exporter::registry()}.
     * @return array Effective registry with overrides applied.
     */
    public function apply_overrides_to_registry(array $registry): array {
        $rows = $this->repo->get_all();
        if (empty($rows)) {
            return $registry;
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
                    // (SQL-injection guard). If NOTHING in the
                    // override is a real column, keep the built-in's curated
                    // columns rather than falling back to '*' (which would
                    // defeat the exporter's deliberate secret-column exclusion).
                    $safe = self::whitelist_columns($effective[$datatype]['table'], $customcolumns);
                    if ($safe !== null) {
                        $effective[$datatype]['columns'] = $safe;
                    }
                } else if (!empty($rec->discovered) && self::may_widen_to_whole_row($effective[$datatype]['table'] ?? '')) {
                    // Auto-discovered built-in (e.g. an IntelliCart commerce
                    // table that is also a curated built-in): export the WHOLE
                    // row so its in_form_table_* dynamic-table version carries
                    // every live column (intellidata whole-table parity). The
                    // curated commerce marts are unaffected — load_raw selects
                    // named fields and ignores extras; row COUNT is unchanged.
                    $effective[$datatype]['columns'] = '*';
                }
                continue;
            }

            // 3) Brand-new admin-supplied entity. The backing table name is
            // admin free-text that lands as the {table} target of
            // $DB->get_recordset_select(), so it must be a REAL table before
            // it is trusted, and its columns are whitelisted.
            if ($customtable !== '' && self::table_is_real($customtable)) {
                $safe = ($customcolumns !== null && $customcolumns !== '')
                    ? self::whitelist_columns($customtable, $customcolumns)
                    : null;
                $effective[$datatype] = [
                    'table'   => $customtable,
                    'columns' => $safe ?? '*',
                ];
            }
        }

        return $effective;
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
     * Two callers with different needs share this one lookup:
     *
     *  - whitelist_columns() below, which needs the SAFE list because an admin
     *    override is free text spliced into a SELECT.
     *  - exporter::resolve_entity_columns(), which needs the safe list AND the
     *    names that were dropped, so schema drift can be reported instead of
     *    silently costing a whole entity.
     *
     * `missing` is EMPTY only when introspection itself failed — there was
     * nothing to compare against. That is what lets a caller tell "this table is
     * unreadable" (columns null, missing empty -> fail open, keep the declared
     * list) apart from "none of these tokens are real columns" (columns null,
     * missing lists every token -> nothing safe to select). Collapsing those two
     * would make an entity whose whole list is absent silently fail open.
     *
     * $DB->get_columns() is served from Moodle's `databasemeta` cache plus a
     * per-request static, so this is cheap enough for the observer path once the
     * cache is warm. A STALE databasemeta entry — e.g. straight after a core
     * upgrade without purge_caches.php — is the one way a genuinely live column
     * can be reported missing.
     *
     * @param string $table Unprefixed Moodle table name.
     * @param string $columns Declared/normalised list ('*' passes through).
     * @return array{columns: ?string, missing: string[]} columns: safe ', '-joined
     *         canonical list, '*', or null when the table could not be
     *         introspected OR no token matched. missing: lowercased declared
     *         tokens absent from the live table; empty ONLY when introspection
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

        // An ABSENT table does not throw: get_columns() returns an empty array.
        // Treated naively that reads as "every declared column is missing", which
        // reported each uninstalled optional plugin (attendance, customcert,
        // questionnaire, IntelliCart...) as a fully drifted entity. A real table
        // always has at least one column, so empty means absent/unreadable — the
        // same "cannot compare" case as a throw.
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
     * $fields/SELECT list, which Moodle does NOT parameterise or escape. This
     * method keeps ONLY tokens that are exact column names of $table (looked up
     * from the live schema via get_columns()), emitting the canonical column
     * name. Anything else — including comment-obfuscated sub-select payloads
     * that survive normalise_columns() (single token, no spaces) — is dropped.
     *
     * The dropped tokens are deliberately DISCARDED here rather than reported:
     * on this path they are attacker-controlled free text, and a rejected
     * sub-select payload must never reach a diagnostic that gets shipped
     * off-site. Only the curated registry's drift is reported.
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
     * Whether a discovered row may widen a CURATED registry entry to whole-row
     * ('*') export.
     *
     * Discovery exists to give local plugin families (default prefix
     * `local_intellicart_`) in_form whole-table parity. But
     * dynamic_discovery_service::match_tables() matches on a bare prefix test and
     * registers under the bare table name, which is also the registry key — so a
     * prefix of `user` matches core's `user` table, shadows the curated `user`
     * entry, and would replace its deliberately password-free column list with
     * '*', shipping the password hashes.
     *
     * No core Moodle table is named `local_*` (that namespace belongs to local
     * plugins), so requiring the prefix here keeps the IntelliCart use case
     * working exactly as before while making the core-shadowing route impossible.
     * Discovery of a NON-curated table is unaffected: it takes rule 3, not this
     * branch.
     *
     * exporter::FORBIDDEN_COLUMNS is the belt to this braces — it strips
     * credentials from any resolved list, however it was widened.
     *
     * @param string $table Unprefixed Moodle table name.
     * @return bool
     */
    protected static function may_widen_to_whole_row(string $table): bool {
        return strpos($table, 'local_') === 0;
    }

    /**
     * True when $table is a real table on this Moodle DB.
     *
     * Public because the same guard is needed on the read-only catalog path —
     * exporter::datatype_config_catalog() also hands a stored `custom_table`
     * straight to $DB->get_columns(), which interpolates the name unescaped on
     * Postgres.
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
