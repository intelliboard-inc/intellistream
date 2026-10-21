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
 * Host-load gate for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Decides whether the shipper task may run, based on host load.
 *
 * Shipping is cooperative: if the Moodle host is already busy, skip this
 * cycle rather than compete with page renders. Buffer files persist, so a
 * skipped cycle just defers — it never loses events.
 */
class health {
    /**
     * Whether the 1-minute load average is low enough to ship.
     *
     * @return bool
     */
    public static function ship_allowed(): bool {
        if (!function_exists('sys_getloadavg')) {
            return true;
        }
        $load = sys_getloadavg();
        if (!is_array($load) || !isset($load[0])) {
            return true;
        }
        $cpus = self::cpu_count();
        if ($cpus === null) {
            // The core count is unknown, so there is no threshold to compare
            // against. Do NOT gate: falling back to a nominal 1 core put the
            // threshold at 1 * 0.7, which any busy host exceeds, so shipping
            // stopped permanently and the buffer grew until the disk cap began
            // deleting un-shipped files. An ungated run on a loaded host costs
            // some contention; a permanently closed gate costs data.
            return true;
        }
        $threshold = $cpus * config::load_gate_factor();
        return (float)$load[0] <= $threshold;
    }

    /**
     * Host CPU count, or null when it cannot be determined.
     *
     * Null rather than a nominal 1 on purpose: the caller has to be able to tell
     * "one core" from "no idea", because those warrant opposite decisions about
     * whether to gate at all.
     *
     * @return int|null
     */
    private static function cpu_count(): ?int {
        if (!is_readable('/proc/cpuinfo')) {
            return null;
        }
        $count = substr_count((string)@file_get_contents('/proc/cpuinfo'), "processor\t");
        return $count > 0 ? $count : null;
    }
}
