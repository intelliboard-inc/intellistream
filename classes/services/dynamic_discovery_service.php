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
 * Dynamic-table discovery: auto-register plugin-family tables as InForm
 *
 * dynamic-table candidates (push-native parity with intellidata).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\services;

use local_intellistream\repositories\config_repository;

/**
 * Enumerates live Moodle tables matching the configured plugin-family prefixes
 * and registers each as a `discovered` row in `local_intellistream_config`, so
 * it flows into the `inform_dyn_schema` catalog and becomes an InForm
 * dynamic-table CANDIDATE downstream.
 *
 * This is the push-native equivalent of intellidata's
 * `dbschema_service::get_tableslist()` + `cli/insert_customtables.php`: the
 * ancestor auto-discovered every optional table (including the whole
 * `local_intellicart_*` family) and exposed it through the
 * `local_intellidata_get_dbschema_custom` web service. We reproduce that with a
 * generic, config-driven PREFIX rule — never a hand-picked table list — so the
 * IntelliCart family (and any future family an admin adds to the prefix
 * setting) is covered without code changes.
 *
 * Discovery only builds the CATALOG (candidates). It NEVER activates a table:
 * activation stays an explicit admin action (the connection's dynamic-tables
 * flag + per-table activate in supernova), exactly as in intellidata. A
 * discovered row an admin later disables (enabled=0) is left disabled —
 * re-discovery never flips `enabled` back on, because
 * {@see config_repository::save()} only defaults `enabled` on INSERT and we
 * never set it on UPDATE.
 */
class dynamic_discovery_service {
    /**
     * Prefix every configured discovery prefix must itself start with.
     *
     * Declared here rather than on the admin setting class because the
     * enforcing read in prefixes() runs from cron and CLI, where
     * lib/adminlib.php is not loaded and the setting class — which extends a
     * core admin_setting — therefore cannot be autoloaded.
     */
    const REQUIRED_PREFIX = 'local_';

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
     * The configured plugin-family prefixes (one per line / comma-separated).
     * Empty array => discovery disabled.
     *
     * Every prefix must itself start with `local_`. That invariant is enforced
     * here, at the point of use, and not only in the admin form: a prefix
     * matching a core table shadows the curated registry entry for that table
     * and widens it to whole-row export, which would ship the columns the
     * curated lists exist to withhold. The setting is writable by any caller
     * that can reach `mdl_config_plugins` — including `set_plugin_config` over
     * the control webhook, which never invokes `admin_setting::validate()` —
     * so a form-time check alone does not hold the invariant.
     *
     * This mirrors `config::buffer_dir()`, which likewise re-validates its
     * stored value on every read rather than trusting the write path.
     *
     * A prefix that fails the rule is dropped, not corrected: dropping narrows
     * discovery to nothing for that entry, while rewriting it would silently
     * change which tables an administrator believes are being exported.
     *
     * @return string[]
     */
    public static function prefixes(): array {
        $raw = (string)get_config('local_intellistream', 'dynamic_discovery_prefixes');
        if (trim($raw) === '') {
            return [];
        }
        $clean = [];
        foreach (preg_split('/[\s,]+/', $raw) as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (strpos($p, self::REQUIRED_PREFIX) !== 0) {
                debugging(
                    'local_intellistream: discovery prefix "' . $p . '" does not start with "'
                    . self::REQUIRED_PREFIX
                    . '" and was ignored. A prefix that can match a core table is refused at'
                    . ' read time regardless of how it was stored.',
                    DEBUG_NORMAL
                );
                continue;
            }
            $clean[] = $p;
        }
        return $clean;
    }

    /**
     * Return the live unprefixed Moodle table names that match a discovery
     * prefix. Read-only; safe to call from a dry run.
     *
     * @param string[] $prefixes
     * @return string[]
     */
    public function match_tables(array $prefixes): array {
        global $DB;
        if (empty($prefixes)) {
            return [];
        }
        // Calling get_tables(false) bypasses the schema cache so a freshly-installed
        // plugin's tables are seen on the very next discovery run.
        $tables = $DB->get_tables(false);
        $matched = [];
        foreach ($tables as $table) {
            foreach ($prefixes as $prefix) {
                if (strpos($table, $prefix) === 0) {
                    $matched[] = $table;
                    break;
                }
            }
        }
        sort($matched);
        return $matched;
    }

    /**
     * Discover plugin-family tables and register each as a candidate row.
     *
     * @return array{prefixes:string[], registered:int, tables:string[]}
     */
    public function discover(): array {
        $prefixes = self::prefixes();
        $matched = $this->match_tables($prefixes);
        foreach ($matched as $table) {
            $this->register($table);
        }
        return [
            'prefixes'   => $prefixes,
            'registered' => count($matched),
            'tables'     => $matched,
        ];
    }

    /**
     * Idempotently register one table as a discovered dynamic-table candidate.
     * datatype == the (unprefixed) Moodle table name, which is also the
     * registry key for the built-in intellicart entities, so the per-row
     * entity snapshots and the catalog key line up.
     *
     * @param string $table Unprefixed live table name.
     * @return void
     */
    protected function register(string $table): void {
        // Only custom_table + discovered are set, so save() leaves an existing
        // row's `enabled` untouched (preserving an admin opt-out) and defaults
        // enabled=1 only on first insert. `discovered` is (re)asserted to 1 on
        // every run: a family-matching table is, by definition, a discovered
        // dynamic table, so it gets whole-row (`*`) in_form export parity even
        // if an admin had hand-added a row for it earlier (an explicit
        // custom_columns override still wins — see config_service rule 2).
        $this->repo->save($table, (object)[
            'custom_table' => $table,
            'discovered'   => 1,
        ]);
    }
}
