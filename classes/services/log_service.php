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
 * Designed for use from the shipper, the bulk exporter, the buffer
 * rotation/drop path, and any other place where an operator needs to see
 * "what did this plugin do, and when". The companion `logs.php` page renders
 * these rows in a paged, filterable table.
 *
 * Column layout (see db/install.xml):
 *   - type        e.g. 'ship', 'export', 'refresh', 'error'
 *   - datatype    optional registry key the entry refers to
 *   - action      verb describing what was done (e.g. 'ok', 'fail', 'start')
 *   - details     free-form, used for short status strings
 *   - timecreated unix timestamp
 */
class log_service {
    /** Table name. */
    const TABLE = 'local_intellistream_logs';

    /**
     * Record one operational log entry.
     *
     * Swallows DB errors silently — the log feed is a diagnostic aid, not a
     * critical path. Other agents call this from inside their own try/catch
     * blocks, so a failure to *log* must never cascade and break the actual
     * work being logged. Returns the new row id on success, or 0 on failure.
     *
     * @param string      $type     Bucket: ship, export, refresh, error, ...
     * @param string|null $datatype Optional registry key.
     * @param string      $action   Verb / outcome.
     * @param string      $details  Free-form context (trimmed to TEXT field).
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
