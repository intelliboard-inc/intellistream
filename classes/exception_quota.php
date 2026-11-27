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
 * The exceptions observer listens to \core\event\user_login_failed, so this path is
 * reachable with no session, sesskey or account. Without a limit such a caller could
 * fill the shared buffer to config::max_buffer_bytes(), where all capture stops.
 * Two counters, because a failed login has no user: one per remote address and one
 * site-wide (for a caller rotating addresses).
 *
 * The counters are approximate (a MODE_APPLICATION cache with a 60-second TTL, read
 * then written without a lock); a lock or a database write on the failed-login path
 * would cost more than it saves. Fails open: if the cache is unavailable the record
 * is captured, and the disk cap remains the backstop.
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
