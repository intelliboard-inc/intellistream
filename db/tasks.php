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
    // Periodic full entity re-snapshot. ENABLED, every 15 minutes. The live
    // event stream (ship_events, above) already keeps high-velocity data
    // near-real-time, but snapshot-only fields that fire no Moodle event --
    // user lastaccess, SCORM tracks, current grade state, course structure --
    // only refresh through this task. A 15-minute cadence keeps the warehouse
    // near-real-time without the read load of a per-minute full re-snapshot.
    // One-time history backfill still comes from cli/backfill.php.
    [
        'classname' => '\local_intellistream\task\refresh_entities',
        'blocking'  => 0,
        'minute'    => '*/15',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],

    // ONE-TIME legacy IntelliBoard tracking migration. Copies rows from the
    // legacy `local_intelliboard_*` tables (if present) into the buffer as
    // entity_snapshot records. Idempotent and resumable; self-disables on
    // completion. Registered DISABLED by default — operators turn it on for
    // the one-time migration, then it disables itself.
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

    // ONE-TIME, RESUMABLE historical backfill wrapper. Lets operators trigger
    // the same resumable code path as cli/backfill.php from the Moodle UI on
    // sites without shell access, via "Run now". Registered DISABLED with NO
    // recurring schedule: it is a run-once job (the cron fields are dormant
    // while disabled). If a run is interrupted it resumes from the saved
    // per-entity watermark on the next trigger — never restarts from zero — and
    // never re-snapshots completed entities. Self-disables on completion.
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

    // Daily 03:00 FULL snapshot — the reconciliation pass that complements the
    // every-15-min INCREMENTAL refresh_entities: it re-exports everything (incl.
    // the timestamp-less tables and derived/aggregate entities that incremental
    // skips) so the warehouse self-heals drift and can reconcile deletions.
    // ENABLED: with incremental carrying the intra-day deltas cheaply, the daily
    // full is affordable and required for completeness.
    [
        'classname' => '\local_intellistream\task\daily_snapshot',
        'blocking'  => 0,
        'minute'    => 0,
        'hour'      => 3,
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // Daily dynamic-table discovery at 02:50 — auto-registers plugin-family
    // tables (default local_intellicart_*) as InForm dynamic-table candidates,
    // just BEFORE the 03:00 daily full snapshot so a newly-appeared table is
    // registered and then carried in the same full pass. Push-native parity
    // with intellidata's get_dbschema_custom auto-discovery. ENABLED: no-ops
    // when the master switch is off or no discovery prefixes are configured,
    // and only ever creates CANDIDATES (never activates / ships data).
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

    // Blackboard Collaborate attendance pull (Part B). Every 30 min. It finds
    // finished Collaborate sessions and pulls per-user attendance from the
    // Blackboard cloud into local_intellistream_colpart (then captured by
    // refresh_entities like any other entity). Registered DISABLED by default:
    // no current customer uses it, and it needs the 3 collab_* API creds to do
    // anything. Enable it per-site once Collaborate attendance is configured.
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
];
