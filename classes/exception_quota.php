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
// Per-IP and global rate limit for the exception-capture path.

namespace local_intellistream;

/**
 * Best-effort per-IP and site-wide quota on exception capture.
 *
 * The exceptions observer is registered on \core\event\user_login_failed, which
 * core fires on every rejected login attempt — so unlike every other capture path
 * in this plugin it is reachable by a caller with no session at all, no sesskey
 * and no account. Each captured attempt used to cost roughly 3-5 KB of shared
 * buffer (a 30-frame backtrace, truncated at 8 KB), against a few hundred bytes
 * for core's own logstore row for the same event: the plugin multiplied the cost
 * of a failed login by about ten, with no ceiling of any kind.
 *
 * The end state matters more than the volume. At config::max_buffer_bytes()
 * (5 GB default) buffer::have_capacity() refuses every new buffer file, so ALL
 * capture stops site-wide; on a push-configured site shipper::enforce_disk_cap()
 * also starts unlinking the oldest un-shipped `.closed` files, which the plugin's
 * own alert string calls "permanently lost". An unauthenticated caller should not
 * be able to steer the plugin there.
 *
 * The structurally identical dwell endpoint was given a quota for exactly this
 * failure mode ({@see dwell_quota}) — and that endpoint additionally requires an
 * authenticated non-guest session with a valid sesskey. This closes the
 * inconsistency.
 *
 * Two counters, because there is no user to attribute a failed login to:
 *
 *  - per remote address, which bounds the ordinary single-source flood;
 *  - site-wide, which bounds a distributed one. Without the global counter a
 *    caller rotating addresses pays no quota at all.
 *
 * Deliberately approximate, exactly as dwell_quota is. The counters live in a
 * MODE_APPLICATION cache with a 60-second TTL and are read-then-written without a
 * lock, so concurrent requests can race and undercount slightly. That is the
 * right trade: the goal is to bound a flood by orders of magnitude, not to meter
 * exactly, and taking a lock (or writing to the database) on the failed-login path
 * would hand an unauthenticated caller a more expensive lever than the one being
 * removed.
 *
 * Fails OPEN: if the cache is unavailable the record is captured. A limiter that
 * silently discarded real diagnostics whenever a cache backend hiccuped would be
 * worse than the flood it guards against, and the disk cap remains the backstop.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exception_quota {
    /** MUC application-cache area holding the counters. */
    const CACHE_AREA = 'exception_quota';

    /** Multiple of the per-IP limit allowed site-wide in one window. */
    const GLOBAL_FACTOR = 10;

    /**
     * Count one exception record against the quotas and say whether to keep it.
     *
     * @return bool True to capture the record, false to throttle it.
     */
    public static function allow(): bool {
        $limit = config::exception_max_per_minute();
        if ($limit <= 0) {
            // Quota disabled by configuration.
            return true;
        }

        try {
            $cache = \cache::make(config::COMPONENT, self::CACHE_AREA);

            // Hashed so the key stays alphanumeric and the definition can keep
            // simplekeys => true: raw addresses carry dots and colons. sha1 is
            // used as a key-shortening function here, not as a security
            // primitive, and it keeps IPv6 keys a fixed length.
            $ip = (string)getremoteaddr('');
            $ipkey = 'ip' . sha1($ip);

            $ipcount = (int)$cache->get($ipkey);
            if ($ipcount >= $limit) {
                return false;
            }

            // Site-wide ceiling, so rotating source addresses does not escape the
            // limit entirely.
            $allcount = (int)$cache->get('all');
            if ($allcount >= ($limit * self::GLOBAL_FACTOR)) {
                return false;
            }

            // The definition's 60s TTL is what makes these per-minute windows:
            // the keys simply expire, so there is no roll-over bookkeeping to get
            // wrong.
            $cache->set($ipkey, $ipcount + 1);
            $cache->set('all', $allcount + 1);
            return true;
        } catch (\Throwable $e) {
            // Fail open — see the class docblock.
            return true;
        }
    }
}
