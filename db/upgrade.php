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
 * Upgrade routine for local_intellistream.
 *
 * This file is responsible only for installing/extending the *adapter's own*
 * support tables (local_intellistream_config, local_intellistream_logs). It never
 * modifies Moodle core tables. Versioned blocks here mirror intellidata's
 * db/upgrade.php style: each `if ($oldversion < N)` block uses the XMLDB
 * manager to install the new tables idempotently and then calls
 * upgrade_plugin_savepoint() to persist progress.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * local_intellistream upgrade callback.
 *
 * Sites upgrading from a pre-2026052001 build do not have the
 * `local_intellistream_config` / `local_intellistream_logs` tables yet; install them
 * one at a time from install.xml. Fresh installs use install.xml directly via
 * the Moodle installer and never reach this branch.
 *
 * @param int $oldversion Previous plugin version (from version.php).
 * @return bool
 */
function xmldb_local_intellistream_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();
    $installxml = __DIR__ . '/install.xml';

    if ($oldversion < 2026052001) {
        if (!$dbman->table_exists('local_intellistream_config')) {
            $dbman->install_one_table_from_xmldb_file($installxml, 'local_intellistream_config');
        }
        if (!$dbman->table_exists('local_intellistream_logs')) {
            $dbman->install_one_table_from_xmldb_file($installxml, 'local_intellistream_logs');
        }
        upgrade_plugin_savepoint(true, 2026052001, 'local', 'intellistream');
    }

    if ($oldversion < 2026060101) {
        // Collaborate Part B: per-user attendance cache + sync tracker.
        if (!$dbman->table_exists('local_intellistream_colpart')) {
            $dbman->install_one_table_from_xmldb_file($installxml, 'local_intellistream_colpart');
        }
        if (!$dbman->table_exists('local_intellistream_colsync')) {
            $dbman->install_one_table_from_xmldb_file($installxml, 'local_intellistream_colsync');
        }
        upgrade_plugin_savepoint(true, 2026060101, 'local', 'intellistream');
    }

    if ($oldversion < 2026062200) {
        // IntelliCart parity: mark which local_intellistream_config rows were
        // auto-registered by the dynamic-table discovery service vs hand-added
        // by an admin. Drives whole-table (`*`) column selection for discovered
        // tables without touching the curated built-in registry entries.
        $table = new xmldb_table('local_intellistream_config');
        $field = new xmldb_field('discovered', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026062200, 'local', 'intellistream');
    }

    if ($oldversion < 2026070200) {
        // Resumable historical backfill. All new state
        // (backfill_wm_<entity>, backfill_done_<entity>, backfill_batch,
        // backfill_complete) lives in mdl_config_plugins via get_config/
        // set_config — there is NO new table and no schema change. This block
        // exists only to advance the plugin savepoint so the new code loads.
        upgrade_plugin_savepoint(true, 2026070200, 'local', 'intellistream');
    }

    if ($oldversion < 2026070201) {
        // The collab_sync task is now registered DISABLED by default (db/tasks.php).
        // Moodle re-syncs scheduled tasks for the component on this version
        // bump, applying the new default to any site that has not manually
        // customised the task. No schema change.
        upgrade_plugin_savepoint(true, 2026070201, 'local', 'intellistream');
    }

    if ($oldversion < 2026070302) {
        // LTI role assignment moved to the control webhook (set_lti_role action).
        // The local ltilearnerroles/ltiteacherroles patch, the maintain_lti_roles
        // scheduled task, and the encrypted set_lti_role WS + encryptionkey are
        // removed. Moodle drops the removed scheduled task / WS function /
        // capability automatically on this version bump; defensively clear the now
        // orphaned config values so stale settings do not linger. No schema change.
        unset_config('ltilearnerroles', 'local_intellistream');
        unset_config('ltiteacherroles', 'local_intellistream');
        unset_config('encryptionkey', 'local_intellistream');
        upgrade_plugin_savepoint(true, 2026070302, 'local', 'intellistream');
    }

    if ($oldversion < 2026070900) {
        // Timestamp-less definition tables now ride the fast lanes —
        // per-entity event observers (~1 min, db/events.php) plus per-entity
        // incremental watermark overrides (registry 'wmcol', 15 min). No schema
        // change; the new observers + watermark logic load on this version bump.
        //
        // Seed cdc_wm_<entity> to each append-table's current MAX so the first
        // incremental after upgrade ships only genuine deltas instead of the
        // whole table once (harmless downstream — content-dedup would absorb a
        // full re-ship — but avoids a large one-off pass on busy sites).
        //
        // course_modules is deliberately NOT seeded: leaving its watermark unset
        // makes the first incremental ship all activities once, which surfaces
        // any activities created before the upgrade (the pre-observer backlog) within
        // ~15 min; the row count is small and downstream UPSERT-on-id dedups it.
        $seed = [
            'forum_posts'     => 'modified',
            'chat_messages'   => 'timestamp',
            'attendance_log'  => 'timetaken',
            'lesson_attempts' => 'timeseen',
            'survey_answers'  => 'time',
        ];
        foreach ($seed as $entity => $col) {
            $key = 'cdc_wm_' . $entity;
            if (get_config('local_intellistream', $key) === false) {
                try {
                    if ($dbman->table_exists($entity)) {
                        $max = $DB->get_field_sql('SELECT MAX(' . $col . ') FROM {' . $entity . '}');
                        set_config($key, (int) $max, 'local_intellistream');
                    }
                } catch (\Throwable $e) {
                    // Leave unset -> first incremental ships the full table once
                    // (content-dedup absorbs it). Never fail the upgrade.
                    null;
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026070900, 'local', 'intellistream');
    }

    if ($oldversion < 2026071000) {
        // The local/intellistream:pullexport capability must no longer be a
        // default authenticated-user grant. Emptying the archetype in
        // db/access.php does NOT revoke an already-applied grant on existing
        // sites — core update_capabilities() only applies archetype defaults to
        // NEW capabilities. So explicitly unassign the capability from every
        // role carrying the 'user' archetype (the authenticated-user role).
        foreach (get_archetype_roles('user') as $role) {
            unassign_capability('local/intellistream:pullexport', $role->id);
        }

        // Early installs created the Collaborate tables under their
        // original names (collab_partic / collab_synced). install.xml later
        // renamed them to colpart / colsync but shipped no migration, so those
        // sites still carry the old names — which breaks the collab_sync task
        // and the Privacy provider (both reference colpart/colsync). The column
        // sets are identical, so rename in place when the legacy table exists
        // and the new name does not.
        foreach (['collab_partic' => 'colpart', 'collab_synced' => 'colsync'] as $old => $new) {
            $oldtable = new xmldb_table('local_intellistream_' . $old);
            $newtable = new xmldb_table('local_intellistream_' . $new);
            if ($dbman->table_exists($oldtable) && !$dbman->table_exists($newtable)) {
                $dbman->rename_table($oldtable, 'local_intellistream_' . $new);
            }
        }

        upgrade_plugin_savepoint(true, 2026071000, 'local', 'intellistream');
    }

    if ($oldversion < 2026072000) {
        // The control-plane "Edit datatype config" form can now set a
        // per-datatype Table Type override. Add the nullable `tabletype` column to
        // local_intellistream_config (null = use the derived default). Idempotent.
        $table = new xmldb_table('local_intellistream_config');
        $field = new xmldb_field('tabletype', XMLDB_TYPE_INTEGER, '2', null, null, null, null, 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026072000, 'local', 'intellistream');
    }

    if ($oldversion < 2026072203) {
        // The `bufferdir` setting is now validated on save. A value stored
        // before that validation existed (or written straight to the DB) would
        // make the settings page unsavable until an admin noticed and fixed the
        // field. Such a value is ALREADY ignored at runtime — config::buffer_dir()
        // falls back to the default for anything it rejects — so clearing it
        // changes no behaviour, and it removes any destructive value a site may
        // already be carrying.
        $stored = get_config('local_intellistream', 'bufferdir');
        if (
            is_string($stored) && trim($stored) !== ''
            && \local_intellistream\config::buffer_dir_problem($stored) !== ''
        ) {
            unset_config('bufferdir', 'local_intellistream');
        }
        upgrade_plugin_savepoint(true, 2026072203, 'local', 'intellistream');
    }

    if ($oldversion < 2026073000) {
        // LTI role assignment now always goes through
        // Moodle's role_assign()/role_unassign(), converging on the diff. The
        // `ltiassigndefaultmethod` switch that used to select between that and a
        // silent bulk INSERT no longer has a second option to select, and its
        // setting is gone, so drop the stored value rather than leaving an orphan
        // row in mdl_config_plugins that no code reads.
        unset_config('ltiassigndefaultmethod', 'local_intellistream');

        upgrade_plugin_savepoint(true, 2026073000, 'local', 'intellistream');
    }

    if ($oldversion < 2026073101) {
        // The pull web service used to park a
        // fully-drained buffer file as `*.jsonl.pulled`, and nothing ever deleted
        // it. Neither the disk cap nor the admin status page globbed that suffix,
        // so a site using the documented pull integration grew moodledata without
        // bound while reporting a small buffer. pull_export now deletes on commit;
        // this clears whatever a site already accumulated.
        //
        // Safe to delete unconditionally: a file only reaches `.pulled` after every
        // record in it was returned to the puller, so nothing undelivered is lost.
        try {
            $dir = \local_intellistream\config::buffer_dir();
            foreach (\local_intellistream\buffer::safe_files($dir, ['*.jsonl.pulled']) as $f) {
                @unlink($f);
            }
        } catch (\Throwable $e) {
            // Never fail an upgrade over cleanup. enforce_disk_cap() now counts and
            // evicts `.pulled` files too, so it drains as a backstop either way.
            debugging('local_intellistream: .pulled cleanup skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026073101, 'local', 'intellistream');
    }

    if ($oldversion < 2026080305) {
        // The `IntelliBoard Pro` web service is now declared
        // `restrictedusers => 1`, so membership is an explicit per-user list
        // instead of a consequence of holding the capability.
        //
        // Core rewrites the stored flag from db/services.php on upgrade
        // (external_update_descriptions() in lib/upgradelib.php), and
        // webservice/lib.php then joins {external_services_users} on every
        // call. A site whose puller already has a working token would
        // therefore start being refused the moment this upgrade lands, with
        // nothing in the plugin to explain it.
        //
        // Adopt the users who already hold a token for the service, so the
        // restriction applies to who may be added NEXT rather than retracting
        // access that works today. `validuntil` is left NULL, which is the
        // no-expiry form the core query expects.
        //
        // Written through core's own \webservice::add_ws_authorised_user()
        // rather than an insert into {external_services_users}: that table
        // belongs to core, and core owns the shape of a row in it. The method
        // sets `timecreated` itself, so it is deliberately absent below.
        require_once($CFG->dirroot . '/webservice/lib.php');
        $service = $DB->get_record('external_services', ['shortname' => 'intelliboard_pro'], 'id');
        if ($service) {
            $wsmanager = new \webservice();
            $tokenholders = $DB->get_fieldset_sql(
                'SELECT DISTINCT userid FROM {external_tokens} WHERE externalserviceid = :sid',
                ['sid' => $service->id]
            );
            foreach ($tokenholders as $userid) {
                $exists = $DB->record_exists('external_services_users', [
                    'externalserviceid' => $service->id,
                    'userid'            => $userid,
                ]);
                if (!$exists) {
                    $wsmanager->add_ws_authorised_user((object)[
                        'externalserviceid' => $service->id,
                        'userid'            => $userid,
                        'iprestriction'     => null,
                        'validuntil'        => null,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026080305, 'local', 'intellistream');
    }

    if ($oldversion < 2026080306) {
        // Seed the settings whose declared default in settings.php was never
        // actually written. Core applies declared defaults only during a core
        // install, and the web-UI plugin installer does not, so a site that
        // added this plugin to an existing Moodle and was configured over the
        // control webhook has no stored value for any of them while its own
        // settings page displays them as if it did.
        //
        // The sharp one is `enabled`: settings.php declares 1 and
        // config::enabled() falls back to 0, so such a site shows the master
        // switch ON and captures nothing. `dynamic_discovery_prefixes` is the
        // same shape — the page shows the IntelliCart prefix while
        // dynamic_discovery_service::prefixes() reads an empty string and
        // matches no tables.
        //
        // Only writes keys that are absent, so nothing an administrator or the
        // control plane chose is touched. The list is
        // config::DECLARED_DEFAULTS, shared with db/install.php.
        $seeded = \local_intellistream\config::seed_declared_defaults();
        if ($seeded) {
            // Worth a line in the upgrade output, because seeding `enabled`
            // changes what the plugin does on the next cron run.
            mtrace('local_intellistream: seeded missing setting defaults: ' . implode(', ', $seeded));
        }

        upgrade_plugin_savepoint(true, 2026080306, 'local', 'intellistream');
    }

    if ($oldversion < 2026080308) {
        // Twelve integer columns across the four adapter tables are declared
        // nullable while every reader treats them as non-null, so a NULL reads
        // as a real value rather than as "unknown" — and different readers
        // infer different values from the same NULL.
        //
        // `enabled` is the one that matters: config/index.php casts a NULL to 0
        // and shows the datatype as DISABLED, while config_service reads the
        // same NULL through `?? 1` and keeps exporting it. The admin page says
        // one thing and the exporter does the other. It is backfilled to 1, not
        // 0, because the exporter is the reader that decides whether data
        // actually moves; backfilling 0 would silently stop shipping a datatype
        // the site ships today. Every other column here reads as 0 everywhere,
        // which is also its declared default.
        //
        // `tabletype` is deliberately NOT in this list. It is the one column
        // where NULL is meaningful: exporter.php tests `!== null` to decide
        // between an explicit override and a derived default, so making it
        // NOT NULL would erase that distinction.
        //
        // Lengths move to 10, Moodle's length for a Unix timestamp. Integer
        // lengths 10 and 11 select the same underlying column type on both
        // PostgreSQL and MySQL, so no stored value changes.
        $columnsbytable = [
            'local_intellistream_config' => [
                ['enabled', '1', 1],
                ['discovered', '1', 0],
                ['timecreated', '10', 0],
                ['timemodified', '10', 0],
            ],
            'local_intellistream_logs' => [
                ['timecreated', '10', 0],
            ],
            'local_intellistream_colpart' => [
                ['external_user_id', '10', 0],
                ['first_join_time', '10', 0],
                ['last_left_time', '10', 0],
                ['duration', '10', 0],
                ['rejoins', '10', 0],
                ['timecreated', '10', 0],
            ],
            'local_intellistream_colsync' => [
                ['timesynced', '10', 0],
            ],
        ];

        // The XMLDB manager refuses to alter a column an index depends on, so
        // the two indexes covering a column changed below are dropped first and
        // rebuilt after. colpart's sessionuid_idx is left alone: sessionuid is a
        // char column and is not touched here.
        $logsindex = new xmldb_index('type_timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['type', 'timecreated']);
        $extuserindex = new xmldb_index('extuser_idx', XMLDB_INDEX_NOTUNIQUE, ['external_user_id']);
        $indexes = [
            'local_intellistream_logs' => $logsindex,
            'local_intellistream_colpart' => $extuserindex,
        ];
        foreach ($indexes as $tablename => $index) {
            $table = new xmldb_table($tablename);
            if ($dbman->table_exists($table) && $dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
        }

        foreach ($columnsbytable as $tablename => $columns) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                [$name, $length, $default] = $column;
                $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, $length, null, XMLDB_NOTNULL, null, $default);
                if (!$dbman->field_exists($table, $field)) {
                    continue;
                }
                // Existing NULLs have to go before the column can refuse them.
                $DB->set_field_select($tablename, $name, $default, $name . ' IS NULL');
                $dbman->change_field_precision($table, $field);
                $dbman->change_field_default($table, $field);
                $dbman->change_field_notnull($table, $field);
            }
        }

        foreach ($indexes as $tablename => $index) {
            $table = new xmldb_table($tablename);
            if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        upgrade_plugin_savepoint(true, 2026080308, 'local', 'intellistream');
    }

    if ($oldversion < 2026080310) {
        // The "Display in custom menu" toggle is gone. It worked by appending the
        // dashboard link to $CFG->custommenuitems from a navigation callback on
        // every page render, because core builds the theme menu from that value
        // and exposes no per-request injection API. The link is still added to the
        // navigation tree itself, and a site that wants it in the menu bar can add
        // it under Appearance > Advanced theme settings > Custom menu items.
        //
        // Drop the stored value so it is not left behind in config_plugins with
        // nothing reading it.
        unset_config('custommenuitem', 'local_intellistream');

        upgrade_plugin_savepoint(true, 2026080310, 'local', 'intellistream');
    }

    return true;
}
