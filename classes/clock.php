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
 * Timestamp helper for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Produces the RFC 3339 UTC timestamps used in every buffer record envelope.
 *
 * Kept separate from observer so the hot-path observer, the page-dwell
 * endpoint, and the entity exporter all format `captured_at` identically.
 */
class clock {
    /**
     * RFC 3339 UTC timestamp with microsecond precision (e.g. the value used
     * for a record's `captured_at`).
     *
     * @return string
     */
    public static function now(): string {
        $t = microtime(true);
        $whole = (int)$t;
        $micros = (int)round(($t - $whole) * 1000000);
        if ($micros >= 1000000) {
            $whole++;
            $micros = 0;
        }
        return gmdate('Y-m-d\TH:i:s', $whole) . sprintf('.%06dZ', $micros);
    }

    /**
     * RFC 3339 UTC timestamp (whole seconds) for a given unix time.
     *
     * @param int $unixseconds
     * @return string
     */
    public static function at(int $unixseconds): string {
        return gmdate('Y-m-d\TH:i:s\Z', $unixseconds);
    }
}
