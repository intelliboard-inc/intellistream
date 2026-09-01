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
 * Cache definitions for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Replay/idempotency guard for the inbound control webhook. Stores each
    // applied command_id briefly so a re-delivered (retried) command is a
    // no-op. TTL comfortably exceeds the webhook's timestamp window so a
    // captured request cannot be replayed after it expires from the guard.
    // simplekeys is false because command ids are UUIDs (contain hyphens).
    'webhook_seen' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => false,
        'simpledata' => true,
        'ttl'        => 900,
    ],

    // Per-user, per-minute beacon counter for dwell.php. Any authenticated
    // non-guest user with a valid sesskey could POST dwell beacons without limit,
    // and a sustained flood grows the shared buffer until the disk cap evicts the
    // OLDEST unshipped files — i.e. one user could destroy other users' captured
    // events.
    //
    // MODE_APPLICATION with a short TTL is the right shape: the counter is a
    // best-effort rate estimate, not an accounting record, so the get/increment/set
    // race is acceptable — a determined flooder might land a few extra beacons per
    // window, which does not change the outcome. Keys are 'u<userid>', so
    // simplekeys is safe; the value is a bare int, so simpledata is too.
    'dwell_quota' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 60,
    ],

    // Per-IP and site-wide counters for exception capture. Same shape and the
    // same reasoning as dwell_quota above, but this path needs it more: the
    // exceptions observer is registered on \core\event\user_login_failed, so it
    // is reachable with no session, no sesskey and no account, and each captured
    // attempt cost several KB of the shared buffer against a few hundred bytes
    // for core's own log row for the same event.
    //
    // Two keys per window: 'ip<sha1>' and 'all'. The hash is only there to keep
    // keys alphanumeric so simplekeys stays true — raw addresses carry dots and
    // colons. Values are bare ints, so simpledata is safe.
    'exception_quota' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 60,
    ],
];
