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
 * local_intellistream_colpart, and (c) stages every captured record in an on-disk
 * JSONL buffer under $CFG->dataroot until the shipper drains it.
 *
 * The colpart table is keyed by the Moodle user id in its external_user_id column
 * (populated from the Collaborate API's externalUserId, which the Moodle
 * Collaborate integration sets to the user id), so it supports full export/delete.
 *
 * The buffer is covered too. It was previously ignored by export_user_data() and
 * delete_data_for_user(), so a subject-access or erasure request run while
 * transmission was backlogged missed whatever was still staged. Export and all three delete paths now include it, and
 * get_contexts_for_userid()/get_users_in_context() see buffer-only subjects so the
 * userlist erasure path reaches them.
 *
 * One documented limit: an ACTIVE buffer file is not rewritten when it may still have
 * a writer, because a live writer holds an append handle at a byte offset and rewriting
 * underneath it would corrupt the file and lose in-flight records. That covers two
 * cases:
 *
 *   - its PID is still running on this host; and
 *   - it was written by ANOTHER host, on a clustered Moodle where the buffer directory
 *     is shared. A PID is only meaningful in the process table it came from, so this
 *     host cannot tell a running remote writer from a finished one, and must not guess.
 *
 * Either way the file ships within about a minute and its contents leave with it, so
 * the staged copy is transient; the erasure is not skipped, only deferred to that point.
 * Both cases are counted in the `skipped` total the caller receives. Every other file —
 * closed, pulled, or orphaned by a dead local worker — is rewritten in place.
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
                'eventdata'  => 'privacy:metadata:intellistream_events:eventdata',
                'client_ip'  => 'privacy:metadata:intellistream_events:client_ip',
                'user_agent' => 'privacy:metadata:intellistream_events:user_agent',
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
            // Declared field-by-field for the sensitive data classes rather than as
            // one generic "table rows" blob. The single `entitydata` line covered
            // ~180 tables including message bodies, forum posts, chat text and
            // payment records, so a DPO reading the rendered privacy registry could
            // not learn that any of those leave the site. These names are disclosure
            // categories, not literal column names; that is what
            // add_external_location_link is for.
            [
                'entitydata'         => 'privacy:metadata:intellistream_entities:entitydata',
                'messagemeta'        => 'privacy:metadata:intellistream_entities:messagemeta',
                'messagecontent'     => 'privacy:metadata:intellistream_entities:messagecontent',
                'forumposts'         => 'privacy:metadata:intellistream_entities:forumposts',
                'chatmessages'       => 'privacy:metadata:intellistream_entities:chatmessages',
                'paypaltransactions' => 'privacy:metadata:intellistream_entities:paypaltransactions',
                'commercedata'       => 'privacy:metadata:intellistream_entities:commercedata',
            ],
            'privacy:metadata:intellistream_entities'
        );
        // Error/exception capture. Declared separately rather than folded into
        // `intellistream_events` because a DPO reading the rendered privacy
        // registry would otherwise have no way to learn that a failed login sends
        // the account's user id and the requested URL off-site — and the events
        // entry reads as ordinary activity data. Same reasoning as the
        // field-by-field split of intellistream_entities above.
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
        // Declared here so a DPO reading the privacy policy sees that the data is
        // staged locally before transmission; export_user_data() and the delete
        // paths below cover it in practice.
        //
        // add_external_location_link is the closest primitive Moodle 4.5 offers —
        // the metadata API has no "local file storage" type (the alternatives are
        // add_database_table, add_subsystem_link, add_plugintype_link and
        // add_user_preference, none of which is true here). The summary string says
        // explicitly that this is on-disk staging inside moodledata and NOT a third
        // party, so the rendered disclosure is accurate even though the primitive is
        // a loose fit.
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

        return $collection;
    }

    /**
     * The colpart rows are site-wide, so a user with any attendance row has
     * data in the system context.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if ($DB->record_exists('local_intellistream_colpart', ['external_user_id' => $userid])) {
            $contextlist->add_system_context();
            return $contextlist;
        }

        // The buffer is site-wide too, so any staged record for this user also puts
        // their data in the system context. Checked second because it
        // reads files: if the attendance table already established the context there
        // is nothing to gain from scanning.
        if (\local_intellistream\buffer::has_user_records($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * List the users who have attendance rows (system context only).
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

        // Plus anyone with a record still staged in the buffer. Without
        // this, a user whose only personal data is an unshipped buffer record would
        // be absent from the userlist, and delete_data_for_users() would never be
        // asked to erase them.
        $buffered = \local_intellistream\buffer::user_ids();
        if ($buffered) {
            $userlist->add_users($buffered);
        }
    }

    /**
     * Export the requesting user's Collaborate attendance rows.
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

            // Records still staged in the on-disk buffer, not yet shipped.
            // Exported under their own subcontext so a subject can tell staged data
            // apart from the attendance rows.
            $buffered = \local_intellistream\buffer::user_records((int) $user->id);
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
     * Delete every user's attendance rows in the given (system) context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records('local_intellistream_colpart');

            // Everything staged in the buffer, for every user. purge_all()
            // is the right primitive here rather than a per-user rewrite: it is the
            // same non-recursive, own-files-only purge the uninstall path uses, so it
            // cannot touch anything that is not this plugin's.
            \local_intellistream\buffer::purge_all();
        }
    }

    /**
     * Delete the requesting user's attendance rows.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $DB->delete_records('local_intellistream_colpart', ['external_user_id' => $user->id]);
                \local_intellistream\buffer::delete_user_records([(int) $user->id]);
            }
        }
    }

    /**
     * Delete attendance rows for a set of users in the given (system) context.
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

        // One pass over the buffer for the whole set, rather than per user.
        \local_intellistream\buffer::delete_user_records($userids);
    }
}
