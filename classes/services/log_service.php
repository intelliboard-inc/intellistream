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
 * Operational-log writer.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\services;

/**
 * Append a single row to `local_intellistream_logs`.
 *
 * Used by the exporter, the datatype settings page and the backfill, tracking
 * copy and re-fetch tasks, so an administrator can see what the plugin did and
 * when. The companion `logs.php` page shows these rows in a paged, filterable
 * table.
 *
 * Column layout (see db/install.xml):
 *   - type        one of types()
 *   - datatype    optional registry key the entry refers to
 *   - action      one of actions()
 *   - details     short data about the entry, usually JSON
 *   - timecreated unix timestamp
 */
class log_service {
    /** Table name. */
    const TABLE = 'local_intellistream_logs';

    /** Type: a record was changed or left out by the exporter. */
    const TYPE_EXPORT = 'export';
    /** Type: an administrator saved a datatype setting. */
    const TYPE_CONFIG = 'config';
    /** Type: a run of the legacy tracking migration task. */
    const TYPE_LEGACY_MIGRATION = 'legacy_migration';
    /** Type: a run of the historical backfill task. */
    const TYPE_HISTORICAL_BACKFILL = 'historical_backfill';
    /** Type: a targeted re-fetch attempt. */
    const TYPE_TARGETED_REFETCH = 'targeted_refetch';

    /** Action: a record was shortened to fit the size limit. */
    const ACTION_SHORTENED = 'shortened';
    /** Action: a record was too large and was left out. */
    const ACTION_OVERSIZE = 'oversize';
    /** Action: the columns of a table could not be read. */
    const ACTION_COLUMNS_UNREADABLE = 'columnsunreadable';
    /** Action: a setting was saved. */
    const ACTION_SAVE = 'save';
    /** Action: a run made progress and continues. */
    const ACTION_PROGRESS = 'progress';
    /** Action: a run found nothing to do. */
    const ACTION_IDLE = 'idle';
    /** Action: a run finished its work. */
    const ACTION_COMPLETE = 'complete';
    /** Action: a run stopped before it finished. */
    const ACTION_INCOMPLETE = 'incomplete';
    /** Action: a run was skipped. */
    const ACTION_SKIPPED = 'skipped';

    /**
     * Every type this plugin writes.
     *
     * @return string[]
     */
    public static function types(): array {
        return [
            self::TYPE_EXPORT,
            self::TYPE_CONFIG,
            self::TYPE_LEGACY_MIGRATION,
            self::TYPE_HISTORICAL_BACKFILL,
            self::TYPE_TARGETED_REFETCH,
        ];
    }

    /**
     * Every action this plugin writes.
     *
     * @return string[]
     */
    public static function actions(): array {
        return [
            self::ACTION_SHORTENED,
            self::ACTION_OVERSIZE,
            self::ACTION_COLUMNS_UNREADABLE,
            self::ACTION_SAVE,
            self::ACTION_PROGRESS,
            self::ACTION_IDLE,
            self::ACTION_COMPLETE,
            self::ACTION_INCOMPLETE,
            self::ACTION_SKIPPED,
        ];
    }

    /**
     * Display name of a type. A value that is not in types() is returned unchanged.
     *
     * @param string $type
     * @return string
     */
    public static function type_label(string $type): string {
        if (!in_array($type, self::types(), true)) {
            return $type;
        }
        return get_string('logtype_' . $type, 'local_intellistream');
    }

    /**
     * Display name of an action. A value that is not in actions() is returned unchanged.
     *
     * @param string $action
     * @return string
     */
    public static function action_label(string $action): string {
        if (!in_array($action, self::actions(), true)) {
            return $action;
        }
        return get_string('logaction_' . $action, 'local_intellistream');
    }

    /**
     * Record one operational log entry.
     *
     * A database error is not thrown: the log is a diagnostic aid, and a failure
     * to log must not stop the work being logged. Returns the new row id, or 0 on
     * failure.
     *
     * @param string      $type     One of types().
     * @param string|null $datatype Optional registry key.
     * @param string      $action   One of actions().
     * @param string      $details  Short data about the entry, usually JSON.
     * @return int
     */
    public static function record(string $type, ?string $datatype, string $action, string $details = ''): int {
        global $DB;

        $record = (object)[
            'type'        => $type,
            'datatype'    => $datatype !== null ? $datatype : '',
            'action'      => $action,
            'details'     => $details,
            'timecreated' => time(),
        ];

        try {
            return (int)$DB->insert_record(self::TABLE, $record);
        } catch (\Throwable $e) {
            // Deliberately do not rethrow — see method PHPDoc.
            return 0;
        }
    }
}
