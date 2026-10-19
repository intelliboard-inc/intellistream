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

    // Whole-row export of discovered (InForm) tables became opt-in
    // (dynamicdiscoveryexport) in 2026100100. Decided HERE, before any step runs and
    // outside every savepoint, because later steps seed each declared default
    // (config::seed_declared_defaults()) and would otherwise write 0 first; and
    // written at once, so an upgrade interrupted and resumed finds the value it
    // decided and keeps it (see config_service::seed_discovery_export(), which does
    // nothing for a site already at 2026100100 or later). Deliberately not wrapped
    // in a version test of its own: it is no upgrade step and has no savepoint.
    \local_intellistream\services\config_service::seed_discovery_export((int)$oldversion);

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
            // Never fail an upgrade over cleanup. The shipper removes `.pulled` files
            // on every run too, so they drain as a backstop either way.
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
        // This step used to delete the stored "Display in custom menu" value, on the
        // reading that the toggle was gone for good. It is not: 2026082000 below
        // restores it (config::lti_custom_menu_item()), so the value is kept, and a site
        // upgrading across both steps keeps the choice it had.

        upgrade_plugin_savepoint(true, 2026080310, 'local', 'intellistream');
    }

    if ($oldversion < 2026082000) {
        // The "Display in custom menu" toggle is available again, off by default.
        //
        // 2026080310 above removed it, on the reading that the navigation-tree node
        // covered the same ground. It does not: Boost 4.x renders no flat navigation
        // at all, so that node has no surface on 4.0+ and the theme menu bar was the
        // only place the dashboard link could appear. It is restored on a mechanism
        // described in full in
        // \local_intellistream\helpers\custom_menu_helper.
        //
        // Only a site whose stored value an earlier 0.9.26 upgrade deleted (the old
        // 2026080310 step did) has lost its choice; a site that still has the key
        // (an 0.9.27 build, or one crossing both steps now) kept it, and is told
        // nothing. Seeding is what makes the key present rather than merely
        // defaulted, matching every other declared default; it only writes absent
        // keys, so it is read first.
        $hadmenu = get_config('local_intellistream', 'custommenuitem') !== false;
        $seeded = \local_intellistream\config::seed_declared_defaults();
        if ($seeded) {
            mtrace('local_intellistream: seeded missing setting defaults: ' . implode(', ', $seeded));
        }
        if (!$hadmenu) {
            mtrace('local_intellistream: "Display in custom menu" is available again under '
                . 'LTI Settings, off by default. A site that had it enabled before 0.9.26 '
                . 'must re-enable it; a site using the Appearance > Custom menu items '
                . 'workaround should remove that entry, as it is visible to every user.');
        }

        upgrade_plugin_savepoint(true, 2026082000, 'local', 'intellistream');
    }

    if ($oldversion < 2026090100) {
        // The incremental lane gains a second, id-keyset window for
        // "clockless" rows — rows whose watermark column is NULL or 0, which
        // `wmcol > :since` can never match. Moodle makes plenty of them: an
        // ungraded grade_grades row materialised at enrolment has a NULL
        // timemodified. Until now those reached the warehouse only via the
        // daily full snapshot.
        //
        // Seed the new per-entity id watermark to each table's current MAX(id)
        // so the first incremental after this upgrade ships only genuinely new
        // clockless rows instead of the entire existing backlog (~160k rows on
        // one production tenant) in a single pass.
        //
        // But ONLY where the daily full snapshot is demonstrably healthy. On a
        // site where it is disabled or stalled, the backlog was never delivered
        // at all, and seeding would declare it delivered — turning a latency
        // bug into permanent data loss. There we leave the watermark unset, and
        // the lane's own chunking (exporter::CLOCKLESS_ID_CHUNK) walks the
        // backlog in bounded passes instead.
        //
        // The entity => watermark-column map is spelled out here rather than
        // read from the registry, so this block keeps doing what it did on the
        // day it shipped even if the registry later changes.
        $seed = [
            'grade_grades'              => 'timemodified',
            'course_modules_completion' => 'timemodified',
            'grade_items'               => 'timemodified',
            'customfield_data'          => 'timemodified',
            'scorm'                     => 'timemodified',
            'attendance'                => 'timemodified',
            'lesson_pages'              => 'timemodified',
        ];

        // Moodle stores the classname without a leading backslash; accept both.
        $snapshothealthy = false;
        try {
            [$insql, $inparams] = $DB->get_in_or_equal([
                'local_intellistream\\task\\daily_snapshot',
                '\\local_intellistream\\task\\daily_snapshot',
            ]);
            $task = $DB->get_record_select(
                'task_scheduled',
                "classname {$insql}",
                $inparams,
                '*',
                IGNORE_MULTIPLE
            );
            $snapshothealthy = $task
                && (int)$task->disabled === 0
                && (int)$task->lastruntime > (time() - (48 * HOURSECS));
        } catch (\Throwable $e) {
            $snapshothealthy = false;
        }

        if ($snapshothealthy) {
            // Seed ONLY into a document that already exists.
            //
            // exporter::cdc_state() adopts the legacy per-entity cdc_wm_<entity>
            // keys only when the document is absent or corrupt — a valid one
            // returns early, before the adoption scan, and cdc_state_save()
            // clears those keys only when that scan set `migrated`. Writing a
            // document here where none existed would therefore make adoption
            // unreachable for ever: every one of the ~179 registry entities
            // would read a timestamp watermark of 0 and re-ship in full down
            // the TIMESTAMP lane, which has no chunking. That is the same
            // data-safety problem CLOCKLESS_ID_CHUNK exists to prevent —
            // buffer::append() refuses at the disk cap, export_entity() counts
            // the refusal as `dropped`, and `dropped` deliberately does not
            // block `complete`, so the watermark advances over rows that were
            // never shipped.
            //
            // The 2026070900 block above still writes five of those keys, so a
            // site jumping straight from < 2026070900 to here would seed and
            // then strand them in the same run.
            //
            // No document means the clockless lane simply starts unseeded, which
            // is already the $snapshothealthy === false behaviour: the chunked
            // lane walks the backlog in bounded passes.
            $raw = get_config('local_intellistream', 'cdc_state');
            $doc = null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                // A corrupt document is left alone for the same reason.
                $doc = is_array($decoded) ? $decoded : null;
            }
            if ($doc !== null) {
                $idwm = isset($doc['idwm']) && is_array($doc['idwm']) ? $doc['idwm'] : [];
                foreach ($seed as $entity => $unusedcol) {
                    if (array_key_exists($entity, $idwm)) {
                        continue; // Already positioned; never move it backwards.
                    }
                    try {
                        if (!$dbman->table_exists($entity)) {
                            continue;
                        }
                        $max = $DB->get_field_sql('SELECT MAX(id) FROM {' . $entity . '}');
                        $idwm[$entity] = (int)$max;
                    } catch (\Throwable $e) {
                        // Leave unset -> the chunked lane walks this entity's
                        // backlog. Never fail the upgrade.
                        null;
                    }
                }
                $doc['idwm'] = $idwm;
                if (!isset($doc['wm']) || !is_array($doc['wm'])) {
                    $doc['wm'] = [];
                }
                $encoded = json_encode($doc);
                if ($encoded !== false) {
                    set_config('cdc_state', $encoded, 'local_intellistream');
                }
                mtrace('local_intellistream: clockless lane seeded to the current max id '
                    . '(daily snapshot is healthy, so the existing backlog is already delivered).');
            } else {
                mtrace('local_intellistream: clockless lane NOT seeded — no usable cdc_state '
                    . 'document yet, so the legacy per-entity watermarks are still waiting to '
                    . 'be adopted. The lane starts from zero and walks in bounded passes.');
            }
        } else {
            mtrace('local_intellistream: clockless lane NOT seeded — the daily snapshot is '
                . 'disabled or has not run in 48h, so its backlog may never have shipped. '
                . 'The lane will walk it in bounded passes instead.');
        }

        upgrade_plugin_savepoint(true, 2026090100, 'local', 'intellistream');
    }

    if ($oldversion < 2026092400) {
        // Buffer directories are now created with the site's directory permissions
        // (make_writable_directory), not a fixed 0750. Directories an earlier release
        // already created keep their old mode, which on a split web/cron OS-user
        // install leaves the cron shipper unable to rename or remove files in them,
        // so bring the existing ones into line. Best effort: a directory this process
        // may not chmod is left as it is, and never fails the upgrade.
        $dirs = array_unique(array_merge(
            [\local_intellistream\config::buffer_root()],
            \local_intellistream\config::buffer_dirs()
        ));
        foreach ($dirs as $dir) {
            if ($dir !== '' && is_dir($dir) && !is_link($dir)) {
                @chmod($dir, $CFG->directorypermissions);
            }
        }
        upgrade_plugin_savepoint(true, 2026092400, 'local', 'intellistream');
    }

    if ($oldversion < 2026092500) {
        // The verification sweep inside the 15-minute task replaces the daily full
        // snapshot: fingerprints (blkhash) and per-entity pass state (sweep).
        $table = new xmldb_table('local_intellistream_blkhash');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('fingerprint', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('entity', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('part', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('passstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('blocks', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('fp_entity_kind_part_uix', XMLDB_INDEX_UNIQUE, ['fingerprint', 'entity', 'kind', 'part']);
        $table->add_index('entity_idx', XMLDB_INDEX_NOTUNIQUE, ['entity']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_intellistream_sweep');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('entity', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        foreach (['passgen', 'passstart'] as $f) {
            $table->add_field($f, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        }
        $table->add_field('scanstarted', XMLDB_TYPE_CHAR, '32', null, null, null, null);
        $table->add_field('passbatch', XMLDB_TYPE_CHAR, '36', null, null, null, null);
        foreach (['nextblock', 'pageno', 'idcount'] as $f) {
            $table->add_field($f, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        }
        $table->add_field('pagesok', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('clkstate', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        foreach (
            ['emittedend', 'lastpassstart', 'lastcomplete', 'pendstart', 'pendend', 'failures',
                'parkeduntil'] as $f
        ) {
            $table->add_field($f, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        }
        $table->add_field('lasterror', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('entity_uix', XMLDB_INDEX_UNIQUE, ['entity']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // The daily task is removed; Moodle drops its row when it resets this plugin's
        // tasks after this upgrade. The 15-minute task now does all the work, so a site
        // that had it disabled captures no entity data at all until it is re-enabled.
        $refresh = $DB->get_record_select('task_scheduled', 'classname = :a OR classname = :b', [
            'a' => '\\local_intellistream\\task\\refresh_entities',
            'b' => 'local_intellistream\\task\\refresh_entities',
        ], 'disabled', IGNORE_MULTIPLE);
        if ($refresh && (int)$refresh->disabled) {
            mtrace('local_intellistream: WARNING — "Refresh bulk entity snapshots" is DISABLED on this site. '
                . 'It is now the only task that exports tables (the daily full snapshot task was removed), '
                . 'so enable it in Site administration > Server > Scheduled tasks.');
        }

        // Upgrades are never held for the historical backfill (only new installs are).
        \local_intellistream\config::seed_declared_defaults();

        upgrade_plugin_savepoint(true, 2026092500, 'local', 'intellistream');
    }

    if ($oldversion < 2026100100) {
        // The capture path now decides on the buffer measurement the shipper publishes
        // instead of sizing the buffer directory itself. Write the first one here, so a
        // site that is already at its cap when it upgrades is refused on a measurement
        // from the first request on, rather than admitted on no measurement at all.
        //
        // Only the measurement is written: not its lock, which the web server user
        // must be able to open and which an upgrade run as another user (root) would
        // otherwise own. A measurement written by another user is harmless: the web
        // server user reads it, or, if it cannot, measures for itself, and either way
        // replaces it by rename on the next publish. The one exception is a directory
        // with the sticky bit set, where only a file's owner may replace it: an upgrade
        // run there by a user who does not own the directory writes nothing, and the
        // first shipper run publishes the measurement instead.
        //
        // What an earlier build of this measurement (a patched 0.9.27) left needs nothing here: its
        // measurement (format 1) is ignored and replaced by this one, its lock and
        // per-worker pointers have the names this release uses, and its temporaries
        // are reclaimed by the shipper as residue.
        try {
            $dir = \local_intellistream\config::buffer_dir();
            $st = is_dir($dir) ? @stat($dir) : false;
            $foreign = $st !== false && ($st['mode'] & 01000) && function_exists('posix_geteuid')
                && posix_geteuid() !== (int)$st['uid'];
            if ($st !== false && !$foreign) {
                \local_intellistream\buffer::measure($dir);
            }
        } catch (\Throwable $e) {
            // Never fail an upgrade over this: the first shipper run publishes it.
            debugging('local_intellistream: first buffer measurement skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // The dynamicdiscoveryexport value for this release is decided at the top of
        // this function, before any step, so the seeding in earlier steps cannot
        // pre-empt it.

        upgrade_plugin_savepoint(true, 2026100100, 'local', 'intellistream');
    }

    return true;
}
