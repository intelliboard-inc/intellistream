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
 * External web service definitions for local_intellistream.
 *
 * The plugin exposes a pull-style web-service surface that mirrors
 * intellidata's "IntelliData Service" name as "IntelliBoard Pro", so a
 * customer that already has a Moodle WS token pointed at the legacy
 * IntelliData plugin can repoint the same token at this plugin without
 * reconfiguring the puller.
 *
 * Two functions are registered: one returns a batch of canonical records from
 * the local buffer (events, entity snapshots, page-dwell beacons), the other
 * returns plugin health for the puller's admin to verify connectivity.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_intellistream_pull_export' => [
        'classname'     => '\\local_intellistream\\external\\pull_export',
        'methodname'    => 'execute',
        'description'   => 'Pull a batch of canonical IntelliStream records (events / '
                         . 'entity snapshots / page-dwell) from the local buffer. '
                         . 'Returns up to the requested limit of records and deletes each fully '
                         . 'drained buffer file, so the S3 shipper cannot '
                         . 'double-ship it and no drained data lingers on disk. '
                         . 'NOT retry-safe without the id de-dup: the caller must '
                         . 'de-duplicate on record id.',
        // Declared 'write' because the call mutates server state (drained buffer files are
        // deleted), so it must not be routed as a side-effect-free read.
        'type'          => 'write',
        'capabilities'  => 'local/intellistream:pullexport',
        'ajax'          => false,
        'loginrequired' => true,
    ],
    'local_intellistream_get_status' => [
        'classname'     => '\\local_intellistream\\external\\get_status',
        'methodname'    => 'execute',
        'description'   => 'Return IntelliStream shipper state, buffer file count, '
                         . 'and last successful ship timestamp. Useful for the '
                         . 'puller admin to verify that capture is healthy.',
        'type'          => 'read',
        'capabilities'  => 'local/intellistream:pullexport',
        'ajax'          => false,
        'loginrequired' => true,
    ],
];

// Mirror intellidata's service name so the customer's existing WS token
// configuration (service shortname is computed from this name by Moodle) is
// interchangeable between the two plugins.
$services = [
    'IntelliBoard Pro' => [
        'functions' => [
            'local_intellistream_pull_export',
            'local_intellistream_get_status',
        ],
        'requiredcapability' => 'local/intellistream:pullexport',
        // Authorised users only. The required capability already gates the
        // service, but a capability can be granted far more broadly than
        // intended, and this service hands back the full buffered PII stream —
        // so membership is an explicit, per-user list an administrator
        // maintains rather than a side effect of a role grant.
        //
        // Turning this on retroactively restricts a service that already
        // exists: core rewrites the stored flag on upgrade
        // (lib/upgradelib.php), and webservice/lib.php then joins
        // {external_services_users} for every call, so a token holder who is
        // not on the list loses access. The upgrade block for this version
        // adopts every existing token holder onto the list so no working
        // integration breaks on the upgrade itself.
        'restrictedusers'    => 1,
        'enabled'            => 1,
        'downloadfiles'      => 0,
        'uploadfiles'        => 0,
        'shortname'          => 'intelliboard_pro',
    ],
];
