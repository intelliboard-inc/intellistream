<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_intellistream;

/**
 * Exporter whose column read fails for one table, as a failed query would.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unreadable_columns_exporter extends exporter {
    /** @var string Table whose columns cannot be read. */
    public static $unreadable = 'forum';

    /**
     * The live columns, or none for the unreadable table.
     *
     * @param string $table
     * @return string[]
     */
    protected static function live_columns(string $table): array {
        return $table === self::$unreadable ? [] : parent::live_columns($table);
    }
}
