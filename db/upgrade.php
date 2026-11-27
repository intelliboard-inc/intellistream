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
 * Each `if ($oldversion < N)` block applies its change and then calls
 * upgrade_plugin_savepoint() to record progress.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * local_intellistream upgrade callback.
 *
 * Runs every step whose version is greater than $oldversion. A fresh install
 * creates the tables from install.xml and does not run these steps.
 *
 * @param int $oldversion Previous plugin version (from version.php).
 * @return bool
 */
function xmldb_local_intellistream_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();
    $installxml = __DIR__ . '/install.xml';

    // Decide the dynamicdiscoveryexport setting (whole-row export of discovered
    // tables) before any step runs and outside every savepoint. Later steps call
    // config::seed_declared_defaults(), which would otherwise write 0 first. The
    // value is written at once, so a resumed upgrade finds it and keeps it;
    // config_service::seed_discovery_export() does nothing for a site already at
    // 2026100100 or later. It is not an upgrade step, so it has no version test.
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
        // Blackboard Collaborate attendance: per-user attendance rows and the sync tracker.
        if (!$dbman->table_exists('local_intellistream_colpart')) {
            $dbman->install_one_table_from_xmldb_file($installxml, 'local_intellistream_colpart');
        }
        if (!$dbman->table_exists('local_intellistream_colsync')) {
            $dbman->install_one_table_from_xmldb_file($installxml, 'local_intellistream_colsync');
        }
        upgrade_plugin_savepoint(true, 2026060101, 'local', 'intellistream');
    }

    if ($oldversion < 2026062200) {
        // Mark which local_intellistream_config rows were registered by the
        // dynamic-table discovery service and which were added by an admin. Drives
        // whole-table (`*`) column selection for discovered tables without touching
        // the curated built-in registry entries.
        $table = new xmldb_table('local_intellistream_config');
        $field = new xmldb_field('discovered', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026062200, 'local', 'intellistream');
    }

    if ($oldversion < 2026070200) {
        // Resumable historical backfill. Its state (backfill_wm_<entity>,
        // backfill_done_<entity>, backfill_batch, backfill_complete) is kept in
        // mdl_config_plugins through get_config()/set_config(), so there is no
        // schema change. This step only advances the plugin savepoint.
        upgrade_plugin_savepoint(true, 2026070200, 'local', 'intellistream');
    }

    if ($oldversion < 2026070201) {
        // The collab_sync task is disabled by default (db/tasks.php). Moodle
        // re-reads the component's scheduled tasks on this version change and
        // applies the default to any site that has not customised the task.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026070201, 'local', 'intellistream');
    }

    if ($oldversion < 2026070302) {
        // LTI roles are assigned through the control webhook (set_lti_role action).
        // Delete the stored values of ltilearnerroles, ltiteacherroles and
        // encryptionkey, which no code reads. Moodle itself removes a scheduled
        // task, web service function or capability that the plugin does not
        // declare. No schema change.
        unset_config('ltilearnerroles', 'local_intellistream');
        unset_config('ltiteacherroles', 'local_intellistream');
        unset_config('encryptionkey', 'local_intellistream');
        upgrade_plugin_savepoint(true, 2026070302, 'local', 'intellistream');
    }

    if ($oldversion < 2026070900) {
        // Tables without a timestamp are captured by per-entity event observers
        // (db/events.php) and per-entity incremental watermark columns (registry
        // 'wmcol'). No schema change.
        //
        // Seed cdc_wm_<entity> to each append table's current MAX, so the first
        // incremental after the upgrade ships only new rows and not the whole table
        // (downstream deduplication would absorb a full re-ship, but the pass is
        // large on a busy site).
        //
        // course_modules is not seeded: with no watermark, the first incremental
        // ships all activities once, including those created before the upgrade.
        // The row count is small and downstream upserts on id remove duplicates.
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
                    // Leave unset: the first incremental ships the full table once
                    // (downstream deduplication absorbs it). Do not fail the upgrade.
                    null;
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026070900, 'local', 'intellistream');
    }

    if ($oldversion < 2026071000) {
        // The local/intellistream:pullexport capability is not a default grant for
        // authenticated users. An empty archetype list in db/access.php does not
        // revoke a grant already applied on an existing site, because core
        // update_capabilities() applies archetype defaults only to new
        // capabilities. So unassign the capability from every role with the
        // 'user' archetype (the authenticated-user role).
        foreach (get_archetype_roles('user') as $role) {
            unassign_capability('local/intellistream:pullexport', $role->id);
        }

        // Some sites have the Collaborate tables under the names collab_partic
        // and collab_synced. The collab_sync task and the privacy provider use
        // colpart and colsync. The columns are identical, so rename in place when
        // the old table exists and the new name does not.
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
        // Add the nullable `tabletype` column to local_intellistream_config: a
        // per-datatype table type override set from the IntelliBoard service's
        // datatype configuration form (null = use the derived default).
        $table = new xmldb_table('local_intellistream_config');
        $field = new xmldb_field('tabletype', XMLDB_TYPE_INTEGER, '2', null, null, null, null, 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026072000, 'local', 'intellistream');
    }

    if ($oldversion < 2026072203) {
        // The `bufferdir` setting is validated on save. A stored value that fails
        // that validation (for example one written straight to the DB) makes the
        // settings page impossible to save until an admin fixes the field.
        // config::buffer_dir() already ignores such a value and uses the default,
        // so clearing it changes no runtime behaviour.
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
        // LTI role assignment always uses Moodle's role_assign()/role_unassign().
        // No code reads the `ltiassigndefaultmethod` setting, so delete its stored
        // value from mdl_config_plugins.
        unset_config('ltiassigndefaultmethod', 'local_intellistream');

        upgrade_plugin_savepoint(true, 2026073000, 'local', 'intellistream');
    }

    if ($oldversion < 2026073101) {
        // Delete leftover `*.jsonl.pulled` buffer files. pull_export removes a fully
        // delivered file on commit, but a site can still hold files with this
        // suffix, and they would grow moodledata without limit.
        //
        // Safe to delete unconditionally: a file only reaches `.pulled` after every
        // record in it was returned to the puller, so nothing undelivered is lost.
        try {
            $dir = \local_intellistream\config::buffer_dir();
            foreach (\local_intellistream\buffer::safe_files($dir, ['*.jsonl.pulled']) as $f) {
                @unlink($f);
            }
        } catch (\Throwable $e) {
            // Do not fail the upgrade over cleanup. The shipper also removes `.pulled`
            // files on every run.
            debugging('local_intellistream: .pulled cleanup skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026073101, 'local', 'intellistream');
    }

    if ($oldversion < 2026080305) {
        // The IntelliBoard Pro service is restricted to listed users. Keep the
        // access of users who already hold a token for it.
        \local_intellistream\local\upgrade_helper::authorise_token_holders();

        upgrade_plugin_savepoint(true, 2026080305, 'local', 'intellistream');
    }

    if ($oldversion < 2026080306) {
        // Store each setting whose declared default in settings.php has no stored
        // value. Core writes declared defaults only during a core install, not
        // from the web plugin installer, so a site that added this plugin to an
        // existing Moodle and was configured over the control webhook can have no
        // stored values while its settings page shows the defaults.
        //
        // This matters most for `enabled`: settings.php declares 1 and
        // config::enabled() falls back to 0, so such a site shows the switch on
        // and captures nothing. `dynamic_discovery_prefixes` is similar: the page
        // shows the default prefix while dynamic_discovery_service::prefixes()
        // reads an empty string and matches no tables.
        //
        // Only absent keys are written, so values set by an administrator or the
        // IntelliBoard service are kept. The list is config::DECLARED_DEFAULTS,
        // shared with db/install.php.
        $seeded = \local_intellistream\config::seed_declared_defaults();
        if ($seeded) {
            // Worth a line in the upgrade output, because seeding `enabled`
            // changes what the plugin does on the next cron run.
            mtrace('local_intellistream: seeded missing setting defaults: ' . implode(', ', $seeded));
        }

        upgrade_plugin_savepoint(true, 2026080306, 'local', 'intellistream');
    }

    if ($oldversion < 2026080308) {
        // Twelve integer columns in the four plugin tables are nullable, but every
        // reader treats them as non-null and different readers infer different
        // values from a NULL. Make them NOT NULL with a default.
        //
        // `enabled` is backfilled to 1, not 0: config/index.php shows a NULL as
        // disabled, but config_service reads it through `?? 1` and keeps exporting.
        // The exporter decides whether data moves, so 1 keeps what the site exports.
        // Every other column reads as 0 everywhere, which is also its default.
        //
        // `tabletype` is not in this list: exporter.php tests `!== null` to tell an
        // explicit override from a derived default, so NULL has meaning there.
        //
        // Lengths become 10, Moodle's length for a Unix timestamp. Integer lengths
        // 10 and 11 select the same column type on PostgreSQL and MySQL, so no
        // stored value changes.
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
        // A logs table installed by step 2026052001 from the current install.xml also
        // has the index on timecreated alone. It is dropped here so that the column
        // can change; step 2026112700 adds it again.
        $logstable = new xmldb_table('local_intellistream_logs');
        $logstimeindex = new xmldb_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if ($dbman->table_exists($logstable) && $dbman->index_exists($logstable, $logstimeindex)) {
            $dbman->drop_index($logstable, $logstimeindex);
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
        // No change: the stored "Display in custom menu" value is kept, because
        // config::lti_custom_menu_item() reads it (see step 2026082000).

        upgrade_plugin_savepoint(true, 2026080310, 'local', 'intellistream');
    }

    if ($oldversion < 2026082000) {
        // The "Display in custom menu" setting, off by default. Boost on Moodle 4.x
        // renders no flat navigation, so the navigation-tree node has no surface
        // there and the theme menu bar is the only place the dashboard link can
        // appear. The link is added in memory for the request only, on every
        // supported release; see \local_intellistream\helpers\custom_menu_helper.
        //
        // A site whose stored value was deleted by an earlier form of step
        // 2026080310 has lost its choice and is told so below; a site that still
        // has the key keeps it and is told nothing. Seeding makes the key present,
        // like every other declared default; it writes only absent keys, so the
        // key is read first.
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
        // The incremental lane has a second, id-keyset window for "clockless"
        // rows: rows whose watermark column is NULL or 0 (for example an ungraded
        // grade_grades row), which `wmcol > :since` never matches.
        //
        // Where the daily full snapshot is healthy it has delivered the existing
        // rows, so seed each table's id watermark to its current MAX(id) and the
        // first incremental ships only new rows. Where the snapshot is disabled or
        // stalled the backlog may never have been delivered, and seeding would lose
        // it, so the watermark stays unset and the lane's chunking
        // (exporter::CLOCKLESS_ID_CHUNK) walks the backlog in bounded passes.
        //
        // The entity => watermark-column map is written out here, not read from
        // the registry, so this step does the same thing if the registry changes.
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
            // Seed only into a cdc_state document that already exists.
            //
            // exporter::cdc_state() adopts the per-entity cdc_wm_<entity> keys only
            // when the document is absent or corrupt. A document written here would
            // make that adoption unreachable: every registry entity would read a
            // timestamp watermark of 0 and re-ship in full on the timestamp lane,
            // which has no chunking. Rows that buffer::append() refuses at the disk
            // cap are counted as `dropped`, which does not block `complete`, so the
            // watermark would advance over rows that were never shipped. Step
            // 2026070900 above writes five of those keys in the same upgrade run.
            //
            // With no document, the clockless lane starts unseeded and walks the
            // backlog in bounded passes, as when the snapshot is not healthy.
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
        // Buffer directories are created with the site's directory permissions
        // (make_writable_directory). Apply them to existing buffer directories too:
        // a directory with another mode can leave the cron shipper unable to rename
        // or remove files in it when the web server and cron run as different OS
        // users. Best effort: a directory this process may not chmod is left as it
        // is, and the upgrade does not fail.
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
        // Tables for the verification sweep that runs in the 15-minute task: block
        // fingerprints (blkhash) and per-entity pass state (sweep).
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

        // Moodle deletes the row of the removed daily snapshot task when it resets
        // this plugin's tasks after the upgrade. The 15-minute task does all table
        // exports, so a site where it is disabled captures no entity data until it
        // is enabled; warn such a site.
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
        // The capture path decides on the buffer measurement that the shipper
        // publishes, not by sizing the buffer directory itself. Write the first
        // measurement here, so a site already at its cap is refused from the first
        // request after the upgrade, not admitted with no measurement at all.
        //
        // Only the measurement is written, not its lock: the web server user must be
        // able to open the lock, and an upgrade run as another user (root) would own
        // it. A measurement written by another user is harmless: the web server user
        // reads it, or measures for itself if it cannot, and replaces it by rename on
        // the next publish. In a directory with the sticky bit set only a file's
        // owner may replace it, so an upgrade run there by a user who does not own
        // the directory writes nothing and the first shipper run publishes it.
        try {
            $dir = \local_intellistream\config::buffer_dir();
            $st = is_dir($dir) ? @stat($dir) : false;
            $foreign = $st !== false && ($st['mode'] & 01000) && function_exists('posix_geteuid')
                && posix_geteuid() !== (int)$st['uid'];
            if ($st !== false && !$foreign) {
                \local_intellistream\buffer::measure($dir);
            }
        } catch (\Throwable $e) {
            // Do not fail the upgrade over this: the first shipper run publishes it.
            debugging('local_intellistream: first buffer measurement skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // The dynamicdiscoveryexport value is decided at the top of this function,
        // before any step, so the seeding in other steps cannot pre-empt it.

        upgrade_plugin_savepoint(true, 2026100100, 'local', 'intellistream');
    }

    if ($oldversion < 2026102900) {
        // Tables for the login totals that the count_userlogins task keeps. Its first
        // run counts the whole standard log in short blocks; until that count ends,
        // the reporting warehouse keeps the totals it already has.
        foreach (['local_intellistream_ulogin', 'local_intellistream_ulstate'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file($installxml, $name);
            }
        }

        // The verification sweep does not send the userlogins entity, so delete its
        // sweep position.
        $DB->delete_records('local_intellistream_sweep', ['entity' => 'userlogins']);

        upgrade_plugin_savepoint(true, 2026102900, 'local', 'intellistream');
    }

    if ($oldversion < 2026112700) {
        // Collaborate attendance rows that belong to no Moodle user cannot be
        // exported or erased with a user, so they are deleted.
        if ($dbman->table_exists('local_intellistream_colpart')) {
            \local_intellistream\local\upgrade_helper::remove_invalid_attendance();
        }

        // The log cleanup task deletes by age, so the logs table gets an index on
        // timecreated alone.
        $logstable = new xmldb_table('local_intellistream_logs');
        $logstimeindex = new xmldb_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if ($dbman->table_exists($logstable) && !$dbman->index_exists($logstable, $logstimeindex)) {
            $dbman->add_index($logstable, $logstimeindex);
        }

        // Stores the default log lifetime on a site that has no value yet.
        \local_intellistream\config::seed_declared_defaults();

        upgrade_plugin_savepoint(true, 2026112700, 'local', 'intellistream');
    }

    return true;
}
