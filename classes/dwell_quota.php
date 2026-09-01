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
// Per-user rate limit for the dwell beacon endpoint.

namespace local_intellistream;

/**
 * Best-effort per-user, per-minute quota on dwell beacons.
 *
 * dwell.php is reachable by any authenticated non-guest user with a valid sesskey,
 * and every accepted beacon appends a record to the buffer that the whole site
 * shares. There was no ceiling on the rate, so a scripted client could grow the
 * buffer until shipper::enforce_disk_cap() started evicting the OLDEST unshipped
 * files — meaning one user could destroy other users' captured events. Request
 * throughput is the limiting factor and the shipper drains every minute, so this is
 * a nuisance rather than a clean exploit, but on shared multi-tenant infrastructure
 * a per-user quota is the prudent bound.
 *
 * Deliberately approximate. The counter lives in a MODE_APPLICATION cache with a
 * 60-second TTL and is read-then-written without a lock, so concurrent beacons from
 * one user can race and undercount slightly. That is the right trade here: the goal
 * is to bound a flood by orders of magnitude, not to meter exactly, and taking a
 * lock (or writing to the database) on a fire-and-forget beacon path would cost more
 * than the problem. A user racing the counter still cannot exceed the cap by more
 * than the number of genuinely concurrent requests they can sustain.
 *
 * Fails OPEN: if the cache is unavailable the beacon is accepted. A rate limit that
 * silently discards real analytics data when a cache backend hiccups would be worse
 * than the flood it guards against, and the disk cap remains the backstop.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dwell_quota {
    /** MUC application-cache area holding the per-user counters. */
    const CACHE_AREA = 'dwell_quota';

    /**
     * Count one beacon against the user's quota and say whether to accept it.
     *
     * @param int $userid Authenticated user id.
     * @return bool True to accept the beacon, false to throttle it.
     */
    public static function allow(int $userid): bool {
        $limit = config::dwell_max_per_minute();
        if ($limit <= 0) {
            // Quota disabled by configuration.
            return true;
        }
        if ($userid <= 0) {
            // No verified user to attribute the beacon to; callers gate on
            // isloggedin() before reaching here, so this is belt-and-braces.
            return true;
        }

        try {
            $cache = \cache::make(config::COMPONENT, self::CACHE_AREA);
            $key = 'u' . $userid;

            $count = (int) $cache->get($key);
            if ($count >= $limit) {
                return false;
            }

            // The definition's 60s TTL is what makes this a per-minute window: the
            // key simply expires, so there is no roll-over bookkeeping to get wrong.
            $cache->set($key, $count + 1);
            return true;
        } catch (\Throwable $e) {
            // Fail open — see the class docblock.
            return true;
        }
    }
}
