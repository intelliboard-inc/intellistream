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
 * CRUD repository for local_intellistream_config.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\repositories;

/**
 * Repository for the per-datatype admin-configuration overrides table.
 *
 * Backing table: `local_intellistream_config` (see db/install.xml). One row per
 * `datatype` (unique key). The `enabled` column gates whether the exporter
 * registry entry is honoured at all; `custom_columns` lets an admin override
 * the SELECT list for an existing entity; `custom_table` lets an admin add a
 * brand-new table that is not in the built-in registry.
 *
 * The repository is intentionally thin — it knows how to read and write rows
 * and nothing else. Higher-level merging into the static registry is the job
 * of {@see \local_intellistream\services\config_service}.
 */
class config_repository {
    /** Table name. */
    const TABLE = 'local_intellistream_config';

    /**
     * Fetch one config record by datatype, or null if there isn't one.
     *
     * @param string $datatype Registry key.
     * @return \stdClass|null Raw DB record, or null when absent.
     */
    public function get(string $datatype): ?\stdClass {
        global $DB;
        $rec = $DB->get_record(self::TABLE, ['datatype' => $datatype]);
        return $rec ? $rec : null;
    }

    /**
     * Fetch every config record, indexed by datatype.
     *
     * @return array<string, \stdClass>
     */
    public function get_all(): array {
        global $DB;
        $records = $DB->get_records(self::TABLE);
        $byname = [];
        foreach ($records as $rec) {
            if (!empty($rec->datatype)) {
                $byname[$rec->datatype] = $rec;
            }
        }
        return $byname;
    }

    /**
     * Insert or update the config row for $datatype.
     *
     * The $record may be a stdClass or array; only the known columns are
     * persisted, and timestamps are managed here. Returns the row id.
     *
     * @param string                   $datatype
     * @param \stdClass|array          $record
     * @return int
     */
    public function save(string $datatype, $record): int {
        global $DB;

        $now = time();
        $data = (object)(array)$record;
        $data->datatype = $datatype;
        $data->timemodified = $now;

        $existing = $this->get($datatype);

        // Restrict to the columns that actually exist in the table.
        $columns = [
            'datatype', 'enabled', 'tabletype', 'discovered', 'custom_columns',
            'custom_table', 'notes', 'timecreated', 'timemodified',
        ];
        $clean = new \stdClass();
        foreach ($columns as $col) {
            if (property_exists($data, $col)) {
                $clean->$col = $data->$col;
            }
        }

        if ($existing) {
            $clean->id = $existing->id;
            if (!isset($clean->timecreated) || empty($clean->timecreated)) {
                $clean->timecreated = $existing->timecreated;
            }
            $DB->update_record(self::TABLE, $clean);
            self::invalidate_derived_caches();
            return (int)$existing->id;
        }

        if (empty($clean->timecreated)) {
            $clean->timecreated = $now;
        }
        if (!isset($clean->enabled)) {
            $clean->enabled = 1;
        }
        $id = (int)$DB->insert_record(self::TABLE, $clean);
        self::invalidate_derived_caches();
        return $id;
    }

    /**
     * Clear every per-request cache derived from this table.
     *
     * This repository is the ONLY writer of `local_intellistream_config` —
     * dynamic discovery registers through save() too — so this is the single
     * choke point where a write becomes visible to a later read in the same
     * request. The control webhook does exactly that: it saves datatype config
     * and then reports the resulting catalogue back within one call.
     *
     * Anything that memoises a value derived from these rows must be listed here.
     *
     * @return void
     */
    private static function invalidate_derived_caches(): void {
        \local_intellistream\exporter::reset_registry_cache();
        \local_intellistream\observers\exceptions_observer::reset_datatype_cache();
    }

    /**
     * Delete the config row for $datatype, if any.
     *
     * @param string $datatype
     * @return void
     */
    public function delete(string $datatype): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['datatype' => $datatype]);
        // See invalidate_derived_caches(): no memo may outlive the row it reflects.
        self::invalidate_derived_caches();
    }

    /**
     * Is $datatype enabled? A missing row counts as enabled (registry default).
     *
     * @param string $datatype
     * @return bool
     */
    public function is_enabled(string $datatype): bool {
        $rec = $this->get($datatype);
        if ($rec === null) {
            return true;
        }
        return (int)$rec->enabled !== 0;
    }
}
