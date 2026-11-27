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
 * Privacy provider for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * The plugin (a) transmits Moodle event/entity payloads — which may contain
 * personal data — to an externally configured object-storage endpoint,
 * (b) stores Blackboard Collaborate attendance in its own table
 * local_intellistream_colpart, (c) stages every captured record in an on-disk
 * JSONL buffer under $CFG->dataroot until the shipper drains it, and (d) keeps each
 * user's login count in local_intellistream_ulogin.
 *
 * colpart is keyed by the Moodle user id (external_user_id), so it supports full
 * export/delete. Every export, delete and lookup path includes the staged buffer records.
 *
 * Limit: a buffer file that may still have a writer (its PID runs on this host, it was
 * written in the last two minutes, or another host sharing the directory wrote it) is
 * not rewritten, as that would corrupt it. It ships within minutes and is counted in
 * the `skipped` total; every other file is rewritten in place.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe what personal data the plugin stores and/or exports off-site.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'intellistream_events',
            [
                'userid'        => 'privacy:metadata:intellistream_events:userid',
                'relateduserid' => 'privacy:metadata:intellistream_events:relateduserid',
                'eventdata'     => 'privacy:metadata:intellistream_events:eventdata',
                'client_ip'     => 'privacy:metadata:intellistream_events:client_ip',
                'user_agent'    => 'privacy:metadata:intellistream_events:user_agent',
            ],
            'privacy:metadata:intellistream_events'
        );
        $collection->add_external_location_link(
            'intellistream_dwell',
            [
                'userid'     => 'privacy:metadata:intellistream_dwell:userid',
                'timespent'  => 'privacy:metadata:intellistream_dwell:timespent',
                'url'        => 'privacy:metadata:intellistream_dwell:url',
                'client_ip'  => 'privacy:metadata:intellistream_dwell:client_ip',
                'user_agent' => 'privacy:metadata:intellistream_dwell:user_agent',
            ],
            'privacy:metadata:intellistream_dwell'
        );
        $collection->add_external_location_link(
            'intellistream_entities',
            // Declared field by field for the sensitive data classes (message bodies,
            // forum posts, chat text, payment records) rather than as one generic
            // "table rows" entry, so the rendered privacy registry shows that they
            // leave the site. These names are disclosure categories, not literal
            // column names.
            [
                'entitydata'         => 'privacy:metadata:intellistream_entities:entitydata',
                'useridentity'       => 'privacy:metadata:intellistream_entities:useridentity',
                'profilefields'      => 'privacy:metadata:intellistream_entities:profilefields',
                'filemetadata'       => 'privacy:metadata:intellistream_entities:filemetadata',
                'logstorewindow'     => 'privacy:metadata:intellistream_entities:logstorewindow',
                'usercontent'        => 'privacy:metadata:intellistream_entities:usercontent',
                'messagemeta'        => 'privacy:metadata:intellistream_entities:messagemeta',
                'messagecontent'     => 'privacy:metadata:intellistream_entities:messagecontent',
                'forumposts'         => 'privacy:metadata:intellistream_entities:forumposts',
                'chatmessages'       => 'privacy:metadata:intellistream_entities:chatmessages',
                'paypaltransactions' => 'privacy:metadata:intellistream_entities:paypaltransactions',
                'commercedata'       => 'privacy:metadata:intellistream_entities:commercedata',
                'fullrows'           => 'privacy:metadata:intellistream_entities:fullrows',
            ],
            'privacy:metadata:intellistream_entities'
        );
        // Error/exception capture. Declared separately from `intellistream_events`
        // so the rendered privacy registry shows that a failed login sends the
        // account's user id and the requested URL off-site.
        $collection->add_external_location_link(
            'intellistream_exceptions',
            [
                'userid'       => 'privacy:metadata:intellistream_exceptions:userid',
                'url'          => 'privacy:metadata:intellistream_exceptions:url',
                'errorclass'   => 'privacy:metadata:intellistream_exceptions:errorclass',
                'errormessage' => 'privacy:metadata:intellistream_exceptions:errormessage',
                'stacktrace'   => 'privacy:metadata:intellistream_exceptions:stacktrace',
            ],
            'privacy:metadata:intellistream_exceptions'
        );
        $collection->add_external_location_link(
            'intellistream_lti',
            [
                'userid'    => 'privacy:metadata:intellistream_lti:userid',
                'email'     => 'privacy:metadata:intellistream_lti:email',
                'firstname' => 'privacy:metadata:intellistream_lti:firstname',
                'lastname'  => 'privacy:metadata:intellistream_lti:lastname',
                'fullname'  => 'privacy:metadata:intellistream_lti:fullname',
                'username'  => 'privacy:metadata:intellistream_lti:username',
            ],
            'privacy:metadata:intellistream_lti'
        );

        // The on-disk JSONL buffer under $CFG->dataroot, which stages every record
        // above until the shipper drains it (normally within about a minute).
        //
        // Declared so the privacy policy shows that the data is staged locally
        // before transmission; export_user_data() and the delete paths cover it.
        // The metadata API has no "local file storage" type, so
        // add_external_location_link is the closest fit; the summary string says
        // that this is on-disk staging inside moodledata, not a third party.
        $collection->add_external_location_link(
            'intellistream_buffer',
            [
                'userid'     => 'privacy:metadata:intellistream_buffer:userid',
                'client_ip'  => 'privacy:metadata:intellistream_buffer:client_ip',
                'user_agent' => 'privacy:metadata:intellistream_buffer:user_agent',
                'url'        => 'privacy:metadata:intellistream_buffer:url',
                'eventdata'  => 'privacy:metadata:intellistream_buffer:eventdata',
            ],
            'privacy:metadata:intellistream_buffer'
        );

        // The pull export web service returns staged records to an authorised
        // web service account instead of object storage.
        $collection->add_external_location_link(
            'intellistream_pull',
            [
                'records' => 'privacy:metadata:intellistream_pull:records',
            ],
            'privacy:metadata:intellistream_pull'
        );

        // Local table: Blackboard Collaborate per-user session attendance.
        $collection->add_database_table('local_intellistream_colpart', [
            'external_user_id' => 'privacy:metadata:local_intellistream_colpart:external_user_id',
            'useruid'          => 'privacy:metadata:local_intellistream_colpart:useruid',
            'display_name'     => 'privacy:metadata:local_intellistream_colpart:display_name',
            'role'             => 'privacy:metadata:local_intellistream_colpart:role',
            'sessionuid'       => 'privacy:metadata:local_intellistream_colpart:sessionuid',
            'first_join_time'  => 'privacy:metadata:local_intellistream_colpart:first_join_time',
            'last_left_time'   => 'privacy:metadata:local_intellistream_colpart:last_left_time',
            'duration'         => 'privacy:metadata:local_intellistream_colpart:duration',
        ], 'privacy:metadata:local_intellistream_colpart');

        // Local table: each user's login count, sent as the `userlogins` entity.
        $collection->add_database_table(\local_intellistream\userlogins::TABLE, [
            'userid' => 'privacy:metadata:local_intellistream_ulogin:userid',
            'logins' => 'privacy:metadata:local_intellistream_ulogin:logins',
        ], 'privacy:metadata:local_intellistream_ulogin');

        // Local table: verification-sweep fingerprints, derived from table rows.
        $collection->add_database_table('local_intellistream_blkhash', [
            'entity' => 'privacy:metadata:local_intellistream_blkhash:entity',
            'blocks' => 'privacy:metadata:local_intellistream_blkhash:blocks',
        ], 'privacy:metadata:local_intellistream_blkhash');

        return $collection;
    }

    /**
     * The colpart rows, the login totals and the buffer are site-wide, so a user
     * with an attendance row, a login total or a staged record has data in the
     * system context.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if (
            $DB->record_exists('local_intellistream_colpart', ['external_user_id' => $userid])
                || $DB->record_exists(\local_intellistream\userlogins::TABLE, ['userid' => $userid])
        ) {
            $contextlist->add_system_context();
            return $contextlist;
        }

        // The buffer is site-wide too, so any staged record for this user also puts
        // their data in the system context. Checked second because it
        // reads files: if a table already established the context there
        // is nothing to gain from scanning.
        if (\local_intellistream\local\buffer_privacy::has_user_records($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * List the users who have attendance rows, a login total or a staged record
     * (system context only).
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql(
            'external_user_id',
            "SELECT DISTINCT external_user_id
               FROM {local_intellistream_colpart}
              WHERE external_user_id > 0",
            []
        );
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . \local_intellistream\userlogins::TABLE . '}', []);

        // Plus anyone with a record still staged in the buffer. Without
        // this, a user whose only personal data is an unshipped buffer record would
        // be absent from the userlist, and delete_data_for_users() would never be
        // asked to erase them.
        $buffered = \local_intellistream\local\buffer_privacy::user_ids();
        if ($buffered) {
            $userlist->add_users($buffered);
        }
    }

    /**
     * Export the requesting user's Collaborate attendance rows, login total and
     * staged records.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }
            $records = $DB->get_records('local_intellistream_colpart', ['external_user_id' => $user->id]);
            if (!empty($records)) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_intellistream')],
                    (object) ['attendance' => array_values($records)]
                );
            }

            $logins = $DB->get_field(\local_intellistream\userlogins::TABLE, 'logins', ['userid' => $user->id]);
            if ($logins !== false) {
                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'local_intellistream'),
                        get_string('privacy:subcontext:userlogins', 'local_intellistream'),
                    ],
                    (object) ['logins' => (int) $logins]
                );
            }

            // Records still staged in the on-disk buffer, not yet shipped.
            // Exported under their own subcontext so a subject can tell staged data
            // apart from the attendance rows.
            $buffered = \local_intellistream\local\buffer_privacy::user_records((int) $user->id);
            if ($buffered) {
                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'local_intellistream'),
                        get_string('privacy:subcontext:buffer', 'local_intellistream'),
                    ],
                    (object) ['records' => $buffered]
                );
            }
        }
    }

    /**
     * Delete every user's attendance rows, login totals and staged records in the
     * given (system) context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records('local_intellistream_colpart');
            \local_intellistream\userlogins::delete_all();

            // Every user's staged records, through the writer-safe erasure path.
            // purge_all() (the uninstall path) would unlink files a live worker still
            // holds open, losing its later appends. delete_user_records() rewrites
            // only writer-less files (live writers' files ship within minutes) and
            // reaches every tracked directory and parked files.
            $userids = \local_intellistream\local\buffer_privacy::user_ids();
            if ($userids !== []) {
                \local_intellistream\local\buffer_privacy::delete_user_records($userids);
            }
        }
    }

    /**
     * Delete the requesting user's attendance rows, login total and staged records.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $DB->delete_records('local_intellistream_colpart', ['external_user_id' => $user->id]);
                \local_intellistream\userlogins::delete_users([(int) $user->id]);
                \local_intellistream\local\buffer_privacy::delete_user_records([(int) $user->id]);
            }
        }
    }

    /**
     * Delete the attendance rows, login totals and staged records of a set of users
     * in the given (system) context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_intellistream_colpart', "external_user_id $insql", $inparams);
        \local_intellistream\userlogins::delete_users($userids);

        // One pass over the buffer for the whole set, rather than per user.
        \local_intellistream\local\buffer_privacy::delete_user_records($userids);
    }
}
