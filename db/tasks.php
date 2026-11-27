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
 * Scheduled task registration for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_intellistream\task\ship_events',
        'blocking'  => 0,
        'minute'    => '*',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    // The recurring export task, enabled, every 15 minutes: rows whose change
    // timestamp moved (near-real-time), then a slice of the verification sweep,
    // which reads every table once per 24 h, spread evenly through the day (tables
    // with no change timestamp, changes that do not move one, the census for deletes).
    // The one-time history backfill is a separate task (below), its ad-hoc runs, or
    // cli/backfill.php.
    [
        'classname' => '\local_intellistream\task\refresh_entities',
        'blocking'  => 0,
        'minute'    => '*/15',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],

    // Per-user login counts, every 15 minutes. Enabled: each run reads only the log ids
    // written since the last one, in short blocks, and sends the totals that changed.
    // The first run on a site counts the whole log, over as many runs as it takes.
    [
        'classname' => '\local_intellistream\task\count_userlogins',
        'blocking'  => 0,
        'minute'    => '*/15',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],

    // One-time migration of the legacy IntelliBoard plugin's tracking data. Copies
    // rows from the `local_intelliboard_*` tables (if present) into the buffer as
    // entity_snapshot records. Idempotent and resumable. Disabled by default: an
    // administrator enables it for the migration, and it disables itself on
    // completion.
    [
        'classname' => '\local_intellistream\task\copy_intelliboard_tracking',
        'blocking'  => 0,
        'minute'    => '*/30',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 1,
    ],

    // One-time, resumable historical backfill wrapper. Lets administrators run the
    // same resumable code path as cli/backfill.php from the Moodle UI on sites
    // without shell access. Disabled by default: once enabled it runs every 30
    // minutes, each run limited to backfill::run_limit_sec(), until the campaign
    // is complete; "Run now" starts one run. A run that stops resumes from the
    // saved per-entity watermark on the next run (it does not restart from zero)
    // and does not re-snapshot completed entities. Disables itself on completion.
    [
        'classname' => '\local_intellistream\task\historical_backfill',
        'blocking'  => 0,
        'minute'    => '*/30',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 1,
    ],

    // Daily dynamic-table discovery at 02:50: registers plugin-family tables
    // (default local_intellicart_*) as dynamic-table candidates for the reporting
    // service. The 15-minute task's verification sweep re-reads the registry every
    // run, so a newly registered table is carried from its next run. Enabled: does
    // nothing when the master switch is off or no discovery prefixes are configured,
    // and only creates candidates (it never activates a table or ships data).
    [
        'classname' => '\local_intellistream\task\discover_dynamic_tables',
        'blocking'  => 0,
        'minute'    => 50,
        'hour'      => 2,
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // Blackboard Collaborate attendance pull, every 30 minutes. It finds finished
    // Collaborate sessions and pulls per-user attendance from the Blackboard cloud
    // into local_intellistream_colpart (then captured by refresh_entities like any
    // other entity). Disabled by default: it needs the three collab_* API
    // credentials to do anything. Enable it per site once Collaborate attendance
    // is configured.
    [
        'classname' => '\local_intellistream\task\collab_sync',
        'blocking'  => 0,
        'minute'    => '*/30',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 1,
    ],

    // Daily: deletes plugin log entries older than the loglifetime setting.
    [
        'classname' => '\local_intellistream\task\cleanup_logs',
        'blocking'  => 0,
        'minute'    => 'R',
        'hour'      => 'R',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
];
