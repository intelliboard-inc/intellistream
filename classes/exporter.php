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
 * Bulk entity exporter for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Snapshots full Moodle core entity rows into the buffer.
 *
 * The adapter is otherwise purely event-triggered, so a fresh install has no
 * record of users / courses / enrolments / grades that already existed. This
 * exporter walks each core table read-only (via $DB->get_recordset) and
 * appends one `entity_snapshot` record per row to the same buffer the event
 * observer writes to.
 *
 * Background job, NOT the hot path: it processes rows in chunks and yields
 * mtrace() progress. It issues only SELECTs — it never writes the Moodle DB.
 */
class exporter {
    /**
     * Column names that must NEVER leave the site, whatever the curated
     * registry, an admin column override or dynamic discovery asks for.
     *
     * The registry docblock below states that obviously sensitive columns are
     * never exported. Nothing used to enforce that, and the rule drifted: three
     * curated entries ended up shipping `enrol_lti_tools.secret`,
     * `lesson.password` and `groups.enrolmentkey`. A stated rule with no
     * enforcement is a rule that will drift again, so it is now a hard filter.
     *
     * It is enforced at the two — and only two — places a record can enter the
     * buffer:
     *   - {@see registry_with_overrides()}, the funnel every export path resolves
     *     its SELECT list through, and
     *   - {@see strip_forbidden_row_keys()}, for the legacy-migration task, which
     *     reads whole rows with `SELECT *` and so never touches the registry at
     *     all.
     * The second is easy to forget precisely because it does not look like an
     * export path; anything added later that buffers a row it did not select by
     * name needs to go through it too.
     *
     * The funnel placement also closes two ways an admin could re-introduce a
     * credential without editing this file at all, both resolving to `columns = '*'`:
     *   - a `custom_table` row pointed at a core table (config_service rule 3), and
     *   - dynamic discovery shadowing a curated entry (config_service rule 2).
     * Either could have shipped `user.password`.
     *
     * Every name here is a value that grants access or money on possession alone.
     * `seatkey` redeems a paid course seat; `local_intellicart_coupons.code` is the
     * same class of credential but cannot live in this global list, because `code`
     * is ordinary data on other tables — see {@see FORBIDDEN_TABLE_COLUMNS}.
     *
     * Matched case-insensitively against the resolved SELECT list, and matched
     * WHOLE-NAME, not as a substring. That is deliberate, and it was re-confirmed
     * against a live Moodle 4.5 schema rather than by argument:
     *
     *   - a substring or suffix test on `password` also matches `lesson.usepassword`
     *     (smallint) and `bigbluebuttonbn.guestpassword`;
     *   - on `token` it also matches `ai_action_generate_text.prompttokens` (bigint),
     *     `external_tokens.tokentype` (smallint),
     *     `auth_oauth2_linked_login.confirmtokenexpires` (bigint) and
     *     `enrol_lti_app_registration.accesstokenurl` (a URL).
     *
     * Every one of those is ordinary reporting data, so broad matching would trade a
     * credential leak for silent data loss — and `usepassword` is exactly the flag a
     * report needs to say whether a lesson is protected. Whole-name matching means
     * this list has to be maintained, so the cost of that choice is paid by the
     * repository's dev-only forbidden-columns coverage smoke, which scans the live schema
     * for any credential-shaped column this list does not cover and FAILS with the
     * names, rather than leaving the gap to be noticed years later.
     *
     * The names below were verified to exist as real columns (Moodle 4.5 core plus
     * installed plugins); `authtoken` comes from `respondusws_auth_users`, and has
     * been observed carrying live tokens, so none of these is hypothetical.
     */
    const FORBIDDEN_COLUMNS = [
        'password',
        'secret',
        'secretkey',
        'accesskey',
        'enrolmentkey',
        'resourcekey',
        'servicesalt',
        'privatekey',
        'apikey',
        'token',
        'seatkey',
        'sessdata',
        // Real credential columns that whole-name matching previously missed.
        'clientsecret', // OAuth2 client secret, on oauth2_issuer (core).
        'refreshtoken', // OAuth2 refresh token, on oauth2_system_account + badge_backpack_oauth2 (core).
        'consumersecret', // LTI consumer secret, on enrol_lti_users.
        'privatetoken', // Webservice private token, on external_tokens (core).
        'confirmtoken', // Account-link token, on auth_oauth2_linked_login (core).
        'guestpassword', // Meeting guest password, on bigbluebuttonbn.
        'quitpassword', // SEB quit password, on quizaccess_seb_quizsettings.
        'authtoken', // Third-party auth token, on respondusws_auth_users.
    ];

    /**
     * Per-table forbidden columns, for credential columns whose NAME is too generic
     * to blocklist globally.
     *
     * `local_intellicart_coupons.code` is a redeemable discount credential —
     * `coupon_repository` resolves a live discount from `['code' => $code,
     * 'status' => 1]` on possession of the value alone. But `code` on any other
     * table is ordinary data (`courses.code` is a legitimate export), so putting the
     * bare name in {@see FORBIDDEN_COLUMNS} would cause silent, unrelated data loss
     * across the registry. Scoping it to the one table it is a credential on is the
     * only safe way to filter it.
     *
     * The two per-table additions below are the same shape: `user_private_key.value` holds a
     * live webservice/RSS key that authenticates as its `userid`, and `sessions.sid`
     * is a session id that can be replayed to resume that session. Both `value` and
     * `sid` are far too generic for the global list — `value` alone appears on
     * `customfield_data`, `config`, `config_plugins`, `question_response`, and more,
     * all of which are legitimate exports.
     *
     * Table name => list of column names, both matched case-insensitively.
     */
    const FORBIDDEN_TABLE_COLUMNS = [
        'local_intellicart_coupons' => ['code'],
        'user_private_key' => ['value'], // Webservice/RSS key.
        'sessions' => ['sid'], // Replayable session id.
    ];

    /**
     * Private-message body columns, exported only when an admin has explicitly
     * enabled `exportmessagebodies`.
     *
     * These are not credentials, so they are not in the lists above — they are the
     * full text of every private message on the site. Default OFF: a site ships
     * message metadata (who, when, which conversation) unless someone deliberately
     * opts in to the bodies. The setting is not writable over the control webhook,
     * so sending message content off-site is a local admin decision that a customer
     * can audit, not something the vendor can switch on remotely.
     */
    const MESSAGE_BODY_COLUMNS = ['fullmessage', 'fullmessagehtml', 'smallmessage'];

    /** Registry entities whose SELECT lists carry {@see MESSAGE_BODY_COLUMNS}. */
    const MESSAGE_ENTITIES = ['messages', 'message'];

    /**
     * Entity registry: snapshot name => [table, columns].
     *
     * `table` is the Moodle table name without the prefix (as passed to the
     * $DB API). `columns` is the SELECT list; '*' exports the whole row.
     *
     * The column lists are deliberately explicit (rather than '*') for the
     * larger / wider tables so the snapshot is stable if a site has extra
     * columns and so obviously sensitive columns (e.g. user.password) are
     * never exported ({@see FORBIDDEN_COLUMNS}, which enforces this).
     *
     * A registry entry may instead be marked `'derived' => true`. A derived
     * entity is not a straight table snapshot: it has a bespoke exporter
     * method (export_<entity>()) that computes the rows. `table` on a derived
     * entry names the underlying table whose presence is still checked before
     * the bespoke method runs.
     *
     * `'clockless' => true` marks a table that carries rows whose watermark
     * column is NULL or 0 — Moodle writes plenty of those (an ungraded
     * grade_grades row materialised at enrolment has a NULL timemodified), and
     * `wmcol > :since` can never be TRUE for either. Those rows are invisible
     * to the 15-minute lane and arrive only via the verification sweep. The
     * flag turns on the id-keyset second lane in export_incremental(); it is a
     * day-1 seed only, and is unioned with what the verification sweep actually
     * observes ({@see CLOCKLESS_DETECTED_KEY}), because which entities are
     * affected varies by site.
     *
     * @return array<string, array{table:string, columns?:string, derived?:bool,
     *     clockless?:bool}>
     */
    public static function registry(): array {
        return [
            // Identity & structure.
            'user' => [
                'table'   => 'user',
                'columns' => 'id, auth, confirmed, policyagreed, deleted, suspended, '
                    . 'mnethostid, username, idnumber, firstname, lastname, email, '
                    . 'emailstop, lang, calendartype, timezone, firstaccess, lastaccess, '
                    . 'lastlogin, currentlogin, picture, country, city, institution, '
                    . 'department, maildisplay, timecreated, timemodified',
            ],
            'course' => [
                'table'   => 'course',
                'columns' => 'id, category, sortorder, fullname, shortname, idnumber, '
                    . 'summaryformat, format, showgrades, startdate, enddate, visible, '
                    . 'groupmode, groupmodeforce, defaultgroupingid, enablecompletion, '
                    . 'timecreated, timemodified, lang, calendartype',
            ],
            'course_categories' => [
                'table'   => 'course_categories',
                'columns' => 'id, name, idnumber, parent, sortorder, coursecount, '
                    . 'visible, visibleold, timemodified, depth, path',
            ],
            'course_sections' => [
                'table'   => 'course_sections',
                'columns' => 'id, course, section, name, sequence, visible, '
                    . 'availability, timemodified',
            ],
            // The context table is fundamental: role_assignments, cohorts,
            // blocks etc. reference a contextid, and downstream ETL must
            // resolve contextid -> course (contextlevel 50, instanceid=courseid)
            // or module (level 70). Without it, role enrichment cannot run.
            'context' => [
                'table'   => 'context',
                'columns' => 'id, contextlevel, instanceid, path, depth',
            ],

            // Enrolment & roles.
            'enrol' => [
                'table'   => 'enrol',
                'columns' => 'id, enrol, status, courseid, sortorder, name, '
                    . 'enrolperiod, enrolstartdate, enrolenddate, roleid, '
                    . 'customint1, customint2, customint3, timecreated, timemodified',
            ],
            'user_enrolments' => [
                'table'   => 'user_enrolments',
                'columns' => 'id, status, enrolid, userid, timestart, timeend, '
                    . 'modifierid, timecreated, timemodified',
            ],
            'role' => [
                'table'   => 'role',
                'columns' => 'id, name, shortname, description, sortorder, archetype',
            ],
            'role_assignments' => [
                'table'   => 'role_assignments',
                'columns' => 'id, roleid, contextid, userid, timemodified, '
                    . 'modifierid, component, itemid, sortorder',
            ],

            // Activities.
            'course_modules' => [
                'table'   => 'course_modules',
                'columns' => 'id, course, module, instance, section, idnumber, '
                    . 'added, visible, visibleold, completion, completiongradeitemnumber, '
                    . 'completionview, completionexpected, deletioninprogress',
                // Timestamp-less table: `added` (insert time) lets the 15-min
                // incremental ship NEW activities as a safety net; create/update
                // in ~1 min is driven by the course_module_* event observers
                // (see db/events.php + observers/entity_observer.php). `added`
                // does not change on edits, hence the events carry updates.
                'wmcol'   => 'added',
            ],
            'modules' => [
                'table'   => 'modules',
                'columns' => 'id, name, cron, lastcron, search, visible',
            ],
            'course_modules_completion' => [
                'table'   => 'course_modules_completion',
                // The `viewed` column was removed from this table in Moodle 4.0
                // (per-module viewing moved to course_modules_viewed).
                'columns' => 'id, coursemoduleid, userid, completionstate, '
                    . 'overrideby, timemodified',
                // Clockless: this table carries rows whose watermark is
                // NULL or 0, which the CDC predicate can never match. See
                // export_incremental() and CLOCKLESS_DETECTED_KEY.
                'clockless' => true,
            ],
            'course_completions' => [
                'table'   => 'course_completions',
                'columns' => 'id, userid, course, timeenrolled, timestarted, '
                    . 'timecompleted, reaggregate',
            ],

            // Grades.
            'grade_items' => [
                'table'   => 'grade_items',
                'columns' => 'id, courseid, categoryid, itemname, itemtype, '
                    . 'itemmodule, iteminstance, itemnumber, gradetype, grademax, '
                    . 'grademin, gradepass, aggregationcoef, sortorder, hidden, '
                    . 'locked, weightoverride, timecreated, timemodified',
                // Clockless: this table carries rows whose watermark is
                // NULL or 0, which the CDC predicate can never match. See
                // export_incremental() and CLOCKLESS_DETECTED_KEY.
                'clockless' => true,
            ],
            'grade_grades' => [
                'table'   => 'grade_grades',
                'columns' => 'id, itemid, userid, rawgrade, rawgrademax, rawgrademin, '
                    . 'finalgrade, hidden, locked, overridden, excluded, feedbackformat, '
                    . 'usermodified, timecreated, timemodified, aggregationstatus, '
                    . 'aggregationweight',
                // Clockless: this table carries rows whose watermark is
                // NULL or 0, which the CDC predicate can never match. See
                // export_incremental() and CLOCKLESS_DETECTED_KEY.
                'clockless' => true,
            ],

            // Quiz.
            'quiz' => [
                'table'   => 'quiz',
                'columns' => 'id, course, name, timeopen, timeclose, timelimit, '
                    . 'attempts, grademethod, sumgrades, grade, questionsperpage, '
                    . 'navmethod, timecreated, timemodified',
            ],
            'quiz_attempts' => [
                'table'   => 'quiz_attempts',
                'columns' => 'id, quiz, userid, attempt, uniqueid, state, '
                    . 'timestart, timefinish, timemodified, sumgrades, gradednotificationsenttime',
            ],

            // Assignment.
            'assign' => [
                'table'   => 'assign',
                'columns' => 'id, course, name, alwaysshowdescription, allowsubmissionsfromdate, '
                    . 'duedate, cutoffdate, gradingduedate, grade, timemodified, '
                    . 'completionsubmit, teamsubmission, blindmarking, markingworkflow',
            ],
            'assign_submission' => [
                'table'   => 'assign_submission',
                'columns' => 'id, assignment, userid, timecreated, timemodified, '
                    . 'status, groupid, attemptnumber, latest',
            ],
            'assign_grades' => [
                'table'   => 'assign_grades',
                'columns' => 'id, assignment, userid, timecreated, timemodified, '
                    . 'grader, grade, attemptnumber',
            ],

            // Forum.
            'forum' => [
                'table'   => 'forum',
                'columns' => 'id, course, type, name, timemodified, '
                    . 'assessed, scale, grade_forum, completiondiscussions, '
                    . 'completionreplies, completionposts',
            ],
            'forum_discussions' => [
                'table'   => 'forum_discussions',
                'columns' => 'id, course, forum, name, firstpost, userid, '
                    . 'groupid, assessed, timemodified, usermodified, timestart, timeend, pinned',
            ],
            'forum_posts' => [
                'table'   => 'forum_posts',
                'columns' => 'id, discussion, parent, userid, created, modified, '
                    . 'mailed, subject, totalscore, deleted',
                // Here `modified` (bumped on edit) drives the 15-min incremental —
                // catches new posts AND edits. High volume, so kept on the
                // batched incremental rather than a per-event observer.
                'wmcol'   => 'modified',
            ],

            // Cohorts & groups.
            'cohort' => [
                'table'   => 'cohort',
                'columns' => 'id, contextid, name, idnumber, visible, '
                    . 'component, timecreated, timemodified',
            ],
            'cohort_members' => [
                'table'   => 'cohort_members',
                'columns' => 'id, cohortid, userid, timeadded',
            ],
            // Note `enrolmentkey` is NOT exported: it is the key a user types to join a
            // restricted group, i.e. a bearer credential, and no report needs it.
            'groups' => [
                'table'   => 'groups',
                'columns' => 'id, courseid, idnumber, name, description, '
                    . 'picture, timecreated, timemodified',
            ],
            'groups_members' => [
                'table'   => 'groups_members',
                'columns' => 'id, groupid, userid, timeadded, component, itemid',
            ],

            // Grade structure.
            'grade_categories' => [
                'table'   => 'grade_categories',
                'columns' => 'id, courseid, parent, depth, path, fullname, '
                    . 'aggregation, aggregateonlygraded, aggregateoutcomes, '
                    . 'timecreated, timemodified, hidden',
            ],
            'grade_letters' => [
                'table'   => 'grade_letters',
                'columns' => 'id, contextid, lowerboundary, letter',
            ],
            'grade_outcomes' => [
                'table'   => 'grade_outcomes',
                'columns' => 'id, courseid, shortname, fullname, scaleid, '
                    . 'description, descriptionformat, timecreated, timemodified, usermodified',
            ],
            'scale' => [
                'table'   => 'scale',
                'columns' => 'id, courseid, userid, name, scale, description, '
                    . 'descriptionformat, timemodified',
            ],

            // User profile fields.
            'user_info_category' => [
                'table'   => 'user_info_category',
                'columns' => 'id, name, sortorder',
            ],
            'user_info_field' => [
                // Fields `param4`/`param5` can hold connection secrets for some
                // field types; only the descriptive metadata is exported.
                'table'   => 'user_info_field',
                'columns' => 'id, shortname, name, datatype, categoryid, '
                    . 'sortorder, required, locked, visible, forceunique, '
                    . 'signup, defaultdata, defaultdataformat',
            ],
            'user_info_data' => [
                'table'   => 'user_info_data',
                'columns' => 'id, userid, fieldid, data, dataformat',
            ],

            // Custom fields (course/module custom fields).
            'customfield_category' => [
                'table'   => 'customfield_category',
                'columns' => 'id, name, description, descriptionformat, '
                    . 'component, area, itemid, contextid, sortorder, '
                    . 'timecreated, timemodified',
            ],
            'customfield_field' => [
                'table'   => 'customfield_field',
                'columns' => 'id, shortname, name, type, description, '
                    . 'descriptionformat, sortorder, categoryid, configdata, '
                    . 'timecreated, timemodified',
            ],
            'customfield_data' => [
                'table'   => 'customfield_data',
                'columns' => 'id, fieldid, instanceid, intvalue, decvalue, '
                    . 'shortcharvalue, charvalue, value, valueformat, '
                    . 'contextid, timecreated, timemodified',
                // Clockless: this table carries rows whose watermark is
                // NULL or 0, which the CDC predicate can never match. See
                // export_incremental() and CLOCKLESS_DETECTED_KEY.
                'clockless' => true,
            ],

            // Course completion criteria.
            'course_completion_criteria' => [
                'table'   => 'course_completion_criteria',
                'columns' => 'id, course, criteriatype, module, moduleinstance, '
                    . 'courseinstance, enrolperiod, timeend, gradepass, role',
            ],

            // Feedback (mod_feedback).
            'feedback' => [
                'table'   => 'feedback',
                'columns' => 'id, course, name, intro, introformat, anonymous, '
                    . 'email_notification, multiple_submit, autonumbering, '
                    . 'site_after_submit, page_after_submit, page_after_submitformat, '
                    . 'publish_stats, timeopen, timeclose, timemodified, completionsubmit',
            ],
            'feedback_item' => [
                'table'   => 'feedback_item',
                'columns' => 'id, feedback, template, name, label, presentation, '
                    . 'typ, hasvalue, position, required, dependitem, dependvalue, options',
            ],
            'feedback_completed' => [
                'table'   => 'feedback_completed',
                'columns' => 'id, feedback, userid, timemodified, random_response, '
                    . 'anonymous_response, courseid',
            ],
            'feedback_value' => [
                'table'   => 'feedback_value',
                'columns' => 'id, course_id, item, completed, tmp_completed, value',
            ],

            // Survey (mod_survey).
            'survey' => [
                'table'   => 'survey',
                'columns' => 'id, course, template, days, timecreated, timemodified, '
                    . 'name, intro, introformat, questions, completionsubmit',
            ],
            'survey_answers' => [
                'table'   => 'survey_answers',
                'columns' => 'id, userid, survey, question, time, answer1, answer2',
                // Append-only; `time` (answer timestamp) drives the 15-min incremental.
                'wmcol'   => 'time',
            ],

            // Advanced grading / rubrics.
            'grading_areas' => [
                'table'   => 'grading_areas',
                'columns' => 'id, contextid, component, areaname, activemethod',
            ],
            'grading_definitions' => [
                'table'   => 'grading_definitions',
                'columns' => 'id, areaid, method, name, description, '
                    . 'descriptionformat, status, copiedfromid, timecreated, '
                    . 'usercreated, timemodified, usermodified, timecopied',
            ],
            'grading_instances' => [
                'table'   => 'grading_instances',
                'columns' => 'id, definitionid, raterid, itemid, rawgrade, '
                    . 'status, feedback, feedbackformat, timemodified',
            ],
            'gradingform_rubric_criteria' => [
                'table'   => 'gradingform_rubric_criteria',
                'columns' => 'id, definitionid, sortorder, description, descriptionformat',
            ],
            'gradingform_rubric_levels' => [
                'table'   => 'gradingform_rubric_levels',
                'columns' => 'id, criterionid, score, definition, definitionformat',
            ],
            'gradingform_rubric_fillings' => [
                'table'   => 'gradingform_rubric_fillings',
                'columns' => 'id, instanceid, criterionid, levelid, remark, remarkformat',
            ],

            // LTI (mod_lti).
            'lti' => [
                'table'   => 'lti',
                'columns' => 'id, course, name, typeid, toolurl, '
                    . 'instructorchoiceacceptgrades, grade, timecreated, timemodified',
            ],
            'lti_types' => [
                // Any `password`/secret-bearing field is NOT in this column
                // list; lti_types stores OAuth/resource secrets elsewhere.
                'table'   => 'lti_types',
                'columns' => 'id, name, baseurl, tooldomain, state, course, '
                    . 'coursevisible, clientid, toolproxyid, enabledcapability, '
                    . 'parameter, icon, secureicon, createdby, timecreated, '
                    . 'timemodified, description',
            ],
            'lti_submission' => [
                'table'   => 'lti_submission',
                'columns' => 'id, ltiid, userid, datesubmitted, dateupdated, '
                    . 'gradepercent, originalgrade, launchid, state',
            ],

            // Question engine.
            'question' => [
                'table'   => 'question',
                // The category/hidden/idnumber/version columns are pre-4.0: Moodle 4.0
                // moved them to question_bank_entries / question_versions. Declaring the union
                // of both schemas costs nothing on 4.x — resolve_entity_columns()
                // intersects this list against $DB->get_columns(), so a column the host
                // does not have is dropped — and it is the only way the question ->
                // category link survives on 3.9, where those newer tables do not exist.
                'columns' => 'id, parent, name, questiontext, questiontextformat, '
                    . 'generalfeedback, generalfeedbackformat, qtype, defaultmark, '
                    . 'penalty, length, stamp, timecreated, timemodified, '
                    . 'createdby, modifiedby, '
                    . 'category, hidden, idnumber, version',
            ],
            'question_categories' => [
                'table'   => 'question_categories',
                'columns' => 'id, name, contextid, info, infoformat, stamp, '
                    . 'parent, sortorder, idnumber',
            ],
            'question_attempts' => [
                'table'   => 'question_attempts',
                'columns' => 'id, questionusageid, slot, behaviour, questionid, '
                    . 'variant, maxmark, minfraction, maxfraction, flagged, '
                    . 'questionsummary, rightanswer, responsesummary, timemodified',
            ],
            'question_attempt_steps' => [
                'table'   => 'question_attempt_steps',
                'columns' => 'id, questionattemptid, sequencenumber, state, '
                    . 'fraction, timecreated, userid',
            ],
            'question_attempt_step_data' => [
                'table'   => 'question_attempt_step_data',
                'columns' => 'id, attemptstepid, name, value',
            ],
            'quiz_slots' => [
                'table'   => 'quiz_slots',
                // Same union as 'question' above, and the reason this entity matters:
                // 3.9 carries the slot -> question link directly on questionid, while
                // 4.0+ routes it through question_references -> question_versions (both
                // absent on 3.9, and both table_exists()-guarded). displaynumber is 4.2+
                // and quizgradeitemid 4.4+, so on any given host some of these are
                // dropped by the resolver — which is exactly the intent.
                'columns' => 'id, slot, quizid, page, displaynumber, requireprevious, '
                    . 'maxmark, quizgradeitemid, '
                    . 'questionid, questioncategoryid, includingsubcategories',
            ],
            // The three Moodle 4.0+ question-bank tables. All are absent on 3.9, where
            // the same facts live on `question` itself (see the column union above);
            // export_entity() guards every entity with table_exists(), so these are
            // simply skipped there and need no version branching.
            'question_bank_entries' => [
                'table'   => 'question_bank_entries',
                // Without this entity a 4.x question has no category and no idnumber
                // anywhere in the feed: 4.0 moved question.category to
                // questioncategoryid here and question.idnumber to idnumber here. The
                // chain is question -> question_versions.questionbankentryid -> this
                // row, and question_versions was already exported, so the join
                // previously ended at a row nobody shipped. `ownerid` is a user
                // reference of the same kind as question.createdby/modifiedby, both
                // long exported; the other three are structural ids.
                'columns' => 'id, questioncategoryid, idnumber, ownerid',
            ],
            'question_references' => [
                'table'   => 'question_references',
                'columns' => 'id, usingcontextid, component, questionarea, '
                    . 'itemid, questionbankentryid, version',
            ],
            'question_versions' => [
                'table'   => 'question_versions',
                'columns' => 'id, questionbankentryid, version, questionid, status',
            ],

            // SCORM (mod_scorm).
            'scorm' => [
                'table'   => 'scorm',
                'columns' => 'id, course, name, scormtype, reference, version, '
                    . 'maxgrade, grademethod, whatgrade, maxattempt, '
                    . 'timeopen, timeclose, completionscorerequired, '
                    . 'completionstatusrequired, timemodified',
                // Clockless: this table carries rows whose watermark is
                // NULL or 0, which the CDC predicate can never match. See
                // export_incremental() and CLOCKLESS_DETECTED_KEY.
                'clockless' => true,
            ],
            'scorm_scoes' => [
                'table'   => 'scorm_scoes',
                'columns' => 'id, scorm, manifest, organization, parent, '
                    . 'identifier, launch, scormtype, title, sortorder',
            ],
            'scorm_scoes_track' => [
                'table'   => 'scorm_scoes_track',
                'columns' => 'id, userid, scormid, scoid, attempt, element, '
                    . 'value, timemodified',
            ],
            'scorm_attempt' => [
                'table'   => 'scorm_attempt',
                'columns' => 'id, userid, scormid, attempt',
            ],

            // Attendance (mod_attendance).
            'attendance' => [
                'table'   => 'attendance',
                'columns' => 'id, course, name, intro, introformat, grade, '
                    . 'timemodified',
                // Clockless: this table carries rows whose watermark is
                // NULL or 0, which the CDC predicate can never match. See
                // export_incremental() and CLOCKLESS_DETECTED_KEY.
                'clockless' => true,
            ],
            'attendance_sessions' => [
                'table'   => 'attendance_sessions',
                'columns' => 'id, attendanceid, groupid, sessdate, duration, '
                    . 'lasttaken, lasttakenby, timemodified, description, '
                    . 'descriptionformat, studentscanmark, statusset',
            ],
            'attendance_log' => [
                'table'   => 'attendance_log',
                'columns' => 'id, sessionid, studentid, statusid, statusset, '
                    . 'timetaken, takenby, remarks',
                // Append-only attendance marks; `timetaken` drives the incremental.
                'wmcol'   => 'timetaken',
            ],
            'attendance_statuses' => [
                'table'   => 'attendance_statuses',
                'columns' => 'id, attendanceid, acronym, description, grade, '
                    . 'studentavailability, setnumber, visible, deleted',
            ],

            // Badges.
            'badge' => [
                'table'   => 'badge',
                'columns' => 'id, name, description, type, courseid, status, '
                    . 'issuername, expiredate, expireperiod, timecreated, timemodified',
            ],
            'badge_issued' => [
                'table'   => 'badge_issued',
                'columns' => 'id, badgeid, userid, dateissued, dateexpire, visible',
            ],

            // IntelliCart commerce (local_intellicart).
            'local_intellicart_products' => [
                'table'   => 'local_intellicart_products',
                'columns' => 'id, name, producttype, categoryid, price, '
                    . 'taxableprice, idnumber, visible, enableseats, seats, '
                    . 'featured, timecreated, timemodified',
            ],
            'local_intellicart_checkout' => [
                'table'   => 'local_intellicart_checkout',
                'columns' => 'id, item_name, userid, items, payment_status, '
                    . 'amount, subtotal, discount, tax, fee, payment_type, '
                    . 'paymentid, currency, type, datepaid, product_quantity, '
                    . 'timeupdated, timecreated',
            ],
            'local_intellicart_logs' => [
                'table'   => 'local_intellicart_logs',
                'columns' => 'id, userid, instanceid, type, status, checkoutid, '
                    . 'price, discountprice, discount, quantity, tax, fee, '
                    . 'sessionid, enrolled, timecreated, timemodified',
            ],
            'local_intellicart_payments' => [
                'table'   => 'local_intellicart_payments',
                'columns' => 'id, name, type, status, currency, sortorder, '
                    . 'timecreated, timemodified',
            ],
            'local_intellicart_relations' => [
                'table'   => 'local_intellicart_relations',
                'columns' => 'id, productid, instanceid, type, sortorder, '
                    . 'timemodified',
            ],
            'local_intellicart_users' => [
                'table'   => 'local_intellicart_users',
                'columns' => 'id, instanceid, type, userid, role, status, '
                    . 'timemodified',
            ],
            'local_intellicart_vendors' => [
                'table'   => 'local_intellicart_vendors',
                'columns' => 'id, name, idnumber, type, email, company, url, '
                    . 'status, timecreated, timemodified',
            ],
            // Note `seatkey` is NOT exported. It is a bearer credential: IntelliCart's own
            // privacy metadata calls it "a seat key to use as a coupon code", and
            // seats::apply_seatkey() redeems a PAID seat on possession of the value
            // alone. Same for local_intellicart_coupons.code below.
            //
            // Both are filtered: `seatkey` via FORBIDDEN_COLUMNS, `code` via
            // FORBIDDEN_TABLE_COLUMNS, because a bare `code` is legitimate on other
            // tables. They are dropped from the SELECT lists here as well so the
            // registry reads truthfully; the filter is the guarantee, this is the
            // documentation.
            //
            // MIGRATION: removing a column propagates through the dyn-schema catalog
            // (see export_inform_dyn_schema) and takes it out of the in_form_table_*
            // rebuild, which is a hard SQL error for any report still selecting it.
            // The affected reports must be migrated alongside this change.
            'local_intellicart_seats' => [
                'table'   => 'local_intellicart_seats',
                'columns' => 'id, userid, productid, quantity, '
                    . 'sessionid, checkoutid, active, expiration, timecreated, '
                    . 'timemodified',
            ],
            // Note `code` is NOT exported — see local_intellicart_seats above.
            // It is redeemable for a discount by possession alone (coupon_repository
            // resolves ['code' => $code, 'status' => 1]).
            'local_intellicart_coupons' => [
                'table'   => 'local_intellicart_coupons',
                'columns' => 'id, starttime, endtime, expiration, '
                    . 'usedperuser, usedcount, discount, status, type, '
                    . 'timecreated, timemodified',
            ],
            'local_intellicart_cust_flds' => [
                'table'   => 'local_intellicart_cust_flds',
                'columns' => 'id, title, required, visibility, fieldtype, '
                    . 'instancetype, sortorder, categoryid, visibleincatalog, '
                    . 'timemodified',
            ],
            'local_intellicart_flds_val' => [
                'table'   => 'local_intellicart_flds_val',
                'columns' => 'id, fieldid, value, instanceid',
            ],

            // Certificates (mod_customcert).
            'customcert' => [
                'table'   => 'customcert',
                'columns' => 'id, course, templateid, name, requiredtime, '
                    . 'language, timecreated, timemodified',
            ],
            // Note `code` is NOT exported: it is the token mod_customcert's
            // verify_certificate.php accepts to disclose a certificate, so it is a
            // capability token even though the certificate itself carries it in
            // print. The legacy_compat mdl_customcert_issues view keeps
            // its `code` column and returns empty, so nothing selecting it breaks.
            'customcert_issues' => [
                'table'   => 'customcert_issues',
                'columns' => 'id, userid, customcertid, emailed, '
                    . 'timecreated',
            ],

            // Blackboard Collaborate (mod_collaborate) -- meeting config.
            // Per-user attendance is NOT in Moodle; it is pulled from the
            // Blackboard cloud API by the collab_sync task (see Part B).
            'collaborate' => [
                'table'   => 'collaborate',
                'columns' => 'id, course, name, sessionid, sessionuid, '
                    . 'timestart, duration, timeend, grade, timecreated, '
                    . 'timemodified',
            ],
            // Collaborate per-user attendance (Part B) -- our own table, filled
            // by the collab_sync task from the Blackboard cloud API.
            'local_intellistream_colpart' => [
                'table'   => 'local_intellistream_colpart',
                'columns' => 'id, sessionuid, useruid, external_user_id, role, '
                    . 'display_name, first_join_time, last_left_time, duration, '
                    . 'rejoins, timecreated',
            ],

            // Competencies.
            'competency' => [
                'table'   => 'competency',
                'columns' => 'id, shortname, description, descriptionformat, '
                    . 'idnumber, competencyframeworkid, parentid, path, '
                    . 'sortorder, ruletype, ruleoutcome, ruleconfig, scaleid, '
                    . 'scaleconfiguration, timecreated, timemodified, usermodified',
            ],
            'competency_framework' => [
                'table'   => 'competency_framework',
                'columns' => 'id, shortname, contextid, idnumber, description, '
                    . 'descriptionformat, scaleid, scaleconfiguration, visible, '
                    . 'taxonomies, timecreated, timemodified, usermodified',
            ],
            'competency_coursecomp' => [
                'table'   => 'competency_coursecomp',
                'columns' => 'id, courseid, competencyid, ruleoutcome, sortorder, '
                    . 'timecreated, timemodified, usermodified',
            ],
            'competency_modulecomp' => [
                'table'   => 'competency_modulecomp',
                'columns' => 'id, cmid, sortorder, competencyid, ruleoutcome, '
                    . 'overridegrade, timecreated, timemodified, usermodified',
            ],
            'competency_usercomp' => [
                'table'   => 'competency_usercomp',
                'columns' => 'id, userid, competencyid, status, reviewerid, '
                    . 'proficiency, grade, timecreated, timemodified, usermodified',
            ],
            'competency_usercompcourse' => [
                'table'   => 'competency_usercompcourse',
                'columns' => 'id, userid, courseid, competencyid, proficiency, '
                    . 'grade, timecreated, timemodified, usermodified',
            ],
            'competency_usercompplan' => [
                'table'   => 'competency_usercompplan',
                'columns' => 'id, userid, competencyid, planid, proficiency, '
                    . 'grade, sortorder, timecreated, timemodified, usermodified',
            ],
            'competency_plan' => [
                'table'   => 'competency_plan',
                'columns' => 'id, name, description, descriptionformat, userid, '
                    . 'templateid, origtemplateid, status, duedate, reviewerid, '
                    . 'timecreated, timemodified, usermodified',
            ],
            'competency_plancomp' => [
                'table'   => 'competency_plancomp',
                'columns' => 'id, planid, competencyid, sortorder, '
                    . 'timecreated, timemodified, usermodified',
            ],
            'competency_templatecomp' => [
                'table'   => 'competency_templatecomp',
                'columns' => 'id, templateid, competencyid, sortorder, '
                    . 'timecreated, timemodified, usermodified',
            ],

            // BigBlueButton conference (mod_bigbluebuttonbn).
            // Table/column names verified against the Moodle 4.1
            // mod/bigbluebuttonbn/db/install.xml. table_exists() guarded so
            // sites without the plugin are skipped cleanly. Secret-bearing
            // columns (moderatorpass, viewerpass, guestpassword, guestlinkuid)
            // are deliberately NOT exported.
            'bigbluebuttonbn' => [
                'table'   => 'bigbluebuttonbn',
                'columns' => 'id, type, course, name, intro, introformat, '
                    . 'meetingid, wait, record, recordallfromstart, '
                    . 'openingtime, closingtime, timecreated, timemodified, '
                    . 'userlimit, completionattendance, guestallowed',
            ],
            'bigbluebuttonbn_logs' => [
                'table'   => 'bigbluebuttonbn_logs',
                'columns' => 'id, courseid, bigbluebuttonbnid, userid, '
                    . 'timecreated, meetingid, log, meta',
            ],
            'bigbluebuttonbn_recordings' => [
                'table'   => 'bigbluebuttonbn_recordings',
                'columns' => 'id, courseid, bigbluebuttonbnid, groupid, '
                    . 'recordingid, headless, imported, status, '
                    . 'importeddata, timecreated, usermodified, timemodified',
            ],

            // Messaging.
            // NOTE, and read this before changing anything here: these entries export
            // the FULL TEXT of private messages — `fullmessage`, `fullmessagehtml` and
            // `smallmessage` are the complete message body, not metadata about it.
            //
            // They are listed here but NOT exported by default. Shipping them was
            // once unconditional, which gave a site no way to consent to it and no
            // control over it. It is now opt-in:
            // strip_forbidden_columns() removes MESSAGE_BODY_COLUMNS from this entry
            // and from `message` below unless an admin has enabled
            // `exportmessagebodies`, which defaults off and is not writable over the
            // control webhook. Metadata — who, when, which conversation — still ships,
            // so conversation-volume analytics are unaffected.
            //
            // The columns stay in this list on purpose: the filter is what enforces
            // the decision, so enabling the setting needs no registry edit.
            'messages' => [
                'table'   => 'messages',
                'columns' => 'id, useridfrom, conversationid, subject, '
                    . 'fullmessage, fullmessageformat, fullmessagehtml, '
                    . 'smallmessage, timecreated, fullmessagetrust, customdata',
            ],
            'message_conversations' => [
                'table'   => 'message_conversations',
                'columns' => 'id, type, name, convhash, component, itemtype, '
                    . 'itemid, contextid, enabled, timecreated, timemodified',
            ],
            'message_conversation_members' => [
                'table'   => 'message_conversation_members',
                'columns' => 'id, conversationid, userid, timecreated',
            ],
            'message_user_actions' => [
                'table'   => 'message_user_actions',
                'columns' => 'id, userid, messageid, action, timecreated',
            ],

            // Per-course last access.
            'user_lastaccess' => [
                'table'   => 'user_lastaccess',
                'columns' => 'id, userid, courseid, timeaccess',
                // The timeaccess column moves for every active user every day and the 15-minute
                // lane sends each change (it is the watermark), so it is left out of
                // the sweep's fingerprint: blocks change only on insert, delete, or a
                // change the lane cannot see.
                'fpskipwm' => 'timeaccess',
            ],

            // Derived: per-user login count.
            // Moodle has no single login counter. The legacy IntelliBoard
            // plugin derived it by counting \core\event\user_loggedin events
            // in the standard logstore; this entity does likewise. Exported
            // by the bespoke export_userlogins() method (see `derived`).
            'userlogins' => [
                'table'   => 'logstore_standard_log',
                'derived' => true,
            ],

            // Assignment flags.
            'assign_user_flags' => [
                'table'   => 'assign_user_flags',
                'columns' => 'id, userid, assignment, locked, mailed, '
                    . 'extensionduedate, workflowstate, allocatedmarker',
            ],

            // Totara / Workplace org hierarchy (table_exists() guarded).
            'org' => [
                'table'   => 'org',
                'columns' => 'id, shortname, description, idnumber, frameworkid, '
                    . 'path, parentid, visible, timecreated, timemodified, '
                    . 'usermodified, fullname, depthlevel, typeid, sortthread, totarasync',
            ],
            'org_framework' => [
                'table'   => 'org_framework',
                'columns' => 'id, shortname, idnumber, description, sortorder, '
                    . 'visible, hidecustomfields, timecreated, timemodified, '
                    . 'usermodified, fullname',
            ],
            'pos' => [
                'table'   => 'pos',
                'columns' => 'id, shortname, idnumber, description, frameworkid, '
                    . 'path, visible, timevalidfrom, timevalidto, timecreated, '
                    . 'timemodified, usermodified, fullname, parentid, depthlevel, '
                    . 'typeid, sortthread, totarasync',
            ],
            'pos_framework' => [
                'table'   => 'pos_framework',
                'columns' => 'id, shortname, idnumber, description, sortorder, '
                    . 'visible, hidecustomfields, timecreated, timemodified, '
                    . 'usermodified, fullname',
            ],
            'job_assignment' => [
                'table'   => 'job_assignment',
                'columns' => 'id, userid, fullname, shortname, idnumber, '
                    . 'description, startdate, enddate, timecreated, timemodified, '
                    . 'usermodified, positionid, positionassignmentdate, '
                    . 'organisationid, managerjaid, managerjapath, tempmanagerjaid, '
                    . 'tempmanagerexpirydate, appraiserid, sortorder, totarasync, '
                    . 'synctimemodified',
            ],
            'tool_tenant' => [
                'table'   => 'tool_tenant',
                'columns' => 'id, name, idnumber, parentid, categoryid, '
                    . 'timecreated, archived',
            ],
            'tool_tenant_user' => [
                'table'   => 'tool_tenant_user',
                'columns' => 'id, tenantid, userid, component, reason, '
                    . 'timecreated, timemodified, usermodified',
            ],

            // Expanded capture (report-coverage audit 2026-05-19).
            // cluster: c1_questionnaire
            // Questionnaire (mod_questionnaire, contrib module).
            'questionnaire' => [
                'table'   => 'questionnaire',
                'columns' => 'id, course, name, intro, introformat, qtype, '
                    . 'respondenttype, resp_eligible, resp_view, notifications, '
                    . 'opendate, closedate, resume, navigate, grade, sid, '
                    . 'timemodified, completionsubmit, autonum, progressbar',
            ],
            // The only table in UMass One Care's migrated InForm set that
            // had no registry entry, so it could never become a candidate. Column
            // list taken from the legacy in_form_table_questionnaire_dependency
            // that was hand-copied into that warehouse (`author_id` is appended by
            // the ETL's view/table DDL, not a Moodle column, so it is not listed).
            'questionnaire_dependency' => [
                'table'   => 'questionnaire_dependency',
                'columns' => 'id, questionid, surveyid, dependquestionid, '
                    . 'dependchoiceid, dependlogic, dependandor',
            ],
            'questionnaire_question' => [
                'table'   => 'questionnaire_question',
                'columns' => 'id, surveyid, name, type_id, result_id, length, '
                    . 'precise, position, content, required, deleted, extradata',
            ],
            'questionnaire_quest_choice' => [
                'table'   => 'questionnaire_quest_choice',
                'columns' => 'id, question_id, content, value',
            ],
            'questionnaire_question_type' => [
                'table'   => 'questionnaire_question_type',
                'columns' => 'id, typeid, type, has_choices, response_table',
            ],
            'questionnaire_response' => [
                'table'   => 'questionnaire_response',
                'columns' => 'id, questionnaireid, submitted, complete, grade, userid',
            ],
            'questionnaire_resp_single' => [
                'table'   => 'questionnaire_resp_single',
                'columns' => 'id, response_id, question_id, choice_id',
            ],
            'questionnaire_resp_multiple' => [
                'table'   => 'questionnaire_resp_multiple',
                'columns' => 'id, response_id, question_id, choice_id',
            ],
            'questionnaire_response_rank' => [
                'table'   => 'questionnaire_response_rank',
                'columns' => 'id, response_id, question_id, choice_id, rankvalue',
            ],
            'questionnaire_response_text' => [
                'table'   => 'questionnaire_response_text',
                'columns' => 'id, response_id, question_id, response',
            ],
            'questionnaire_response_bool' => [
                'table'   => 'questionnaire_response_bool',
                'columns' => 'id, response_id, question_id, choice_id',
            ],
            'questionnaire_response_date' => [
                'table'   => 'questionnaire_response_date',
                'columns' => 'id, response_id, question_id, response',
            ],
            'questionnaire_response_other' => [
                'table'   => 'questionnaire_response_other',
                'columns' => 'id, response_id, question_id, choice_id, response',
            ],
            'questionnaire_survey' => [
                'table'   => 'questionnaire_survey',
                // Column order matches the mod_questionnaire 4.1.x install.xml.
                // DO NOT add `feedbacknotifications`: the legacy plugin had
                // that field, the current one does not, and including it in
                // the SELECT made the whole table fail with "Error reading
                // from database" (questionnaire_survey exported 0 rows).
                'columns' => 'id, name, courseid, realm, status, title, email, '
                    . 'subtitle, info, theme, thanks_page, thank_head, thank_body, '
                    . 'feedbacksections, feedbacknotes, feedbackscores, chart_type',
            ],
            'questionnaire_attempts' => [
                'table'   => 'questionnaire_attempts',
                'columns' => 'id, qid, userid, rid, timemodified',
            ],
            // Cluster: c2_modA
            // Lesson (mod_lesson).
            // `password` is NOT exported: it is the gate a student types to open the
            // lesson, i.e. a bearer credential. The
            // `usepassword` boolean stays, so a report can still show WHETHER a
            // lesson is protected without carrying the secret.
            'lesson' => [
                'table'   => 'lesson',
                'columns' => 'id, course, name, intro, introformat, practice, '
                    . 'modattempts, usepassword, dependency, conditions, '
                    . 'grade, custom, ongoing, usemaxgrade, maxanswers, maxattempts, '
                    . 'review, nextpagedefault, feedback, minquestions, maxpages, '
                    . 'timelimit, retake, activitylink, mediafile, mediaheight, '
                    . 'mediawidth, mediaclose, slideshow, width, height, bgcolor, '
                    . 'displayleft, displayleftif, progressbar, available, deadline, '
                    . 'timemodified, completionendreached, completiontimespent, '
                    . 'allowofflineattempts',
            ],
            'lesson_pages' => [
                'table'   => 'lesson_pages',
                'columns' => 'id, lessonid, prevpageid, nextpageid, qtype, qoption, '
                    . 'layout, display, timecreated, timemodified, title, contents, '
                    . 'contentsformat',
                // Clockless: this table carries rows whose watermark is
                // NULL or 0, which the CDC predicate can never match. See
                // export_incremental() and CLOCKLESS_DETECTED_KEY.
                'clockless' => true,
            ],
            'lesson_attempts' => [
                'table'   => 'lesson_attempts',
                'columns' => 'id, lessonid, pageid, userid, answerid, retry, correct, '
                    . 'useranswer, timeseen',
                // Append-only lesson attempts; `timeseen` drives the incremental.
                'wmcol'   => 'timeseen',
            ],

            // Workshop (mod_workshop).
            'workshop' => [
                'table'   => 'workshop',
                'columns' => 'id, course, name, intro, introformat, instructauthors, '
                    . 'instructauthorsformat, instructreviewers, instructreviewersformat, '
                    . 'timemodified, phase, useexamples, usepeerassessment, '
                    . 'useselfassessment, grade, gradinggrade, strategy, evaluation, '
                    . 'gradedecimals, submissiontypetext, submissiontypefile, '
                    . 'nattachments, submissionfiletypes, latesubmissions, maxbytes, '
                    . 'examplesmode, submissionstart, submissionend, assessmentstart, '
                    . 'assessmentend, phaseswitchassessment, conclusion, conclusionformat, '
                    . 'overallfeedbackmode, overallfeedbackfiles, '
                    . 'overallfeedbackfiletypes, overallfeedbackmaxbytes',
            ],

            // Choice (mod_choice).
            'choice' => [
                'table'   => 'choice',
                'columns' => 'id, course, name, intro, introformat, publish, '
                    . 'showresults, display, allowupdate, allowmultiple, '
                    . 'showunanswered, includeinactive, limitanswers, timeopen, '
                    . 'timeclose, showpreview, timemodified, completionsubmit, '
                    . 'showavailable',
            ],
            'choice_answers' => [
                'table'   => 'choice_answers',
                'columns' => 'id, choiceid, userid, optionid, timemodified',
            ],

            // H5pactivity (mod_h5pactivity).
            'h5pactivity' => [
                'table'   => 'h5pactivity',
                'columns' => 'id, course, name, timecreated, timemodified, intro, '
                    . 'introformat, grade, displayoptions, enabletracking, '
                    . 'grademethod, reviewmode',
            ],
            'h5pactivity_attempts' => [
                'table'   => 'h5pactivity_attempts',
                'columns' => 'id, h5pactivityid, userid, timecreated, timemodified, '
                    . 'attempt, rawscore, maxscore, scaled, duration, completion, '
                    . 'success',
            ],
            // Cluster: c3_modB.
            'data' => [
                'table'   => 'data',
                'columns' => 'id, course, name, intro, introformat, comments, '
                    . 'timeavailablefrom, timeavailableto, timeviewfrom, '
                    . 'timeviewto, requiredentries, requiredentriestoview, '
                    . 'maxentries, rssarticles, approval, manageapproved, '
                    . 'scale, assessed, assesstimestart, assesstimefinish, '
                    . 'defaultsort, defaultsortdir, editany, notification, '
                    . 'timemodified, completionentries',
            ],
            'data_content' => [
                'table'   => 'data_content',
                'columns' => 'id, fieldid, recordid, content, content1, '
                    . 'content2, content3, content4',
            ],
            'data_fields' => [
                'table'   => 'data_fields',
                'columns' => 'id, dataid, type, name, description, required, '
                    . 'param1, param2, param3, param4, param5, param6, '
                    . 'param7, param8, param9, param10',
            ],
            'data_records' => [
                'table'   => 'data_records',
                'columns' => 'id, userid, groupid, dataid, timecreated, '
                    . 'timemodified, approved',
            ],
            'wiki' => [
                'table'   => 'wiki',
                'columns' => 'id, course, name, intro, introformat, '
                    . 'timecreated, timemodified, firstpagetitle, wikimode, '
                    . 'defaultformat, forceformat, editbegin, editend',
            ],
            'glossary' => [
                'table'   => 'glossary',
                'columns' => 'id, course, name, intro, introformat, '
                    . 'allowduplicatedentries, displayformat, mainglossary, '
                    . 'showspecial, showalphabet, showall, allowcomments, '
                    . 'allowprintview, usedynalink, defaultapproval, '
                    . 'approvaldisplayformat, globalglossary, entbypage, '
                    . 'editalways, rsstype, rssarticles, assessed, '
                    . 'assesstimestart, assesstimefinish, scale, timecreated, '
                    . 'timemodified, completionentries',
            ],
            'glossary_entries' => [
                'table'   => 'glossary_entries',
                'columns' => 'id, glossaryid, userid, concept, definition, '
                    . 'definitionformat, definitiontrust, attachment, '
                    . 'timecreated, timemodified, teacherentry, '
                    . 'sourceglossaryid, usedynalink, casesensitive, '
                    . 'fullmatch, approved',
            ],
            'chat' => [
                'table'   => 'chat',
                'columns' => 'id, course, name, intro, introformat, keepdays, '
                    . 'studentlogs, chattime, schedule, timemodified',
            ],
            'chat_messages' => [
                'table'   => 'chat_messages',
                'columns' => 'id, chatid, userid, groupid, issystem, message, '
                    . 'timestamp',
                // Immutable chat messages; `timestamp` (send time) drives the incremental.
                'wmcol'   => 'timestamp',
            ],
            // Cluster: c4_static.
            'url' => [
                'table'   => 'url',
                'columns' => 'id, course, name, intro, introformat, externalurl, display, displayoptions, parameters, timemodified',
            ],
            'page' => [
                'table'   => 'page',
                'columns' => 'id, course, name, intro, introformat, content, contentformat, legacyfiles, '
                    . 'legacyfileslast, display, displayoptions, revision, timemodified',
            ],
            'resource' => [
                'table'   => 'resource',
                'columns' => 'id, course, name, intro, introformat, tobemigrated, legacyfiles, legacyfileslast, '
                    . 'display, displayoptions, filterfiles, revision, timemodified',
            ],
            'book' => [
                'table'   => 'book',
                'columns' => 'id, course, name, intro, introformat, numbering, navstyle, customtitles, revision, '
                    . 'timecreated, timemodified',
            ],
            'folder' => [
                'table'   => 'folder',
                'columns' => 'id, course, name, intro, introformat, revision, timemodified, display, showexpanded, '
                    . 'showdownloadfolder, forcedownload',
            ],
            'label' => [
                'table'   => 'label',
                'columns' => 'id, course, name, intro, introformat, timemodified',
            ],
            'imscp' => [
                'table'   => 'imscp',
                'columns' => 'id, course, name, intro, introformat, revision, keepold, structure, timemodified',
            ],
            // Cluster: c5_core.
            'files' => [
                'table'   => 'files',
                'columns' => 'id, contenthash, pathnamehash, contextid, '
                    . 'component, filearea, itemid, filepath, filename, '
                    . 'userid, filesize, mimetype, status, source, author, '
                    . 'license, timecreated, timemodified, sortorder, '
                    . 'referencefileid',
            ],
            'tag' => [
                'table'   => 'tag',
                'columns' => 'id, userid, tagcollid, name, rawname, '
                    . 'isstandard, description, descriptionformat, flag, '
                    . 'timemodified',
            ],
            'tag_instance' => [
                'table'   => 'tag_instance',
                'columns' => 'id, tagid, component, itemtype, itemid, '
                    . 'contextid, tiuserid, ordering, timecreated, '
                    . 'timemodified',
            ],
            'event' => [
                'table'   => 'event',
                'columns' => 'id, name, description, format, categoryid, '
                    . 'courseid, groupid, userid, repeatid, component, '
                    . 'modulename, instance, type, eventtype, timestart, '
                    . 'timeduration, timesort, visible, uuid, sequence, '
                    . 'timemodified, subscriptionid, priority, location',
            ],
            'grade_grades_history' => [
                'table'   => 'grade_grades_history',
                'columns' => 'id, action, oldid, source, timemodified, '
                    . 'loggeduser, itemid, userid, rawgrade, rawgrademax, '
                    . 'rawgrademin, rawscaleid, usermodified, finalgrade, '
                    . 'hidden, locked, locktime, exported, overridden, '
                    . 'excluded, feedback, feedbackformat, information, '
                    . 'informationformat',
            ],
            'grade_items_history' => [
                'table'   => 'grade_items_history',
                'columns' => 'id, action, oldid, source, timemodified, '
                    . 'loggeduser, courseid, categoryid, itemname, itemtype, '
                    . 'itemmodule, iteminstance, itemnumber, iteminfo, '
                    . 'idnumber, calculation, gradetype, grademax, grademin, '
                    . 'scaleid, outcomeid, gradepass, multfactor, plusfactor, '
                    . 'aggregationcoef, aggregationcoef2, sortorder, hidden, '
                    . 'locked, locktime, needsupdate, display, decimals, '
                    . 'weightoverride',
            ],
            // Cluster: c6_grading.
            'gradingform_guide_criteria' => [
                'table'   => 'gradingform_guide_criteria',
                'columns' => 'id, definitionid, sortorder, shortname, description, '
                    . 'descriptionformat, descriptionmarkers, descriptionmarkersformat, maxscore',
            ],
            'gradingform_guide_fillings' => [
                'table'   => 'gradingform_guide_fillings',
                'columns' => 'id, instanceid, criterionid, remark, remarkformat, score',
            ],
            'quiz_grades' => [
                'table'   => 'quiz_grades',
                'columns' => 'id, quiz, userid, grade, timemodified',
            ],
            'quiz_statistics' => [
                'table'   => 'quiz_statistics',
                'columns' => 'id, hashcode, whichattempts, timemodified, firstattemptscount, '
                    . 'highestattemptscount, lastattemptscount, allattemptscount, '
                    . 'firstattemptsavg, highestattemptsavg, lastattemptsavg, allattemptsavg, '
                    . 'median, standarddeviation, skewness, kurtosis, cic, errorratio, standarderror',
            ],
            'question_answers' => [
                'table'   => 'question_answers',
                'columns' => 'id, question, answer, answerformat, fraction, feedback, feedbackformat',
            ],
            'question_statistics' => [
                'table'   => 'question_statistics',
                'columns' => 'id, hashcode, timemodified, questionid, slot, subquestion, variant, s, '
                    . 'effectiveweight, negcovar, discriminationindex, discriminativeefficiency, '
                    . 'sd, facility, subquestions, maxmark, positions, randomguessscore',
            ],
            'question_usages' => [
                'table'   => 'question_usages',
                'columns' => 'id, contextid, component, preferredbehaviour',
            ],
            'survey_questions' => [
                'table'   => 'survey_questions',
                'columns' => 'id, text, shorttext, multi, intro, type, options',
            ],
            'assign_user_mapping' => [
                'table'   => 'assign_user_mapping',
                'columns' => 'id, assignment, userid',
            ],
            'assignment' => [
                'table'   => 'assignment',
                'columns' => 'id, course, name, intro, introformat, assignmenttype, resubmit, '
                    . 'preventlate, emailteachers, var1, var2, var3, var4, var5, maxbytes, '
                    . 'timedue, timeavailable, grade, timemodified',
            ],
            'assignfeedback_comments' => [
                'table'   => 'assignfeedback_comments',
                'columns' => 'id, assignment, grade, commenttext, commentformat',
            ],
            // Cluster: c7_misc.
            'competency_template' => [
                'table'   => 'competency_template',
                'columns' => 'id, shortname, contextid, description, descriptionformat, visible, duedate, timecreated, '
                    . 'timemodified, usermodified',
            ],
            'competency_templatecohort' => [
                'table'   => 'competency_templatecohort',
                'columns' => 'id, templateid, cohortid, timecreated, timemodified, usermodified',
            ],
            'competency_evidence' => [
                'table'   => 'competency_evidence',
                'columns' => 'id, usercompetencyid, contextid, action, actionuserid, descidentifier, desccomponent, '
                    . 'desca, url, grade, note, timecreated, timemodified, usermodified',
            ],
            'course_format_options' => [
                'table'   => 'course_format_options',
                'columns' => 'id, courseid, format, sectionid, name, value',
            ],
            'role_context_levels' => [
                'table'   => 'role_context_levels',
                'columns' => 'id, roleid, contextlevel',
            ],
            'tool_cohortroles' => [
                'table'   => 'tool_cohortroles',
                'columns' => 'id, cohortid, roleid, userid, timecreated, timemodified, usermodified',
            ],
            'enrol_paypal' => [
                'table'   => 'enrol_paypal',
                'columns' => 'id, business, receiver_email, receiver_id, item_name, courseid, userid, instanceid, memo, '
                    . 'tax, option_name1, option_selection1_x, option_name2, option_selection2_x, payment_status, '
                    . 'pending_reason, reason_code, txn_id, parent_txn_id, payment_type, timeupdated',
            ],
            // Note `secret` is NOT exported. It is the LTI 1.1 consumer shared secret:
            // enrol/lti/classes/tool_provider.php authenticates an inbound launch
            // with `$this->tool->secret == $this->consumer->secret`, so anyone
            // holding it — plus the `uuid` and role/provisioning columns exported
            // alongside — could forge a launch against this site's
            // enrol/lti/tool.php and be auto-provisioned into the course.
            // `uuid` is retained deliberately: without the secret it is an
            // identifier, not a credential, and it is what joins the tool to its
            // enrolment rows downstream.
            'enrol_lti_tools' => [
                'table'   => 'enrol_lti_tools',
                'columns' => 'id, enrolid, contextid, ltiversion, institution, lang, timezone, maxenrolled, '
                    . 'maildisplay, city, country, gradesync, gradesynccompletion, membersync, membersyncmode, '
                    . 'roleinstructor, rolelearner, uuid, provisioningmodelearner, provisioningmodeinstructor, '
                    . 'timecreated, timemodified',
            ],
            // Legacy pre-3.6 messaging table. Like the `messages` entry above, the body
            // columns here are gated behind `exportmessagebodies` and are stripped
            // unless an admin opts in — see the note there. This entity is named in
            // MESSAGE_ENTITIES, which is what makes the gate apply to it.
            'message' => [
                'table'   => 'message',
                'columns' => 'id, useridfrom, useridto, subject, fullmessage, fullmessageformat, fullmessagehtml, '
                    . 'smallmessage, notification, contexturl, contexturlname, timecreated, timeuserfromdeleted, '
                    . 'timeusertodeleted, component, eventtype, customdata',
            ],
        ];
    }

    /**
     * Change-timestamp columns usable to drive incremental export, in priority
     * order. The first one present in an entity's column set is its watermark.
     *
     * `timeupdated` is included (second, so a table carrying both still prefers
     * `timemodified`) because several IntelliCart tables — notably
     * `local_intellicart_checkout`/`_icheckout` — track their last change in
     * `timeupdated`, not `timemodified`. intellidata special-cased checkout to
     * `timeupdated` for exactly this reason; without it, those tables would key
     * incremental export on `timecreated` and miss UPDATEs (e.g. payment_status)
     * until the verification sweep.
     */
    const WATERMARK_COLUMNS = ['timemodified', 'timeupdated', 'timecreated', 'timeaccess', 'timeadded'];

    /**
     * Config key holding the incremental lane's whole position as one JSON
     * document: `{"wm":{"<entity>":<timestamp>,...}}`.
     *
     * One key rather than one per entity because every set_config() write on a
     * plugin purges that plugin's ENTIRE configuration cache — the cache
     * config::enabled() reads on every page render of the capture path. A run
     * that advanced a hundred entities purged it a hundred times; it now writes
     * once per run, and not at all when no watermark moved.
     *
     * @var string
     */
    const CDC_STATE_KEY = 'cdc_state';

    /**
     * Key prefix earlier releases stored one watermark per entity under.
     *
     * Still written by the 2026070900 upgrade block, which is left alone on
     * purpose — rewriting a historical upgrade block changes what already-shipped
     * sites did. cdc_state() adopts these keys the first time it reads, so an
     * upgrading site keeps its exact position and never re-ships history, and
     * cdc_state_save() then removes them once.
     *
     * @var string
     */
    const CDC_LEGACY_PREFIX = 'cdc_wm_';

    /**
     * Config key holding the entities the verification sweep actually observed
     * clockless rows in, as a JSON list.
     *
     * The registry 'clockless' flag is a day-1 seed taken from two production
     * tenants, and those two do not agree: lesson_pages is 72.6% clockless on
     * one and ~0% on the other, while grade_items/attendance/customfield_data
     * appear on the other only. A fixed list would therefore leave some sites
     * broken until someone shipped a release. The verification sweep already reads every
     * row of every table, so recording what it sees costs no extra query and
     * closes that gap within a day.
     *
     * @var string
     */
    const CLOCKLESS_DETECTED_KEY = 'clockless_detected';

    /**
     * Largest span of ids the clockless lane may cover in one pass.
     *
     * The lane exists because a clockless row has no usable change clock, so it
     * is keyed on `id` instead. Left unbounded, the first pass on a site that
     * has never run it would try to ship the entire backlog at once — ~160k
     * rows on one production tenant. That is not merely slow: buffer::append()
     * refuses records once the buffer is at its byte cap, export_entity()
     * counts a refusal as `dropped`, and `dropped` deliberately does NOT block
     * `complete` (see export_entity()), so the watermark would advance over
     * rows that were never shipped and never retry them. Bounding the window
     * keeps every pass small, and the keyset makes it resumable.
     *
     * @var int
     */
    const CLOCKLESS_ID_CHUNK = 50000;

    /**
     * Smallest advance of the task_log mark that is copied into plugin config
     * (seconds of timeend); see export_task_state().
     *
     * @var int
     */
    public const TASK_LOG_MARK_STEP_SEC = 300;

    /**
     * How many refresh-task intervals an entity's watermark may be old for the
     * incremental read to skip its existence check.
     *
     * The watermark is the change time of the newest row this lane shipped. An
     * entity that changed within the last two runs is very likely to have
     * changed again, and for it the COUNT would only add a second pass over the
     * table before the read. An entity that has been quiet for longer gets the
     * COUNT, which on PostgreSQL is a parallel pass where the read would be a
     * serial cursor. Either path yields the same rows and the same watermark;
     * this only picks the cheaper one. The interval is read from the task's own
     * schedule (sweep::interval()), so an admin who changes it keeps the same
     * two-run window.
     *
     * @var int
     */
    public const CDC_ACTIVE_RUNS = 2;

    /**
     * Above this highest user id, export_userlogins() streams its aggregate
     * instead of holding it in memory.
     *
     * The in-memory form peaks at about 590 bytes per user who has logged in
     * (measured, PHP 8.3, pgsql driver). 500,000 users keeps that under 300 MB,
     * inside cron's MEMORY_EXTRA — at least 384 MB on 64-bit PHP, 512 MB with
     * Moodle's default extramemorylimit — with room for the rest of the run. The
     * highest user id is an upper bound on users who have logged in, and a
     * primary-key lookup rather than a count. The memory actually left is checked
     * too ({@see USERLOGINS_BYTES_PER_USER}).
     *
     * @var int
     */
    public const USERLOGINS_INMEMORY_MAX_USERS = 500000;

    /**
     * Bytes per user budgeted for export_userlogins()'s in-memory form.
     *
     * 590 bytes per user was measured on pgsql; mysqli also holds its buffered
     * (STORE_RESULT) copy of the result alongside the array, so 640 is used. The
     * in-memory form is taken only while twice this, times the highest user id,
     * fits in the memory left under memory_limit; otherwise the aggregate
     * streams.
     *
     * @var int
     */
    public const USERLOGINS_BYTES_PER_USER = 640;

    /**
     * Coerce a decoded JSON object into a string => int map.
     *
     * Shared by the two watermark maps in the state document so cdc_state()
     * does not carry a near-identical loop per map.
     *
     * @param mixed $raw Decoded JSON value; anything not array-ish yields [].
     * @return array<string, int>
     */
    private static function int_map($raw): array {
        $out = [];
        foreach ((array)$raw as $key => $value) {
            $out[(string)$key] = (int)$value;
        }
        return $out;
    }

    /**
     * A timestamp-lane watermark: an int when integral, else the decimal string.
     *
     * A decimal or float column can be a watermark ({@see numeric_column()}).
     * Truncating its mark to an int would leave every row between the int and the
     * real mark above it, so they were re-sent on every run. A non-integral mark is
     * kept as the string the database returned, not as a PHP float: Moodle's
     * drivers bind a float parameter at 14 significant digits, which can round the
     * mark up past a row not yet read. An integral value stays an int, so an
     * integer column's SQL parameters are unchanged.
     *
     * @param mixed $value A stored or read watermark value.
     * @return int|string
     */
    private static function wm_number($value) {
        if (is_int($value)) {
            return $value;
        }
        // A float (only from a state document written before marks were kept as
        // text) is rendered at its shortest round-trip form, not a fixed precision.
        $text = is_float($value) ? (string)json_encode($value) : trim((string)$value);
        if (!is_numeric($text)) {
            return 0;
        }
        if ((float)$text == (int)$text && strpbrk($text, 'eE') === false) {
            return (int)$text;
        }
        return $text;
    }

    /**
     * A mark to bind against a given watermark column: a non-integral mark against
     * an integer column (whose type changed since the mark was taken) is floored, so
     * the database is never asked to compare an integer column with a fraction.
     *
     * @param string $table Table name without the prefix.
     * @param string $column Watermark column.
     * @param int|string $mark From wm_number().
     * @return int|string
     */
    private static function wm_for_column(string $table, string $column, $mark) {
        global $DB;
        if (is_int($mark)) {
            return $mark;
        }
        try {
            $info = $DB->get_columns($table)[$column] ?? null;
        } catch (\Throwable $e) {
            $info = null;
        }
        return ($info !== null && ($info->meta_type ?? '') === 'I') ? (int)floor((float)$mark) : $mark;
    }

    /**
     * Coerce a decoded JSON object into a string => watermark map
     * ({@see wm_number()}), for the timestamp lane's `wm` map.
     *
     * @param mixed $raw Decoded JSON value; anything not array-ish yields [].
     * @return array<string, int|string>
     */
    private static function wm_map($raw): array {
        $out = [];
        foreach ((array)$raw as $key => $value) {
            $out[(string)$key] = self::wm_number($value);
        }
        return $out;
    }

    /**
     * Decode the incremental lane's resume records.
     *
     * One record per entity whose last scan the buffer stopped part-way:
     * `lo`/`hi` are the frozen timestamp window, `id` the last id settled, and
     * `idlo`/`idhi` the frozen clockless id window when that lane was part of
     * the scan. A malformed record is dropped, which is always safe: the
     * entity's watermarks were never advanced past it, so the next run simply
     * re-scans the window from its start.
     *
     * @param mixed $raw Decoded JSON.
     * @return array<string, array<string, int>>
     */
    private static function resume_map($raw): array {
        $out = [];
        foreach ((array)$raw as $entity => $rec) {
            if (!is_array($rec) || !isset($rec['lo'], $rec['hi'], $rec['id'])) {
                continue;
            }
            $one = ['lo' => self::wm_number($rec['lo']), 'hi' => self::wm_number($rec['hi']), 'id' => (int)$rec['id']];
            if (isset($rec['idlo'], $rec['idhi'])) {
                $one['idlo'] = (int)$rec['idlo'];
                $one['idhi'] = (int)$rec['idhi'];
            }
            $out[(string)$entity] = $one;
        }
        return $out;
    }

    /**
     * Entities the verification sweep last observed clockless rows in.
     *
     * @return array<string, int> Entity name => max clockless id delivered by
     *     the last complete verification pass (0 when unknown, e.g. a legacy list).
     */
    private static function clockless_detected(): array {
        $raw = get_config(config::COMPONENT, self::CLOCKLESS_DETECTED_KEY);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_int($key) && is_string($value) && $value !== '') {
                // Legacy shape: a plain list of entity names, no delivered
                // position. Reads as 0, which is the safe direction — the lane
                // walks from the start rather than skipping.
                $out[$value] = 0;
                continue;
            }
            if (is_string($key) && $key !== '') {
                $out[$key] = (int)$value;
            }
        }
        return $out;
    }

    /**
     * Read the incremental lane's state document.
     *
     * `idwm` is the clockless lane's position (max `id` shipped per entity) and
     * is absent from every document written before the clockless lane existed;
     * it reads as an
     * empty map there, which is the correct starting point.
     *
     * `resume` holds the frozen window of each entity whose last scan the buffer
     * stopped part-way; see resume_map(). Absent from every earlier
     * document, and written only while non-empty.
     *
     * @return array{wm: array<string, int|float>, idwm: array<string, int>,
     *     idseen: array<string, int>, resume: array<string, array<string, int>>,
     *     migrated: bool}
     */
    private static function cdc_state(): array {
        $state = ['wm' => [], 'idwm' => [], 'idseen' => [], 'resume' => [], 'migrated' => false];

        $raw = get_config(config::COMPONENT, self::CDC_STATE_KEY);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state['wm'] = self::wm_map($decoded['wm'] ?? []);
                $state['idwm'] = self::int_map($decoded['idwm'] ?? []);
                $state['idseen'] = self::int_map($decoded['idseen'] ?? []);
                $state['resume'] = self::resume_map($decoded['resume'] ?? []);
                return $state;
            }
            // Corrupt document. Falling through to the legacy scan is the safe
            // direction: at worst the lane restarts from 0 and re-ships, which
            // the downstream UPSERT-on-id absorbs. Treating it as empty and
            // advancing would skip rows instead.
            mtrace('local_intellistream: cdc_state is not valid JSON — rebuilding from any '
                . 'per-entity watermarks still present.');
        }

        foreach ((array)get_config(config::COMPONENT) as $key => $value) {
            if ($key === self::CDC_STATE_KEY || strpos($key, self::CDC_LEGACY_PREFIX) !== 0) {
                continue;
            }
            $entity = substr($key, strlen(self::CDC_LEGACY_PREFIX));
            if ($entity !== '') {
                $state['wm'][$entity] = self::wm_number($value);
            }
        }
        if ($state['wm']) {
            $state['migrated'] = true;
        }
        return $state;
    }

    /**
     * Start the timestamp lane of an entity just below $ts, if it has never run for it.
     * Called by the historical backfill when it finishes an entity, with $ts taken
     * before the entity's first read: rows changed while the backfill read it are then
     * sent by the lane, and nothing older is sent twice.
     *
     * @param string $entity
     * @param int $ts Unix seconds; ignored when not positive.
     * @return void
     */
    public static function seed_incremental_watermark(string $entity, int $ts): void {
        if ($ts <= 0) {
            return;
        }
        $state = self::cdc_state();
        if (isset($state['wm'][$entity])) {
            return;   // The lane already has a position of its own.
        }
        $state['wm'][$entity] = $ts;
        self::cdc_state_save($state);
    }

    /**
     * Persist the incremental lane's state — the one config write a run makes.
     *
     * A no-op when the document is unchanged, so a run in which nothing moved
     * costs no cache purge at all.
     *
     * @param array $state As returned by cdc_state().
     * @return void
     */
    private static function cdc_state_save(array $state): void {
        // BOTH maps, always. Encoding only 'wm' here (as this did before
        // the clockless lane existed) while cdc_state() reads 'idwm' would silently drop the
        // clockless position on every single run: the lane would restart from 0
        // for ever, re-shipping the same backlog every 15 minutes.
        $doc = [
            'wm' => self::wm_map($state['wm']),
            'idwm' => array_map('intval', (array)($state['idwm'] ?? [])),
            'idseen' => array_map('intval', (array)($state['idseen'] ?? [])),
        ];
        // Only while some entity is mid-window, so a site that never hits the
        // buffer cap keeps a byte-identical document (and no extra write).
        if (!empty($state['resume'])) {
            $doc['resume'] = $state['resume'];
        }
        $payload = json_encode($doc);
        if ($payload === false) {
            // Never lose a run over an encoding failure. Not advancing means the
            // next run re-exports the same window, which is the safe direction.
            mtrace('local_intellistream: could not encode cdc_state — watermarks not advanced.');
            return;
        }
        if ((string)get_config(config::COMPONENT, self::CDC_STATE_KEY) === $payload) {
            return;
        }
        set_config(self::CDC_STATE_KEY, $payload, config::COMPONENT);

        if (!empty($state['migrated'])) {
            // The per-entity keys are now folded into the document. Drop them so
            // a later read cannot resurrect a stale position, and so the config
            // table stops carrying ~180 dead rows.
            foreach ((array)get_config(config::COMPONENT) as $key => $unusedvalue) {
                if ($key !== self::CDC_STATE_KEY && strpos($key, self::CDC_LEGACY_PREFIX) === 0) {
                    unset_config($key, config::COMPONENT);
                }
            }
        }
    }

    /**
     * Resolve the change-timestamp column that drives incremental export for an
     * entity, or null when it has none (those are covered only by the verification
     * sweep).
     *
     * Derived from the registry 'columns' list when explicit; when '*'/null the
     * live table is introspected. No hardcoded per-entity map to drift.
     *
     * @param string|null $columns Registry columns string ('*'/null = whole row).
     * @param string $table Moodle table name (unprefixed).
     * @param string|null $override Explicit per-entity watermark column (registry
     *        'wmcol'); used when present so tables whose real change column is not
     *        one of WATERMARK_COLUMNS (e.g. forum_posts.modified, course_modules.added)
     *        can still be watermarked — the 15-min incremental for registry entities,
     *        and task_log.timeend for the per-minute lane in export_task_state().
     * @return string|null
     */
    protected static function watermark_column(?string $columns, string $table, ?string $override = null): ?string {
        global $DB;
        $have = [];
        $cols = $columns === null ? '' : trim($columns);
        if ($cols !== '' && $cols !== '*') {
            foreach (preg_split('/[\s,]+/', $cols) as $c) {
                $c = trim($c);
                if ($c !== '') {
                    $have[strtolower($c)] = true;
                }
            }
        } else {
            // Where the list is '*' or null, introspect the live table's columns.
            try {
                foreach (array_keys($DB->get_columns($table)) as $c) {
                    $have[strtolower($c)] = true;
                }
            } catch (\Throwable $e) {
                return null;
            }
        }
        // Explicit per-entity override wins when that column is actually present
        // on the entity. Falls through to the standard scan when the override is
        // absent/misconfigured, so we never return a non-existent column.
        $chosen = null;
        if ($override !== null && $override !== '' && isset($have[strtolower($override)])) {
            $chosen = strtolower($override);
        } else {
            foreach (self::WATERMARK_COLUMNS as $col) {
                if (isset($have[$col])) {
                    $chosen = $col;
                    break;
                }
            }
        }
        return ($chosen !== null && self::numeric_column($table, $chosen)) ? $chosen : null;
    }

    /** @var array<string, bool> Tables already reported as having a non-numeric watermark column. */
    private static $textwatermark = [];

    /**
     * Whether a column holds numbers, so it can be a watermark.
     *
     * The watermark column is chosen by NAME (WATERMARK_COLUMNS). Every curated core
     * entity's time columns are integers, but an admin or discovered custom table can
     * carry a `timemodified` that is text; the lane cannot compare or advance a mark
     * over that (the highest value read is not a number), so it would read the whole
     * table again on every run. Such a column is therefore not a watermark: the entity
     * is left to the verification sweep, like any table without one, and a CLI run says
     * so once per table. Answered from $DB->get_columns(), which Moodle caches, so this
     * costs no query after the first per table. When the metadata cannot be read the
     * answer is yes, which is how this code behaved before it asked.
     *
     * @param string $table
     * @param string $column Lower-case column name.
     * @return bool
     */
    private static function numeric_column(string $table, string $column): bool {
        global $DB;
        try {
            $meta = $DB->get_columns($table);
        } catch (\Throwable $e) {
            return true;
        }
        $info = $meta[$column] ?? null;
        if ($info === null || !isset($info->meta_type)) {
            return true;
        }
        if (in_array($info->meta_type, ['I', 'N', 'F', 'R'], true)) {
            return true;
        }
        if (CLI_SCRIPT && !isset(self::$textwatermark[$table])) {
            self::$textwatermark[$table] = true;
            mtrace("local_intellistream: table '{$table}' — column '{$column}' is not numeric, so it cannot be a "
                . 'watermark; the table is left to the verification sweep.');
        }
        return false;
    }

    /**
     * Memoised resolutions, keyed "table|columns|wmcol". Per-process; a schema
     * change mid-request is not a thing, and an override change produces a
     * different key, so no invalidation is needed.
     *
     * @var array<string, array{columns: ?string, wmcol: ?string, missing: string[]}>
     */
    private static $resolvedcolumns = [];

    /**
     * Memoised effective registry for this request, or null when not yet built.
     *
     * Unlike $resolvedcolumns above, this one DOES need invalidation: it is keyed
     * on nothing, so a config write in the same request would otherwise be
     * invisible to a later read. {@see reset_registry_cache()}.
     *
     * @var array<string, array{table:string, columns?:string, derived?:bool}>|null
     */
    private static $registrycache = null;

    /**
     * Entity => declared columns absent from the live table, accumulated across
     * this process for the one-line drift summary. Diagnostics only; never feeds
     * a SELECT.
     *
     * @var array<string, string[]>
     */
    private static $columndrift = [];

    /**
     * Columns deliberately declared for MORE THAN ONE Moodle schema, so their
     * absence is expected rather than drift.
     *
     * Some entities declare the union of a pre-4.0 and a 4.0+ shape, because the
     * link they carry moved tables rather than disappearing: on 3.9 a quiz slot
     * names its question directly, while 4.0+ routes it through
     * question_references -> question_versions. The resolver drops whichever half
     * the host lacks, which is the point — but on any given site one half is
     * ALWAYS missing, so recording it as drift would put a permanent entry in the
     * summary. That trains operators to ignore the line and hides the real drift
     * it exists to surface, so these are excluded from the drift record only.
     * They are still dropped from the SELECT exactly like any absent column.
     *
     * Scope note: this lists only columns declared for cross-version reasons. A
     * column that is simply newer than the host (quiz_slots.displaynumber on 4.1)
     * is genuine drift and must keep reporting.
     *
     * @var array<string, string[]> entity => column names.
     */
    private static $versionoptionalcolumns = [
        'question'   => ['category', 'hidden', 'idnumber', 'version'],
        'quiz_slots' => ['questionid', 'questioncategoryid', 'includingsubcategories'],
    ];

    /**
     * Record an entity's absent columns as drift, minus the ones we expect to be
     * absent because they belong to another Moodle version's schema.
     *
     * @param string   $entity  Registry key.
     * @param string[] $missing Columns the live table does not have.
     */
    private static function record_column_drift(string $entity, array $missing): void {
        $expected = self::$versionoptionalcolumns[$entity] ?? [];
        if ($expected) {
            $missing = array_values(array_diff($missing, $expected));
        }
        if ($missing) {
            self::$columndrift[$entity] = $missing;
        }
    }

    /**
     * Narrow an entity's declared column list to the columns the host Moodle
     * actually has, and derive its watermark from the survivors.
     *
     * The registry declares an explicit SELECT list per entity. Moodle moves
     * columns between releases (quiz_slots.displaynumber arrived in 4.2,
     * .quizgradeitemid in 4.4, and the plugin spans several releases), and third-party
     * tables move on their own plugin's schedule while table_exists() keeps
     * passing. Handed to the DB verbatim, ONE absent column makes the whole
     * SELECT throw — which the callers swallow — so the entity silently exports
     * zero rows. Losing one column is the intended cost; losing the table is not.
     *
     * Called at the point of use rather than inside registry_with_overrides()
     * for two reasons. It is 175x cheaper on the worst realistic path (the funnel
     * resolves all ~178 entities, and capture_entity_match() runs it per observed
     * event — a 300-activity course restore would pay 300 x 178 lookups instead
     * of 300 x 1). More importantly it is the only variant that is CORRECT:
     * task_registry() never passes through that funnel, so task_log /
     * task_scheduled / task_adhoc — shipped every 60s by ship_events — would
     * stay unprotected.
     *
     * Runs AFTER strip_forbidden_columns(), which is the required order: that
     * pass expands a '*' entry to live-minus-forbidden before anything derives a
     * watermark from it, and it means a column deliberately withheld for
     * security is never reported here as drift.
     *
     * @param string $entity Registry key, for the drift record only.
     * @param string $table Unprefixed Moodle table name.
     * @param string|null $columns Declared list; '*'/null = whole row.
     * @param string|null $wmcol Registry 'wmcol' override, if any.
     * @return array{columns: ?string, wmcol: ?string, missing: string[]}
     *         columns === null means there is no usable SELECT list on this site
     *         and the caller must skip the entity.
     */
    protected static function resolve_entity_columns(
        string $entity,
        string $table,
        ?string $columns,
        ?string $wmcol = null
    ): array {
        $cols = $columns === null ? '' : trim($columns);

        // Whole-row export: `SELECT *` cannot name a column that is not there, and
        // watermark_column() already introspects for this shape. Nothing to
        // resolve, and deliberately no get_columns() call of our own — this is
        // why the resolver is free for exactly the entities where drift is
        // impossible.
        if ($cols === '' || $cols === '*') {
            return [
                'columns' => $columns,
                'wmcol' => self::watermark_column($columns, $table, $wmcol),
                'missing' => [],
            ];
        }

        $memokey = $table . '|' . $cols . '|' . (string) $wmcol;
        if (isset(self::$resolvedcolumns[$memokey])) {
            $hit = self::$resolvedcolumns[$memokey];
            // Re-record: the memo is keyed by table, but drift is reported per
            // entity, and two entities can share a table.
            if ($hit['missing']) {
                self::record_column_drift($entity, $hit['missing']);
            }
            return $hit;
        }

        $r = \local_intellistream\services\config_service::intersect_columns($table, $cols);

        if ($r['columns'] === null && !$r['missing']) {
            // Introspection failed outright, so there is nothing to compare
            // against. Fail OPEN: hand back the declared list and behave exactly
            // as this code did before the resolver existed. table_exists() has
            // already covered the ordinary "table is gone" case upstream.
            // Deliberately NOT memoised — a transient failure must not stick for
            // the life of the process.
            return [
                'columns' => $columns,
                'wmcol' => self::watermark_column($columns, $table, $wmcol),
                'missing' => [],
            ];
        }

        $safe = $r['columns'];

        // The `id` column is load-bearing downstream, not just another column: callers
        // order by it and buffer_entity_row() reads $row->id to build the
        // deterministic entity uuid. Every registry entry declares it first, so
        // its absence from the survivors means the table has no `id` — and then
        // there is no salvageable export, only a slower failure. Realistically
        // this is a table-name collision with a third-party plugin rather than a
        // dropped column, which is why the callers report it distinctly.
        if ($safe !== null) {
            $hasid = false;
            foreach (preg_split('/\s*,\s*/', $safe) as $c) {
                if (strtolower(trim($c)) === 'id') {
                    $hasid = true;
                    break;
                }
            }
            if (!$hasid) {
                $safe = null;
            }
        }

        $resolved = [
            'columns' => $safe,
            // Derive the watermark from the SURVIVORS. Without this, an entity
            // whose declared timestamp column is absent builds
            // `WHERE timemodified > :cdcwm` and `MAX(timemodified)` against a
            // column that is not there: both throw, both are swallowed, and the
            // entity ships nothing every 15 minutes forever with its watermark
            // pinned at 0. Passing an explicit list keeps watermark_column() on
            // its string-parsing branch; its numeric check reads Moodle's cached
            // column metadata, so this costs no query after the first per table.
            'wmcol' => $safe === null ? null : self::watermark_column($safe, $table, $wmcol),
            'missing' => $r['missing'],
        ];

        self::$resolvedcolumns[$memokey] = $resolved;
        if ($resolved['missing']) {
            self::record_column_drift($entity, $resolved['missing']);
        }

        return $resolved;
    }

    /**
     * Entities whose declared columns are (partly) absent on this site, as
     * accumulated so far in this process.
     *
     * @return array<string, string[]> entity => missing column names.
     */
    public static function column_drift(): array {
        return self::$columndrift;
    }

    /**
     * One-line summary of accumulated column drift, or null when there is none.
     *
     * Deliberately aggregated rather than logged per entity: export_task_state()
     * calls export_entity() three times a minute and export_incremental() runs 96
     * times a day, so a per-entity line would be thousands of identical rows a
     * day. Drift is a STATE — the per-datatype `datatype_config` snapshot carries
     * `columns_missing` for machine consumption; this line is just so an operator
     * reading cron output sees it at all.
     *
     * @return string|null
     */
    protected static function column_drift_summary(): ?string {
        if (!self::$columndrift) {
            return null;
        }
        $cols = 0;
        $parts = [];
        foreach (self::$columndrift as $entity => $missing) {
            $cols += count($missing);
            $parts[] = $entity . ': ' . implode(',', $missing);
        }
        return sprintf(
            'local_intellistream: column drift — %d entit%s, %d declared column(s) absent (%s).',
            count(self::$columndrift),
            count(self::$columndrift) === 1 ? 'y' : 'ies',
            $cols,
            implode('; ', $parts)
        );
    }

    /**
     * The table's current MAX(id), or null when it cannot be read.
     *
     * A primary-key lookup on every supported database. The obvious alternative
     * — MAX(CASE WHEN <wm> IS NULL THEN id END) — cannot use an index at all
     * and would cost a full table scan every pass.
     *
     * @param string $table Table name without the prefix.
     * @return int|null
     */
    private static function table_max_id(string $table): ?int {
        global $DB;
        try {
            $max = $DB->get_field_sql("SELECT MAX(id) FROM {" . $table . "}");
        } catch (\Throwable $e) {
            return null;
        }
        return ($max === null || $max === false) ? null : (int)$max;
    }

    /**
     * Bounded id window for an entity's clockless rows, or null when there is
     * nothing new to do on that lane.
     *
     * The upper bound is the max id observed by the PREVIOUS pass, not this
     * one. A sequence value is allocated at INSERT while the row only becomes
     * visible at COMMIT, so the two orders differ under concurrency: one
     * transaction can take id 1005, a later one take 1010 and commit, and a
     * pass that read MAX(id) itself would advance the watermark to 1010 and put
     * 1005 permanently below it when that transaction finally commits. For an
     * ungraded grade_grades row — materialised inside exactly the concurrent
     * enrolment and regrade transactions this lane exists to catch — nothing
     * would ever revisit it, because it never gains a timestamp.
     *
     * Deferring the ceiling by one pass turns that into a time bound: an id at
     * or below the previous pass's observation was allocated before that pass
     * ran, so its transaction has had a full refresh_entities interval (15
     * minutes by default) to commit or roll back. That is the guarantee, stated
     * rather than assumed. It is deliberately a *time* bound and not a fixed
     * id-count overlap, because one bulk enrolment or regrade can allocate
     * thousands of ids inside a single transaction and outrun any fixed id
     * margin, while 15 minutes covers it comfortably.
     *
     * Residual, stated honestly: a transaction open longer than one whole pass
     * interval can still slip under the ceiling. The verification sweep stays
     * the backstop there, as it already is for clockless rows that mutate
     * without gaining a timestamp.
     *
     * @param int $idsince Last id this lane shipped for the entity.
     * @param int $ceiling Max id observed on the previous pass; 0 on the first.
     * @return array{0:int, 1:int}|null [since, max], or null when nothing is new.
     */
    private static function clockless_window(int $idsince, int $ceiling): ?array {
        // Capped so a site that has never run this lane cannot try to ship its
        // whole backlog at once; see CLOCKLESS_ID_CHUNK.
        $idmax = min($ceiling, $idsince + self::CLOCKLESS_ID_CHUNK);
        return $idmax > $idsince ? [$idsince, $idmax] : null;
    }

    /**
     * Build the incremental lane's WHERE clause and bound parameters.
     *
     * The timestamp clause is always present. The clockless clause is appended
     * ONLY for an entity known to carry rows the timestamp clause cannot reach,
     * because `COALESCE(<wm>, 0) <= 0` is not indexable. Several registry tables
     * DO index their watermark column and carry no clockless rows at all (the
     * grade history tables are the large example). To be accurate about the
     * cost: the clockless branch also carries a bounded `id` range, which IS
     * indexable, so a planner may reach it as a union of two index ranges
     * rather than a full scan — but it is still strictly more work than the
     * single index range the timestamp clause alone needs, for an entity that
     * by definition has nothing to gain. Entities without clockless rows keep
     * the original single-clause predicate, byte for byte.
     *
     * @param string $wmcol Resolved watermark column.
     * @param int|string $since Timestamp lane's position (a decimal string only for
     *        a non-integral mark on a decimal column; see wm_number()).
     * @param int|string|null $wmbound Timestamp lane's upper bound, or null for none.
     *        Only a frozen resume window has one; an ordinary pass reads
     *        everything above $since and takes its next mark from the rows it
     *        read. Equal to $since makes the clause trivially false.
     * @param array|null $clockless Id window [from, to], or null for none.
     * @return array [$select, $params]
     */
    private static function cdc_select(
        string $wmcol,
        $since,
        $wmbound,
        ?array $clockless
    ): array {
        $select = "{$wmcol} > :cdcwm";
        $params = ['cdcwm' => $since];
        if ($wmbound !== null) {
            $select .= " AND {$wmcol} <= :cdcmax";
            $params['cdcmax'] = $wmbound;
        }
        if ($clockless === null) {
            return [$select, $params];
        }
        $select = "({$select}) OR (COALESCE({$wmcol}, 0) <= 0 "
            . 'AND id > :cdcidwm AND id <= :cdcidmax)';
        $params['cdcidwm'] = $clockless[0];
        $params['cdcidmax'] = $clockless[1];
        return [$select, $params];
    }

    /**
     * Whether an entity's watermark says it changed within the last $window
     * seconds, so its incremental read should run without the existence check.
     *
     * A watermark more than $window in the future is not treated as recent: it
     * is not a Unix time in seconds (a custom table's millisecond column, say),
     * and the existence check is the safe default for it.
     *
     * @param int|string $since The entity's current watermark (see wm_number()).
     * @param int $window CDC_ACTIVE_RUNS refresh intervals, in seconds.
     * @return bool
     */
    private static function cdc_recently_active($since, int $window): bool {
        $since = (int)$since;
        $now = time();
        return $since > $now - $window && $since <= $now + $window;
    }

    /**
     * Whether an incremental window holds at least one row.
     *
     * Asked before the read of an ordinary window, with the read's own WHERE
     * clause and parameters, so it cannot answer "nothing" for a window the read
     * would have returned rows for (short of a row committed between the two
     * statements, which the next run picks up because no mark moved). The
     * answer is used ONLY to skip; it never bounds the read, and it carries no
     * value into the watermark.
     *
     * It is a plain COUNT, not a recordset and not `LIMIT 1`. On PostgreSQL a
     * recordset is a cursor, which the planner never parallelises, so a quiet
     * table would cost a serial pass where 0.9.32's MAX() probe cost a parallel
     * one. `LIMIT 1` was measured too, and PostgreSQL plans it as a serial scan
     * as well (it expects to stop early, which on a quiet table it never does),
     * so it cost about twice the old probe. An aggregate over the same WHERE
     * gets the same parallel plan as MAX() and costs the same.
     *
     * A failed check answers true, so the read runs and reports the failure
     * through its own outcome (and holds the watermark) instead of this method
     * guessing that nothing changed.
     *
     * @param string $table Table name without the prefix.
     * @param string $select The read's WHERE clause (no "WHERE" keyword).
     * @param array $params The read's bound parameters.
     * @return bool
     */
    private static function window_has_rows(string $table, string $select, array $params): bool {
        global $DB;
        try {
            return (int)$DB->get_field_sql("SELECT COUNT(1) FROM {" . $table . "} WHERE {$select}", $params) > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Incremental (CDC) export: ship only rows changed since the last run, per
     * entity, using a persisted per-entity watermark on the entity's change
     * timestamp. This is the every-15-min path (refresh_entities task).
     *
     * NOT exported here (covered by the verification sweep):
     *   - derived entities (aggregates, no row-level change timestamp),
     *   - timestamp-less tables (context/role/modules/course_modules/user_info_*),
     *   - the inform_dyn_schema catalog (rarely changes).
     *
     * Watermarks live in ONE config document, self::CDC_STATE_KEY, holding the
     * max change-timestamp shipped so far per entity. First run (no entry = 0)
     * ships the full table once, then deltas. The verification sweep is the safety net
     * for any timestamp=0 / timestamp-less rows.
     *
     * Two properties this depends on, both of which used to be missing:
     *
     * The next watermark is taken from the rows the scan READ, inside the same
     * statement, not from a separate SELECT MAX(). The two-statement form cost
     * a second pass over the table per entity per run (the watermark columns
     * are almost never indexed), and its mark and its data came from two
     * different point-in-time reads, so the mark could describe state the
     * export did not cover: delete the row that produced MAX() before the scan
     * reaches it and the watermark sits above every timestamp the lane actually
     * shipped, so a row later written into that gap is below the watermark and
     * never picked up here. A mark taken from the rows returned is the same read
     * as the data, so it cannot exceed a value some shipped row carried.
     *
     * An ordinary window on an entity that has been quiet for longer than
     * CDC_ACTIVE_RUNS refresh intervals is first asked whether it holds any row at all
     * (window_has_rows(): a plain COUNT over the read's own WHERE). An empty
     * window is recorded exactly as an empty read would be and the read is
     * skipped. This is a cost measure, not part of the watermark: on PostgreSQL
     * the read is a cursor and runs serially, so without it a run in which
     * nothing changed would cost more than 0.9.32's parallel MAX() probe did.
     * An entity whose watermark is recent (cdc_recently_active()) goes straight
     * to the read, because it has most likely changed again and the check would
     * only be a second pass. So a quiet entity costs one parallel pass, as in
     * 0.9.32, and an active one costs one read, where 0.9.32 paid the MAX()
     * and the read. The mark is always taken from the read. A frozen (resume)
     * window skips the check.
     *
     * What it does NOT close, because no timestamp watermark over a
     * non-serialisable read can: a transaction that commits after the scan's
     * snapshot carrying a timestamp below one the scan did see. That row is
     * invisible to this lane, and recovering it is why the daily verification
     * sweep is a documented safety net rather than an optimisation.
     *
     * A watermark advances only when the scan for that entity ran to the end.
     * export_entity() swallows a mid-scan failure by design, so one bad entity
     * cannot end a run — but the watermark was advanced regardless, which turned
     * a transient database error into a permanently skipped window that only a
     * whole-table pass would ever have recovered.
     *
     * A record the buffer refuses for good (unserialisable, over the size cap)
     * is counted and skipped; it would fail on every retry. A record refused
     * because the buffer cannot take anything right now (the disk
     * cap, a write failure) STOPS that entity's scan: the window is frozen in
     * `cdc_state.resume` together with the last id settled, and the next run
     * continues the same window from that id instead of opening a new one.
     * The frozen upper bound is the highest watermark over the rows settled
     * before the stop, so every unsent row above it, and every settled row
     * changed during the pause, falls in the window after. Every run therefore
     * makes forward progress, re-reads nothing it already delivered, and never
     * steps over an unsent row. The unpaired case
     * is handled by not running at all, below — without that guard every append
     * fails and the lane would either hold every watermark forever or, as it
     * used to, advance all of them while shipping nothing.
     *
     * @param string|null $batch snapshot_batch UUID; generated if null.
     * @return array{batch:string, rows:int, entities:int}
     */
    public static function export_incremental(?string $batch = null): array {
        $batch = $batch ?? \core\uuid::generate();
        $total = 0;
        $entities = 0;
        $held = 0;

        // Nothing may run before the site is paired. buffer::append_record()
        // refuses every record while the site id is empty, so a run here would
        // scan every table, ship nothing, and advance every watermark over the
        // rows it did not ship — the whole point of the watermark, lost silently.
        if (config::site_id() === '') {
            mtrace('local_intellistream: no site id — incremental export skipped until the site is paired.');
            return ['batch' => $batch, 'rows' => 0, 'entities' => 0];
        }

        // Report only this run's drift — see the verification sweep.
        self::$columndrift = [];
        $registry = self::registry_with_overrides();
        $state = self::cdc_state();
        // Registry flag OR what the verification sweep actually saw; see
        // CLOCKLESS_DETECTED_KEY for why a static list is not enough.
        $detected = self::clockless_detected();
        $activewindow = self::CDC_ACTIVE_RUNS * sweep::interval();
        foreach ($registry as $entity => $def) {
            // Keep the sweeper from mistaking a long run with few appends for an
            // abandoned file. First statement in the loop so it still runs for the
            // entities that `continue` out below.
            buffer::keepalive();
            if (!empty($def['derived'])) {
                continue; // Aggregate — sent by the verification sweep.
            }
            if (backfill::gated((string)$entity)) {
                continue; // New install: the historical backfill owns it until it is done.
            }
            $table = $def['table'] ?? null;
            if ($table === null) {
                continue;
            }
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                continue;
            }
            // Resolve against the live schema, and take the watermark from the
            // SURVIVING columns. Deriving it from the declared list instead let a
            // drifted entity build `WHERE <absent> > :cdcwm` and `MAX(<absent>)`:
            // both throw, both are swallowed, so the entity shipped nothing every
            // 15 minutes indefinitely with its watermark stuck at 0.
            $resolved = self::resolve_entity_columns(
                $entity,
                $table,
                $def['columns'] ?? null,
                $def['wmcol'] ?? null
            );
            if ($resolved['columns'] === null) {
                continue; // Nothing selectable on this site — export_entity() reports it.
            }
            $wmcol = $resolved['wmcol'];
            if ($wmcol === null) {
                continue; // No change timestamp — the verification sweep only.
            }
            $since = self::wm_for_column($table, $wmcol, self::wm_number($state['wm'][$entity] ?? 0));
            // No SELECT MAX({$wmcol}) here any more: the next mark comes out of
            // the rows the read returned (see the docblock). A quiet entity is
            // asked first whether its window holds any row, and an active one
            // is read directly, so neither pays for the table twice in the
            // usual case.

            // The clockless lane. A row whose watermark is NULL or 0 can never
            // satisfy `wmcol > :since` — NULL comparisons are UNKNOWN, and the
            // lane starts at 0 with a strict `>`. Moodle produces such rows in
            // bulk (an ungraded grade_grades row materialised at enrolment has a
            // NULL timemodified), so on the entities that carry them the 15-min
            // lane ships nothing and the verification sweep is the only way in
            // — and a site can disable that. `id` is the one monotonic key such
            // a row has, so it gets a second, keyset-bound window here.
            //
            // Applied ONLY to entities known to carry clockless rows. The OR
            // below cannot use an index, and several registry tables DO index
            // their watermark column — grade_grades_history holds 1.85M rows
            // with an index on timemodified and zero clockless rows, so adding
            // the OR there would turn a bounded index range scan into a full
            // scan every 15 minutes for no benefit. Entities not marked stay on
            // the original single-lane predicate, byte for byte.
            $clockless = null;
            if (!empty($def['clockless']) || isset($detected[$entity])) {
                // Read the ceiling BEFORE recording this pass's observation:
                // the window is bounded by what the PREVIOUS pass saw, so an
                // id allocated by a still-open transaction has had a full pass
                // interval to commit. See clockless_window().
                $ceiling = (int)($state['idseen'][$entity] ?? 0);
                $observed = self::table_max_id($table);
                if ($observed !== null) {
                    // An observation, not a delivery watermark — recorded even
                    // when the scan below fails, because it only ever says
                    // "ids up to here existed at this time".
                    $state['idseen'][$entity] = $observed;
                }

                if (!isset($state['idwm'][$entity])) {
                    // First time this entity reaches the lane. Start from the
                    // max clockless id the last verification pass DELIVERED rather
                    // than from 0: those rows are already downstream, and
                    // walking a large table from the start in CLOCKLESS_ID_CHUNK
                    // steps would re-ship every one of them. The registry flag
                    // gets this from db/upgrade.php; a detected entity gets it
                    // here, so the two routes into the lane behave alike.
                    $state['idwm'][$entity] = (int)($detected[$entity] ?? 0);
                }

                if ($observed !== null && $observed < (int)$state['idwm'][$entity]) {
                    // The table shrank under us — a restore from an older
                    // backup, a tenant reset, a truncate-and-reimport. Left
                    // alone the watermark would sit above every id that now
                    // exists, `$idmax > $idsince` would never be true again,
                    // and the lane would be silently inert for this entity
                    // until ids climbed back past the old mark.
                    //
                    // Reset to 0, not to the new max. Clamping to the new max
                    // would declare every row now present as already delivered,
                    // and after a restore from an older backup the ids below
                    // that mark are not the rows we shipped — they are whatever
                    // the backup held. A shrink means the id space is no longer
                    // the one this watermark was tracking, so the only honest
                    // position is the start. It costs a chunked re-walk, which
                    // the downstream UPSERT-on-id and content dedup absorb;
                    // the alternative silently drops rows we never sent.
                    mtrace("local_intellistream: {$entity} — clockless id watermark "
                        . "({$state['idwm'][$entity]}) is above the table's current max "
                        . "({$observed}). The table shrank, so the id space being tracked "
                        . 'is gone; restarting this lane from 0 and re-walking in bounded '
                        . 'passes rather than assuming the surviving rows were shipped.');
                    $state['idwm'][$entity] = 0;
                    $state['idseen'][$entity] = $observed;
                    $ceiling = $observed;
                    // A frozen window from before the shrink describes ids that no
                    // longer mean the same rows. Dropping it is safe: no watermark
                    // was ever advanced past it.
                    unset($state['resume'][$entity]);
                }

                $clockless = self::clockless_window(
                    (int)$state['idwm'][$entity],
                    $ceiling
                );
            }

            $resume = $state['resume'][$entity] ?? null;
            if ($resume !== null) {
                // A previous run's scan was stopped by the buffer part-way through
                // this window. Finish THAT window, from the row after the last one
                // settled, before opening a new one — both lanes frozen exactly as
                // they were, because recomputing either would put rows below the
                // resume id that the stopped scan never reached.
                $since = self::wm_for_column($table, $wmcol, $resume['lo']);
                $bound = self::wm_for_column($table, $wmcol, $resume['hi']);
                $clockless = isset($resume['idlo'], $resume['idhi'])
                    ? [$resume['idlo'], $resume['idhi']] : null;
                if ($bound <= $since && $clockless === null) {
                    unset($state['resume'][$entity]); // An empty frozen window owes nothing.
                    continue;
                }
            } else {
                // Open above: nothing to bound against without the probe, and
                // nothing lost by it, because the mark is taken from what is read.
                $bound = null;
            }

            [$select, $params] = self::cdc_select($wmcol, $since, $bound, $clockless);
            if ($resume !== null) {
                $select = "({$select}) AND id > :cdcresume";
                $params['cdcresume'] = $resume['id'];
            }

            $outcome = null;
            $quiet = $resume === null && !self::cdc_recently_active($since, $activewindow);
            if ($quiet && !self::window_has_rows($table, $select, $params)) {
                // Nothing in this window: exactly what an empty read would have
                // reported, without opening the read. A frozen window never takes
                // this path, because it must be completed.
                $rowshere = 0;
                $outcome = ['complete' => true, 'rows' => 0, 'dropped' => 0, 'error' => null,
                    'stopped' => false, 'lastid' => null, 'maxwm' => null];
            } else {
                $rowshere = self::export_entity(
                    $entity,
                    $batch,
                    $registry,
                    $select,
                    $params,
                    $outcome,
                    true
                );
            }
            $total += $rowshere;
            // Counted per entity that did something. Every entity is read now, so
            // counting reads would report the whole registry on every idle run.
            if ($rowshere > 0 || !empty($outcome['dropped']) || !empty($outcome['stopped'])) {
                $entities++;
            }
            // The highest timestamp over the rows this read settled; null when
            // none carried one (nothing changed, or clockless rows only).
            $readmax = $outcome['maxwm'] === null ? null : self::wm_number($outcome['maxwm']);

            if (!empty($outcome['stopped'])) {
                // The buffer refused a row it may accept later. Freeze this window
                // and resume after the last settled id next run; no watermark moves.
                //
                // The upper bound of a NEW frozen window is the highest timestamp
                // over the rows settled before the stop, not open-ended: the resume
                // is `id > lastid`, so a settled row changed during the pause would
                // be skipped by it, and must therefore fall above the bound, in the
                // window after. Unsent rows above the bound land there too; unsent
                // rows at or below it, same-second ties included, are the resume's.
                // A window being resumed keeps its own bound.
                $hi = $bound ?? max($since, $readmax ?? $since);
                $rec = [
                    'lo' => $since,
                    'hi' => $hi,
                    'id' => (int)($outcome['lastid'] ?? ($resume['id'] ?? 0)),
                ];
                if ($clockless !== null) {
                    $rec['idlo'] = $clockless[0];
                    $rec['idhi'] = $clockless[1];
                }
                $held++;
                if ($hi <= $since && $clockless === null) {
                    // Nothing settled with a timestamp, so there is no window to
                    // finish: the ordinary read from $since next run is the same
                    // read. Recording one would only cost that run.
                    unset($state['resume'][$entity]);
                    mtrace("local_intellistream: {$entity} — the buffer refused a row it may "
                        . "accept later; watermark HELD at {$since}, next run re-reads from there.");
                } else {
                    $state['resume'][$entity] = $rec;
                    mtrace("local_intellistream: {$entity} — the buffer refused a row it may "
                        . "accept later; window frozen, next run resumes after id {$rec['id']}.");
                }
            } else if (!empty($outcome['complete'])) {
                // Both lanes advance together or neither does: the scan that
                // covers them is a single pass, so advancing one while holding
                // the other would step over rows the held lane still owes.
                // Note the coupling this introduces: on a clockless-flagged
                // entity a persistent scan failure now also freezes its
                // TIMESTAMP watermark, which was not true before. That is the
                // conservative direction — re-exporting a window is absorbed by
                // the downstream UPSERT-on-id, skipping one is not — but it is
                // a real behaviour change for those entities.
                //
                // A finished resume owes its whole frozen window, so it moves to
                // the bound; an ordinary read moves to the highest timestamp it
                // read, and stays put when it read none (only clockless rows, or
                // nothing changed).
                $newwm = $bound ?? $readmax;
                if ($newwm !== null && $newwm > $since) {
                    $state['wm'][$entity] = $newwm;
                }
                if ($clockless !== null) {
                    $state['idwm'][$entity] = $clockless[1];
                }
                unset($state['resume'][$entity]);
            } else {
                // The scan did not finish, so part of this window was never read.
                // Holding the watermark is what makes the next run re-export it;
                // the downstream UPSERT-on-id absorbs the overlap.
                $held++;
                mtrace("local_intellistream: {$entity} — watermark HELD at {$since} "
                    . '(scan did not complete); the next run re-exports this window.');
            }
        }

        // The one config write of the run, and skipped when nothing moved.
        self::cdc_state_save($state);

        mtrace(sprintf(
            'local_intellistream: incremental export — %d changed row(s) across %d entit%s (batch %s).',
            $total,
            $entities,
            $entities === 1 ? 'y' : 'ies',
            $batch
        ));
        if ($held > 0) {
            mtrace(sprintf(
                'local_intellistream: incremental export — %d entit%s did not finish scanning; '
                    . 'their watermarks were held for the next run.',
                $held,
                $held === 1 ? 'y' : 'ies'
            ));
        }
        if (($drift = self::column_drift_summary()) !== null) {
            mtrace($drift);
        }
        return ['batch' => $batch, 'rows' => $total, 'entities' => $entities];
    }

    /**
     * Registry of Moodle's own task tables — kept SEPARATE from registry() on
     * purpose.
     *
     * These MUST only ever be exported with the
     * `component='local_intellistream'` filter (see export_task_state()).
     * Folding them into registry() would make the sweep / export_incremental()
     * ship the host's ENTIRE task history (every component's tasks), and
     * task_adhoc even carries a `timecreated` column that the 15-min incremental
     * would key on — both of which massively over-ship. So they are held here
     * and passed EXPLICITLY as the $registry argument to export_entity(), which
     * never consults registry() when a registry is supplied.
     *
     * Column lists are verified against the live Moodle task-subsystem schema
     * (task_log / task_scheduled / task_adhoc). These entities are NOT in
     * load_raw's mapped set downstream, so they land in the generic
     * custom_entities mart with zero pipeline change.
     *
     * @return array
     */
    public static function task_registry(): array {
        return [
            // Completed runs (success AND failure). Moodle writes a row only
            // when a task finishes, so this is the post-completion task log.
            'task_log' => [
                'table'   => 'task_log',
                'columns' => 'id, type, component, classname, userid, timestart, '
                    . 'timeend, dbreads, dbwrites, result, output, hostname, pid',
                // This lane watermarks on completion time, and `timeend` is not
                // one of WATERMARK_COLUMNS, so it has to be declared. It is what
                // lets export_task_state() take the next mark out of the rows its
                // read returned; without it `maxwm` is always null and the lane
                // would never advance, re-shipping its whole history every minute.
                'wmcol'   => 'timeend',
            ],
            // Persistent per-class schedule/status. timestarted/pid are set
            // while a run is in progress and cleared when it finishes — the
            // basis for the Running-Tasks in-progress view.
            'task_scheduled' => [
                'table'   => 'task_scheduled',
                'columns' => 'id, component, classname, lastruntime, nextruntime, '
                    . 'faildelay, disabled, timestarted, hostname, pid',
            ],
            // Queue rows: present while pending, removed on success, persist on
            // failure (faildelay/nextruntime bumped).
            'task_adhoc' => [
                'table'   => 'task_adhoc',
                'columns' => 'id, component, classname, nextruntime, faildelay, '
                    . 'customdata, userid, timecreated, timestarted, hostname, pid',
            ],
        ];
    }

    /**
     * Export this plugin's Moodle task tables so the control-plane
     * Tasks-log / Running-Tasks / Ad-hoc-Tasks views populate for a push-based
     * (Moodle V2) connection the same way they do for the pull-based
     * intellidata plugin.
     *
     * Called from the 1-minute ship_events task (NOT the 15-min refresh) so a
     * finished run surfaces within ~1–2 buffer rotations. Reuses export_entity()
     * unchanged — same entity_snapshot envelope, same deterministic id, same
     * generic downstream lane. EVERY query is filtered to
     * component='local_intellistream'; task_log is additionally watermarked on
     * `timeend` so each pass ships only newly-finished runs.
     *
     * task_scheduled / task_adhoc are tiny per-plugin state tables and carry the
     * live timestarted/pid used for running detection, so the full filtered set
     * is shipped every pass; the downstream content-hash dedup means unchanged
     * rows never re-land in the warehouse.
     *
     * Best-effort by contract: the caller wraps this in try/catch so a task
     * export failure can never block the file-shipping step ship_events exists
     * for. Individual entity errors are already swallowed by export_entity().
     *
     * @param string $batch snapshot_batch UUID shared by this pass.
     * @return int rows buffered.
     */
    public static function export_task_state(string $batch): int {
        $comp = config::COMPONENT;
        $reg = self::task_registry();
        $rows = 0;

        // Task_log: completed runs since the last watermark.
        // timeend is a decimal seconds timestamp; the watermark is the max
        // timeend already shipped. First run (unset) ships this plugin's whole
        // task_log history once, then only newer completions. The stored value
        // keeps full string precision, and the WHERE binds that same string.
        $wmkey = 'task_wm_task_log';
        $stored = get_config($comp, $wmkey);
        $stored = is_numeric($stored) ? (string)$stored : '0';
        // The mark moves on nearly every run (each of this plugin's own task runs
        // adds a row), so it is kept beside the buffer (buffer::write_mark()) and
        // copied into config only every TASK_LOG_MARK_STEP_SEC, as a backstop if
        // that file is lost; the later of the two is where the lane stands.
        $file = buffer::read_mark('task_log');
        $since = $file !== null && (float)$file > (float)$stored ? $file : $stored;
        // The next mark comes out of the rows this read returned (`maxwm`). It
        // used to come from a separate SELECT MAX(timeend) ... WHERE component = ?
        // on this ONE-MINUTE pass, over a table with no index on either column
        // that grows by a row for every task the site runs: a full pass a
        // minute, to learn what the read below already sees. The read is and
        // was unbounded above the mark, so no window changes.
        $refusedbefore = buffer::transient_refusals();
        $outcome = null;
        $rows += self::export_entity(
            'task_log',
            $batch,
            $reg,
            'component = :comp AND timeend > :wm',
            ['comp' => $comp, 'wm' => $since],
            $outcome
        );
        // Only past rows that were written. A row the buffer refused for now (full,
        // unpaired, a failed write) is still owed, and moving the mark past it would
        // leave it behind for good; the whole window is read again next pass
        // instead. A scan that failed part-way is treated the same.
        $complete = !empty($outcome['complete']) && buffer::transient_refusals() === $refusedbefore;
        // Raw, so the stored value keeps the precision the read returned, and bound
        // as that string above: a float parameter is bound at 14 significant
        // digits, which could round the mark past a row not yet read.
        $newmax = $outcome['maxwm'] ?? null;
        if ($complete && $newmax !== null && (float)$newmax > (float)$since) {
            buffer::write_mark('task_log', (string)$newmax);
            // Config only on the step, even when the file cannot be written: a failing
            // file must not turn this back into a set_config() every minute. The rows
            // since the config mark are then re-read (fixed ids, idempotent) until it moves.
            if ((float)$newmax - (float)$stored >= self::TASK_LOG_MARK_STEP_SEC) {
                set_config($wmkey, (string)$newmax, $comp);
            }
        }

        // Task_scheduled + task_adhoc: full filtered state each pass.
        $rows += self::export_entity(
            'task_scheduled',
            $batch,
            $reg,
            'component = :comp',
            ['comp' => $comp]
        );
        $rows += self::export_entity(
            'task_adhoc',
            $batch,
            $reg,
            'component = :comp',
            ['comp' => $comp]
        );

        return $rows;
    }

    // Datatype "table type" categories, mirroring the control plane's display
    // enum (0=Required, 1=Optional, 2=Logs) so the shipped values render as the
    // same labels the legacy IntelliData Datatypes-configuration tab uses.

    /** @var int Table type: required for the product to function. */
    const DATATYPE_TABLETYPE_REQUIRED = 0;

    /** @var int Table type: optional, exported only when enabled. */
    const DATATYPE_TABLETYPE_OPTIONAL = 1;

    /** @var int Table type: high-volume log data. */
    const DATATYPE_TABLETYPE_LOGS     = 2;

    /**
     * Build the per-datatype CONFIG catalog — the push-native equivalent of the
     * legacy `local_intellidata_config` table that fed IntelliData's
     * Datatypes-configuration tab.
     *
     * Every value is DERIVED from how this plugin actually captures the datatype
     * (not copied from IntelliData, and not hardcoded per datatype): the set of
     * datatypes comes straight from the effective exporter registry, and each
     * setting is computed from that registry entry:
     *   - tabletype          Required for a built-in curated datatype, Optional
     *                        for an admin-added custom one, Logs for the
     *                        event-sourced log datatypes.
     *   - timemodified_field the real change-timestamp column that drives
     *                        incremental export (exporter::watermark_column()),
     *                        or '' when the datatype has none.
     *   - rewritable         1 when there is NO incremental watermark, so the
     *                        datatype is re-exported in full each cycle (the
     *                        verification sweep); 0 when it ships incrementally.
     *   - filterbyid         1 for id-keyed tables (this plugin collects/backfills
     *                        them by keyset row id); 0 for derived aggregates.
     *   - events_tracking    1 only for the log datatypes (captured live from
     *                        Moodle events); 0 for entity snapshots, which are
     *                        snapshotted, not event-tracked. This is the honest
     *                        push-model value and intentionally differs from
     *                        IntelliData's per-datatype observers.
     *   - status/exportenabled  1 when the datatype is in the effective registry
     *                        and being exported; a discovered candidate listed
     *                        while the discovery export switch is off carries
     *                        exportenabled 0 (its name, status and change-time
     *                        column only, never its rows).
     *
     * @return array list of associative config rows.
     */
    public static function datatype_config_catalog(): array {
        $builtin = self::registry();                 // Curated built-ins -> Required.
        $overrides = (new \local_intellistream\repositories\config_repository())->get_all();
        $catalog = [];

        // Enumerate built-ins + any admin-added custom entities. Unlike the export
        // path (registry_with_overrides(), which DROPS disabled datatypes), the
        // catalog keeps disabled entries so the control-plane Datatypes-config tab
        // can still list them and re-enable them. With no override rows
        // this reproduces the previous output exactly.
        $names = array_keys($builtin);
        foreach ($overrides as $datatype => $row) {
            if (!isset($builtin[$datatype]) && !empty($row->custom_table)) {
                $names[] = $datatype;
            }
        }

        foreach ($names as $datatype) {
            $def = $builtin[$datatype] ?? null;
            $override = $overrides[$datatype] ?? null;
            $table = $def['table'] ?? null;
            if ($table === null && $override && !empty($override->custom_table)) {
                // A stored `custom_table` is admin free text, and the plugin's own
                // help text documents setting it by editing the config table
                // directly. It reaches watermark_column() -> $DB->get_columns(),
                // which interpolates the table name UNESCAPED into the catalog
                // query on Postgres (pgsql_native_moodle_database::fetch_columns).
                // The export path already gates this behind table_is_real(); this
                // read-only catalog path did not.
                $candidate = (string)$override->custom_table;
                $table = \local_intellistream\services\config_service::table_is_real($candidate)
                    ? $candidate
                    : null;
            }
            $columns = $def['columns'] ?? (($override && isset($override->custom_columns)) ? $override->custom_columns : null);
            $derived = !empty($def['derived']);
            // Previously `wmcol` was omitted here, so the six entities that rely
            // on an override (course_modules, forum_posts, survey_answers,
            // attendance_log, lesson_attempts, chat_messages) were reported to the
            // control plane as timemodified_field='' / rewritable=1 despite
            // shipping incrementally.
            $resolved = ($table !== null && !$derived)
                ? self::resolve_entity_columns($datatype, $table, $columns, $def['wmcol'] ?? null)
                : ['columns' => null, 'wmcol' => null, 'missing' => []];
            $wm = $resolved['wmcol'];

            // Report drift for CURATED entries only. For an admin `custom_table`
            // row $columns is un-whitelisted PARAM_RAW free text, and its rejected
            // tokens must never be shipped off-site.
            $missing = isset($builtin[$datatype]) ? $resolved['missing'] : [];

            $enabled = ($override && (int)$override->enabled === 0) ? 0 : 1;
            $tabletype = ($override && isset($override->tabletype) && $override->tabletype !== null && $override->tabletype !== '')
                ? (int)$override->tabletype
                : (isset($builtin[$datatype]) ? self::DATATYPE_TABLETYPE_REQUIRED : self::DATATYPE_TABLETYPE_OPTIONAL);
            // A discovered table (not a curated built-in) exports only while
            // dynamicdiscoveryexport is on; until then it is listed, with its own
            // enabled flag as `status`, but reported as not exporting.
            $exporting = $enabled;
            if (
                !isset($builtin[$datatype]) && $override && !empty($override->discovered)
                    && !\local_intellistream\services\config_service::dynamic_discovery_export_enabled()
            ) {
                $exporting = 0;
            }

            $catalog[] = [
                'datatype'           => $datatype,
                'tabletype'          => $tabletype,
                'status'             => $enabled,
                'exportenabled'      => $exporting,
                'timemodified_field' => $wm ?? '',
                'rewritable'         => $wm === null ? 1 : 0,
                'filterbyid'         => $derived ? 0 : 1,
                'events_tracking'    => 0,
                // Declared columns this Moodle does not have. One current row per
                // datatype (deterministic uuid5 -> downstream UPSERT), so drift is
                // carried as STATE that self-heals on upgrade rather than as an
                // ever-growing log. Empty string on a healthy site.
                'columns_missing'    => $missing ? implode(',', $missing) : '',
            ];
        }

        // Log datatypes: captured live from Moodle events (not entity snapshots),
        // so tabletype=Logs and events_tracking=Yes. Enumerated from the plugin's
        // own log datatype marker classes rather than a hand-kept list; an admin
        // enable/disable override is honored the same way.
        foreach (
            [
            \local_intellistream\datatypes\syslogs_datatype::ENTITY,
            \local_intellistream\datatypes\exceptions_datatype::ENTITY,
            ] as $logtype
        ) {
            $override = $overrides[$logtype] ?? null;
            $enabled = ($override && (int)$override->enabled === 0) ? 0 : 1;
            $catalog[] = [
                'datatype'           => $logtype,
                'tabletype'          => self::DATATYPE_TABLETYPE_LOGS,
                'status'             => $enabled,
                'exportenabled'      => $enabled,
                'timemodified_field' => '',
                'rewritable'         => 0,
                'filterbyid'         => 0,
                'events_tracking'    => 1,
                // Log datatypes are event-captured, not table snapshots, so they
                // have no declared column list to drift. Present for a uniform
                // row shape.
                'columns_missing'    => '',
            ];
        }

        return $catalog;
    }

    /**
     * Ship the datatype-config catalog so the control-plane
     * Datatypes-configuration tab populates for a push-based connection.
     *
     * One `datatype_config` entity_snapshot per datatype, keyed by the datatype
     * name (deterministic id → the pipeline UPSERTs one current row per
     * datatype). Rides the existing buffer→ship→pipeline path into the generic
     * custom_entities mart with ZERO pipeline change — the same mechanism the
     * inform_dyn_schema catalog already uses. Static metadata, so it is emitted
     * from the periodic refresh (not the 1-min shipper).
     *
     * @param string $batch snapshot_batch UUID shared by this pass.
     * @return int datatypes buffered.
     */
    public static function export_datatype_config(string $batch): int {
        global $CFG;
        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;

        $n = 0;
        foreach (self::datatype_config_catalog() as $cfg) {
            $payload = [
                'id'             => self::entity_uuid($siteid, 'datatype_config', $cfg['datatype']),
                'site_id'        => $siteid,
                'captured_at'    => clock::now(),
                'plugin_version' => $pluginversion,
                'moodle_version' => $moodleversion,
                'record_type'    => 'entity_snapshot',
                'entity'         => 'datatype_config',
                'snapshot_batch' => $batch,
                // The 'id' inside entity_data is the datatype name (the logical key);
                // the control plane assigns its own display row number.
                'entity_data'    => array_merge(['id' => $cfg['datatype']], $cfg),
            ];
            if (buffer::append_record($payload)) {
                $n++;
            }
        }
        mtrace("local_intellistream: datatype_config catalog — {$n} datatype(s) buffered (batch {$batch}).");
        return $n;
    }

    /**
     * Fixed namespace UUID for deterministic entity_snapshot ids (RFC 4122 v5).
     * Do NOT change — altering it shifts every snapshot id and breaks dedup
     * against already-ingested canonical_entities rows.
     */
    const ENTITY_ID_NAMESPACE = '6f1b2c3d-4e5a-4b6c-8d7e-9f0a1b2c3d4e';

    /**
     * Deterministic id for an entity_snapshot row, derived from
     * (site_id, entity, primary key). The SAME logical Moodle record therefore
     * gets the SAME id on every scheduled export, so the downstream consumer's
     * UPSERT-on-id collapses re-snapshots of an unchanged row (and updates a
     * changed row in place) instead of accumulating a fresh random UUID per run
     * — which is what bloated canonical_entities. `$pk` is null for singleton
     * snapshots (e.g. the inform_dyn_schema catalog).
     *
     * @param string $siteid pairing/tenant id
     * @param string $entity entity/datatype name
     * @param int|string|null $pk Moodle primary key (null = singleton)
     * @return string RFC 4122 v5 UUID
     */
    private static function entity_uuid(string $siteid, string $entity, $pk = null): string {
        $name = $pk === null ? "{$siteid}|{$entity}" : "{$siteid}|{$entity}|{$pk}";
        return self::uuid5(self::ENTITY_ID_NAMESPACE, $name);
    }

    /**
     * RFC 4122 v5 (SHA-1, name-based) UUID. Moodle's \core\uuid::generate() is
     * random v4 only, so compute the name-based variant here.
     *
     * @param string $namespace 36-char namespace UUID
     * @param string $name      name within the namespace
     * @return string
     */
    private static function uuid5(string $namespace, string $name): string {
        $nhex = str_replace('-', '', $namespace);
        $nbytes = '';
        for ($i = 0, $len = strlen($nhex); $i < $len; $i += 2) {
            $nbytes .= chr(hexdec(substr($nhex, $i, 2)));
        }
        $hash = sha1($nbytes . $name);
        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000, // Version 5.
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000, // Variant RFC 4122.
            substr($hash, 20, 12)
        );
    }

    /**
     * Emit the InForm dynamic-tables SCHEMA catalog as a single
     * `inform_dyn_schema` entity_snapshot.
     *
     * The catalog lists every non-derived entity in the EFFECTIVE REGISTRY —
     * i.e. every table this plugin exports — together with its column metadata,
     * introspected from the live Moodle table via
     * {@see \moodle_database::get_columns()}.
     *
     * Model: CATALOGUE BROADLY, MATERIALISE NARROWLY. This mirrors
     * legacy `local_intellidata`, whose `get_dbschema_custom` returned every
     * non-blocklisted Moodle table — core tables included, even ones that also
     * had a curated entity (its own test asserts `db_user` is present) — and
     * gated the expensive half separately, per table. Canvas does the same, from
     * the DAP table list. Cataloguing costs nothing: downstream every entry
     * becomes a ZERO-ROW candidate view, and no data moves until an admin
     * activates that table in supernova (meta.last_download). Restricting the
     * catalog to admin-registered `custom_table` rows — as this did before —
     * meant no Moodle V2 tenant could EVER get an InForm dynamic table, because
     * only `local_*`-prefixed dynamic discovery can write `custom_table`.
     *
     * A built-in getting BOTH an `in_form_table_*` and its curated mart is
     * intended, and is what legacy did.
     *
     * Column safety is inherited, not re-implemented: `$registry` comes from
     * {@see registry_with_overrides()}, which has already dropped `enabled = 0`
     * rows and applied {@see strip_forbidden_columns()}, so no credential column
     * can reach the catalog.
     *
     * Shape (identical to the legacy MoodleClient::get_custom_files_schemas
     * output the downstream MoodleColumnEntityMapper consumes):
     *   { "<datatype>": { "name": "<datatype>",
     *                     "fields": { "<col>": {type, max_length, primary_key, ...} } } }
     *
     * The catalog key is the datatype (== the registry key the per-row
     * snapshots carry as `entity`), so the ETL's `original_table_name` and the
     * captured entity name line up.
     *
     * NOT PAGED, deliberately. The catalog is one record and the whole registry
     * measures ~400 KB against buffer::MAX_EVENT_BYTES (1 MiB). Paging would not
     * currently be correct: the ETL merges only rows of the winning
     * `snapshot_batch`, and the middleware's content-hash dedup means an
     * unchanged page ships nothing — so a partial change would silently TRUNCATE
     * the catalog. export_census() defeats that by echoing `snapshot_batch`
     * inside `entity_data`; we cannot, because `entity_data` IS the catalog dict
     * and an injected key becomes a phantom table. One record instead degrades
     * to stale-but-complete. If the size guard below ever fires, add a wrapper
     * envelope on both sides rather than splitting the bare dict.
     *
     * @param string     $batch    snapshot_batch UUID shared by this run.
     * @param array|null $registry Pre-resolved effective registry (column
     *        overrides applied); resolved on demand when null.
     * @return int Number of tables catalogued; 0 when none, or when the record
     *         was too large to emit (see the ALERT below).
     */
    public static function export_inform_dyn_schema(string $batch, ?array $registry = null): int {
        global $DB, $CFG;

        $registry = $registry ?? self::registry_with_overrides();
        $catalog = [];
        foreach ($registry as $datatype => $def) {
            buffer::keepalive(); // See export_entity().
            // Derived entities are computed in PHP, not snapshotted from a table:
            // `userlogins` ships {id, logins} aggregated out of
            // logstore_standard_log, so introspecting its backing table would
            // describe columns the entity never carries — a candidate view with
            // columns the data can never fill.
            //
            // Note the catalog key is the DATATYPE, not the table name, and the
            // two legitimately differ for an admin-added custom entity
            // (config_service rule 3: datatype `inform_demo`, custom_table
            // `local_inform_demo`). That is correct: rows are captured under the
            // datatype, so keying on it is what makes the ETL's canonical lookup
            // and its `in_form_table_<alias>` relation agree.
            if (!empty($def['derived'])) {
                continue;
            }
            $table = $def['table'] ?? '';
            if ($table === '') {
                continue;
            }
            // Budget note: `in_form_table_` (14 chars) + the datatype key must
            // stay inside Postgres' 63-byte identifier limit, i.e. keys up to 49
            // chars. The longest today is 28. A longer key would be silently
            // truncated, colliding with another candidate relation.
            $allow = self::dyn_schema_column_allow_set($def['columns'] ?? '*');
            try {
                if (!\local_intellistream\services\config_service::table_is_real($table)) {
                    mtrace("local_intellistream: inform_dyn_schema — table '{$table}' absent; skipped.");
                    continue;
                }
                $cols = $DB->get_columns($table);
            } catch (\Throwable $e) {
                mtrace("local_intellistream: inform_dyn_schema introspect '{$table}' failed: " . $e->getMessage());
                continue;
            }

            $fields = [];
            foreach ($cols as $name => $col) {
                if ($allow !== null && !isset($allow[$name])) {
                    continue;
                }
                $fields[$name] = [
                    'type'          => $col->type,
                    'name'          => $name,
                    'original_name' => $name,
                    'max_length'    => $col->max_length,
                    'null'          => empty($col->not_null),
                    'has_default'   => !empty($col->has_default),
                    'default_value' => $col->default_value ?? null,
                    'primary_key'   => !empty($col->primary_key),
                    'unique'        => !empty($col->unique),
                    'keys'          => [],
                ];
            }
            if (!$fields) {
                continue;
            }
            $catalog[$datatype] = ['name' => $datatype, 'fields' => $fields];
        }

        if (!$catalog) {
            return 0;
        }

        $bytes = strlen((string)json_encode($catalog));
        $emitted = buffer::append_record([
            'id'             => self::entity_uuid(config::site_id(), 'inform_dyn_schema'),
            'site_id'        => config::site_id(),
            'captured_at'    => clock::now(),
            'plugin_version' => (int)config::plugin_version(),
            'moodle_version' => isset($CFG->version) ? (int)$CFG->version : null,
            'record_type'    => 'entity_snapshot',
            'entity'         => 'inform_dyn_schema',
            'snapshot_batch' => $batch,
            'entity_data'    => $catalog,
        ]);
        if (!$emitted) {
            // A false return means the record exceeded MAX_EVENT_BYTES (or would
            // not serialise) and NOTHING was appended. Reported, never swallowed:
            // a dropped catalog means the ETL sees no catalog, prints
            // "candidates: 0" and exits 0, so this site silently never gets InForm
            // dynamic tables — which is exactly the failure mode. The fix
            // if this fires is a paged wrapper envelope; see the docblock.
            mtrace('local_intellistream: ALERT inform_dyn_schema NOT emitted — '
                . count($catalog) . " table(s), {$bytes} bytes exceeds the "
                . buffer::MAX_EVENT_BYTES . '-byte per-record cap (or failed to '
                . 'serialise). InForm dynamic tables will NOT be discovered.');
            return 0;
        }
        // Byte count is logged on the success path too, so headroom against the
        // cap is observable in every site's cron log before it becomes a problem.
        mtrace('local_intellistream: inform_dyn_schema — ' . count($catalog)
            . " table(s) catalogued, {$bytes} bytes (batch {$batch}).");
        return count($catalog);
    }

    /**
     * Max primary keys carried in ONE census record. A table with more than this
     * is PAGED: N page records + a manifest, unioned by the reconciler
     * — instead of the old behaviour of skipping large tables entirely (which
     * left them never delete-reconciled). Sized so one page's JSON stays well
     * under buffer::MAX_EVENT_BYTES (1 MiB) and the middleware's 2 MiB Kafka cap.
     */
    const CENSUS_PAGE_SIZE = 50000;

    /**
     * Emit a delete-reconciliation CENSUS: one record per entity listing the
     * COMPLETE set of current primary keys, taken from a full table scan.
     *
     * Why this exists: the every-15-min incremental export ships only changed
     * rows, and the middleware content-hash dedup suppresses unchanged rows, so
     * NO single data batch ever holds the full current state — a deleted row and
     * an unchanged-live row look identical downstream. The census is the
     * authoritative "what currently exists", independent of that dedup, so the
     * reconciler can safely tell a genuine deletion from a quiet survivor.
     *
     * Small tables ship ONE record (entity='entity_census', deterministic id =
     * uuid5(site|entity_census|<entity>), payload {census_entity,count,pks}).
     * Tables over CENSUS_PAGE_SIZE are PAGED: N page records
     * (id uuid5(site|entity_census|<entity>|page_<k>), payload {census_entity,
     * page,pks}) followed by a manifest (id ...|manifest, payload
     * {census_entity,is_census_manifest,page_count,count}); the reconciler unions
     * the pages named by the manifest. Every record carries the run batch inside
     * the payload so a pk-set that returns to a prior value still re-ships (the
     * middleware content-dedup would otherwise leave the warehouse on a stale set).
     *
     * Emitted only from the verification sweep (the verification sweep); an incremental
     * run could never build a complete census. A census is written for an entity
     * ONLY after its id scan completes successfully — a failed/partial scan emits
     * nothing, so a census's presence always means a complete set.
     *
     * @param string     $batch    snapshot_batch UUID shared by this run.
     * @param array|null $registry Pre-resolved effective registry.
     * @return int Number of entity census records emitted.
     */
    public static function export_census(string $batch, ?array $registry = null): int {
        global $DB, $CFG;

        $registry = $registry ?? self::registry_with_overrides();
        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $emitted = 0;
        // Page size is the CENSUS_PAGE_SIZE default, overridable via hidden config
        // (operational tuning + lets smoke tests exercise paging on a tiny table).
        $pagesize = (int)get_config('local_intellistream', 'census_page_size');
        if ($pagesize <= 0) {
            $pagesize = self::CENSUS_PAGE_SIZE;
        }

        foreach ($registry as $entity => $def) {
            buffer::keepalive(); // See export_entity(); a census is a full table scan per entity.
            // Derived/computed entities are not straight table snapshots keyed
            // on a stable Moodle id — they cannot be delete-reconciled this way.
            if (!empty($def['derived'])) {
                continue;
            }
            $table = $def['table'] ?? null;
            if (!$table) {
                continue;
            }
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                continue;
            }
            $rs = null;
            try {
                // Stream ids in pages so an arbitrarily large table is never
                // materialised whole and each census record stays well under the
                // 1 MiB per-record cap. A table that fits ONE page ships a single
                // record (unchanged wire format); a larger one ships N page
                // records + a manifest, which the reconciler unions.
                //
                // Stamped BEFORE the scan is issued: a row created after this
                // instant may be missing from the id list, so the reconciler must
                // not read its absence as a delete. Same plugin clock
                // as every row's captured_at, so the two compare without skew.
                $scanstarted = clock::now();
                $rs = $DB->get_recordset($table, null, 'id ASC', 'id');
                $page = [];
                $pageno = 0;
                $total = 0;
                $pagesok = true;
                foreach ($rs as $row) {
                    $page[] = (string)$row->id;
                    if (count($page) >= $pagesize) {
                        $pageno++;
                        $total += count($page);
                        // A page that did not get written must be remembered.
                        // The manifest below names a page COUNT, so a missing page
                        // makes it describe a set the buffer does not contain.
                        $pagesok = self::emit_census_page(
                            $siteid,
                            $entity,
                            $batch,
                            $pageno,
                            $page,
                            $pluginversion,
                            $moodleversion
                        ) && $pagesok;
                        $page = [];
                    }
                }
            } catch (\Throwable $e) {
                // A failed scan emits NO manifest, so the reconciler (which only
                // acts on a manifest's named page set) never acts on a partial set.
                mtrace("local_intellistream: census — '{$entity}' scan failed: "
                    . $e->getMessage() . "; census skipped.");
                continue;
            } finally {
                // Close on EVERY exit, not just the happy path.
                // Several of these loops throw by design, so a close() placed after
                // the foreach leaked the cursor on the paths that matter most.
                if ($rs instanceof \moodle_recordset) {
                    $rs->close();
                }
            }

            $refusal = null;
            if ($pageno === 0) {
                // Single-record census (fits one page): unchanged wire format.
                $written = buffer::append_record([
                    'id'             => self::entity_uuid($siteid, 'entity_census', $entity),
                    'site_id'        => $siteid,
                    'captured_at'    => clock::now(),
                    'plugin_version' => $pluginversion,
                    'moodle_version' => $moodleversion,
                    'record_type'    => 'entity_snapshot',
                    'entity'         => 'entity_census',
                    'snapshot_batch' => $batch,
                    'entity_data'    => [
                        'census_entity'   => $entity,
                        'count'           => count($page),
                        'pks'             => $page,
                        'snapshot_batch'  => $batch,
                        'scan_started_at' => $scanstarted,
                    ],
                ], $refusal);
            } else {
                // Flush the final partial page, then a manifest naming how many
                // pages complete this census for this batch. Manifest is written
                // LAST and only after a clean scan, so its presence => all pages
                // were emitted this run.
                $pagewritten = true;
                if (!empty($page)) {
                    $pageno++;
                    $total += count($page);
                    $pagewritten = self::emit_census_page(
                        $siteid,
                        $entity,
                        $batch,
                        $pageno,
                        $page,
                        $pluginversion,
                        $moodleversion
                    );
                }
                if (!$pagesok || !$pagewritten) {
                    // Same invariant the catch block above relies on.
                    // The reconciler acts only on a manifest's named page set, so a
                    // manifest missing pages is worse than no manifest at all.
                    // Withhold it; the next census re-emits the entity cleanly.
                    mtrace("local_intellistream: census — '{$entity}' page write failed; "
                        . 'manifest withheld so the reconciler never sees a partial page set.');
                    continue;
                }
                $written = buffer::append_record([
                    'id'             => self::entity_uuid($siteid, 'entity_census', "{$entity}|manifest"),
                    'site_id'        => $siteid,
                    'captured_at'    => clock::now(),
                    'plugin_version' => $pluginversion,
                    'moodle_version' => $moodleversion,
                    'record_type'    => 'entity_snapshot',
                    'entity'         => 'entity_census',
                    'snapshot_batch' => $batch,
                    'entity_data'    => [
                        'census_entity'      => $entity,
                        'is_census_manifest' => true,
                        'page_count'         => $pageno,
                        'count'              => $total,
                        'snapshot_batch'     => $batch,
                        'scan_started_at'    => $scanstarted,
                    ],
                ], $refusal);
            }

            // Only count what was actually written. append_record() returns false
            // without writing when the serialised record exceeds MAX_EVENT_BYTES, or
            // when the buffer cannot take anything right now (the disk cap, a write
            // failure); counting regardless told an operator reading the cron output that
            // delete reconciliation was covered for an entity where the census had
            // in fact been discarded. A census that does not arrive makes the
            // downstream reconciler under-clean, which is safe — believing it
            // arrived is not.
            if (!$written) {
                mtrace("local_intellistream: census — '{$entity}' record was NOT written ("
                    . ($refusal === buffer::REFUSED_PERMANENT
                        ? 'it exceeded the per-record size cap'
                        : 'the buffer could not take it now')
                    . '); census skipped for this entity (delete reconciliation will '
                    . 'under-clean until a later census arrives).');
                continue;
            }
            $emitted++;
        }

        mtrace("local_intellistream: census — {$emitted} entit(y/ies) censused "
            . "(batch {$batch}).");
        return $emitted;
    }

    /**
     * Emit one census PAGE record for a large, paged entity. A distinct
     * deterministic id per (entity, page) so pages UPSERT independently; carries
     * the run batch so a page whose pk-set returns to a prior value still re-ships
     * and the reconciler can match a page to its manifest's batch.
     *
     * @param string   $siteid
     * @param string   $entity
     * @param string   $batch
     * @param int      $pageno         1-based page index
     * @param string[] $pks            this page's primary keys
     * @param int      $pluginversion
     * @param int|null $moodleversion
     * @return bool false when the record exceeded the per-record size cap and was
     *         not written, so the caller can report the census as skipped rather
     *         than counting a discarded page as emitted
     */
    private static function emit_census_page(
        string $siteid,
        string $entity,
        string $batch,
        int $pageno,
        array $pks,
        int $pluginversion,
        ?int $moodleversion
    ): bool {
        return buffer::append_record([
            'id'             => self::entity_uuid($siteid, 'entity_census', "{$entity}|page_{$pageno}"),
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => $pluginversion,
            'moodle_version' => $moodleversion,
            'record_type'    => 'entity_snapshot',
            'entity'         => 'entity_census',
            'snapshot_batch' => $batch,
            'entity_data'    => [
                'census_entity'  => $entity,
                'page'           => $pageno,
                'pks'            => $pks,
                'snapshot_batch' => $batch,
            ],
        ]);
    }

    /**
     * Parse an effective-registry `columns` value into an allow-set of column
     * names, or null when all columns are exported ('*').
     *
     * @param string $columns Comma/space-separated list, or '*'.
     * @return array<string,true>|null
     */
    protected static function dyn_schema_column_allow_set(string $columns): ?array {
        $columns = trim($columns);
        if ($columns === '' || $columns === '*') {
            return null;
        }
        $allow = [];
        foreach (preg_split('/[\s,]+/', $columns) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $allow[$name] = true;
            }
        }
        return $allow ?: null;
    }

    /**
     * Export one entity, streaming rows in batches.
     *
     * Uses $DB->get_recordset so an arbitrarily large table never has to be
     * fully materialised in memory. Each row becomes one `entity_snapshot`
     * buffer record. mtrace() reports progress per chunk.
     *
     * @param string $entity Registry key.
     * @param string $batch snapshot_batch UUID shared by this export run.
     * @param array|null $registry Pre-resolved effective registry; when null
     *        it is resolved via registry_with_overrides() so a standalone call
     *        still honours admin overrides / custom datatypes.
     * @param string $select Optional WHERE clause (no "WHERE" keyword) for an
     *        incremental export, e.g. "timemodified > :cdcwm". Empty = full table.
     * @param array|null $params Bound params for $select.
     * @param array|null $outcome Out-param. Receives
     *        ['complete' => bool, 'rows' => int, 'dropped' => int, 'error' => ?string,
     *        'stopped' => bool, 'lastid' => ?int, 'maxwm' => string|int|float|null].
     *        `complete` is true only when the scan ran to the end, which is what
     *        export_incremental() requires before it may advance a watermark past
     *        this window. `stopped` is true when $stoponrefusal ended the scan
     *        early; `lastid` is the highest id the scan settled (accepted, or
     *        refused for good) before that point. `maxwm` is the highest value of
     *        the entity's watermark column over those same settled rows, raw and
     *        uncast, ignoring NULL/0 (clockless) values; null when the entity has
     *        no watermark column or no settled row carried one. It lets a
     *        watermarked caller take its next mark from the rows this scan read
     *        instead of a separate SELECT MAX(). Optional, so every existing
     *        caller is unaffected.
     * @param bool $stoponrefusal End the scan at the first record the buffer
     *        refuses for a TRANSIENT reason (full, unpaired, write failure)
     *        instead of skipping past it. Only for callers that resume from
     *        `lastid` (export_incremental) or re-run the whole scan
     *        (targeted_refetch, via export_entity_range()); every other caller
     *        keeps the skip-and-continue behaviour, which ships the rows accepted
     *        after the buffer drains. Permanent refusals are always skipped.
     * @return int Rows exported for this entity.
     */
    public static function export_entity(
        string $entity,
        string $batch,
        ?array $registry = null,
        string $select = '',
        ?array $params = null,
        ?array &$outcome = null,
        bool $stoponrefusal = false
    ): int {
        global $DB, $CFG;

        $outcome = ['complete' => false, 'rows' => 0, 'dropped' => 0, 'error' => null,
            'stopped' => false, 'lastid' => null, 'maxwm' => null];

        // Every bulk export path funnels through here, so this is the one place
        // that keeps a long run's buffer file from looking abandoned. See
        // buffer::keepalive(): an entity that yields no rows appends nothing, and
        // a stretch of those is enough for the shipper's sweeper to take the file
        // out from under a writer that is still holding it open.
        buffer::keepalive();

        $registry = $registry ?? self::registry_with_overrides();
        if (!isset($registry[$entity])) {
            mtrace("local_intellistream: unknown entity '{$entity}' — skipped.");
            return 0;
        }

        $table = $registry[$entity]['table'];
        // Derived entities (see registry()) carry no 'columns' key — they are
        // computed by a bespoke method, not streamed as a table snapshot.
        $columns = $registry[$entity]['columns'] ?? null;

        // Tolerate a table missing on a given site (optional sub-plugins,
        // older Moodle) rather than aborting the whole run.
        if (!\local_intellistream\services\config_service::table_is_real($table)) {
            mtrace("local_intellistream: table '{$table}' absent — entity '{$entity}' skipped.");
            return 0;
        }

        // Derived entities are not straight table snapshots: they compute
        // their rows in a bespoke method rather than streaming a recordset.
        // Resolved AFTER this branch so a derived entity's backing table is
        // never introspected.
        if (!empty($registry[$entity]['derived'])) {
            $error = null;
            $rows = self::export_derived($entity, $batch, $error);
            // Reported like any other entity, so a caller that checks the outcome
            // (the verification sweep) can tell a finished derived export from a failed one.
            $outcome['rows'] = $rows;
            $outcome['error'] = $error;
            $outcome['complete'] = $error === null;
            return $rows;
        }

        // Narrow the declared list to what this Moodle actually has, so one
        // absent column costs that column instead of the whole entity.
        $resolved = self::resolve_entity_columns(
            $entity,
            $table,
            $columns,
            $registry[$entity]['wmcol'] ?? null
        );
        $columns = $resolved['columns'];
        // Reported back through $outcome as `maxwm`. watermark_column() derives it
        // FROM the surviving list, so it is a column this SELECT returns.
        $wmcol = $resolved['wmcol'];
        if ($columns === null) {
            mtrace("local_intellistream: entity '{$entity}' — no declared column of "
                . "'{$table}' exists on this site (table-name collision?) — skipped.");
            return 0;
        }

        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $chunk = config::export_batch_size();

        // An empty $select is the ONLY case that streams the whole table (the historical
        // backfill's derived path, a whole-table entity of the verification sweep, and
        // export_entity_range()'s fallback for a timestamp-less entity). Everything
        // else is a bounded window — the 15-minute incremental lane, or a targeted
        // re-fetch — where a derived value must be point-read:
        // building a whole-table lookup map to name three changed rows, 96 times a
        // day, would be far more expensive than the thing it optimises.
        $bulk = ($select === '');

        $rows = 0;
        $sincechunk = 0;

        // The role entity needs Moodle's display-name resolution (see below);
        // load accesslib once up front rather than per row.
        if ($entity === 'role') {
            require_once($CFG->libdir . '/accesslib.php');
        }

        $dropped = 0;
        $rs = null;
        try {
            $rs = $DB->get_recordset_select($table, $select, $params, 'id ASC', $columns);
            foreach ($rs as $row) {
                if (
                    !self::buffer_entity_row(
                        $siteid,
                        $entity,
                        $row,
                        $pluginversion,
                        $moodleversion,
                        $batch,
                        $bulk,
                        $reason
                    )
                ) {
                    // Refused by the buffer — over-size record, disk cap, or an
                    // unpaired site. buffer records its own durable marker; what
                    // matters here is not counting it as shipped.
                    if ($stoponrefusal && $reason !== buffer::REFUSED_PERMANENT) {
                        // The buffer cannot take rows right now. Stop here rather
                        // than read on past a row nobody will retry: the caller
                        // resumes from `lastid`, which is below this row.
                        $outcome['stopped'] = true;
                        break;
                    }
                    $dropped++;
                    if ($reason === buffer::REFUSED_PERMANENT) {
                        $outcome['lastid'] = (int)$row->id;   // Settled: can never be written.
                        self::note_maxwm($outcome, $row, $wmcol);
                    }
                    continue;
                }
                $outcome['lastid'] = (int)$row->id;
                self::note_maxwm($outcome, $row, $wmcol);
                $rows++;
                $sincechunk++;

                // Yield between chunks: keep memory flat and give the host a
                // breather. This is a background job, not the hot path.
                if ($sincechunk >= $chunk) {
                    $sincechunk = 0;
                    mtrace("local_intellistream: {$entity} — {$rows} rows buffered...");
                    usleep(1000);
                }
            }
            // Reached only when the scan ran to the end, or was stopped above.
            $outcome['complete'] = !$outcome['stopped'];
        } catch (\Throwable $e) {
            $outcome['error'] = $e->getMessage();
            mtrace("local_intellistream: entity '{$entity}' export error: " . $e->getMessage());
        } finally {
            // Close on EVERY exit, not just the happy path.
            // Several of these loops throw by design, so a close() placed after
            // the foreach leaked the cursor on the paths that matter most.
            if ($rs instanceof \moodle_recordset) {
                $rs->close();
            }
        }

        $outcome['rows'] = $rows;
        $outcome['dropped'] = $dropped;
        if ($dropped > 0) {
            // Reported, but deliberately NOT folded into `complete`. A PERMANENT
            // refusal (unserialisable, or over MAX_EVENT_BYTES) would fail on
            // every retry, so a caller that refused to advance past it would wedge
            // the lane; losing a named, counted record is bounded. A TRANSIENT
            // refusal is counted here only for callers that did not ask to stop
            // on one (see $stoponrefusal) — the verification sweep, the backfill's
            // derived path — and each of those, like a targeted re-fetch, reads
            // buffer::transient_refusals() so it does not report itself complete.
            // buffer sets its own durable capacity marker, which is what surfaces
            // a full buffer on the status page.
            mtrace("local_intellistream: {$entity} — {$dropped} row(s) REFUSED by the buffer "
                . 'and not retried by this pass.');
        }

        // A bounded read that found nothing (the incremental lane's window on an idle
        // entity, most of every 15-minute run) says nothing: that run prints one
        // summary line instead, and these lines land in task_log.output. A whole-table
        // read, anything refused, a stop or an error is always reported.
        if (!($rows === 0 && $dropped === 0 && $select !== '' && empty($outcome['stopped']) && $outcome['error'] === null)) {
            mtrace("local_intellistream: {$entity} — {$rows} rows exported (batch {$batch}).");
        }
        return $rows;
    }

    /**
     * Raise $outcome['maxwm'] to this settled row's watermark value.
     *
     * Called exactly where `lastid` is set, so the mark covers the same rows a
     * resume point does: accepted, or refused for good. A row refused for now is
     * not settled and must not raise it. NULL and 0 are skipped (clockless rows
     * move the id lane, never the timestamp one). Compared as a float, never as
     * a string, and stored raw: task_log.timeend is fractional and
     * export_task_state() keeps its full precision.
     *
     * @param array $outcome export_entity()'s out-param, updated in place.
     * @param \stdClass $row Row as fetched.
     * @param string|null $wmcol Resolved watermark column, or null for none.
     * @return void
     */
    private static function note_maxwm(array &$outcome, \stdClass $row, ?string $wmcol): void {
        if ($wmcol === null || !isset($row->{$wmcol}) || !is_numeric($row->{$wmcol})) {
            return;
        }
        $value = $row->{$wmcol};
        if ((float)$value > 0 && ($outcome['maxwm'] === null || (float)$value > (float)$outcome['maxwm'])) {
            $outcome['maxwm'] = $value;
        }
    }

    /**
     * Whether this row is clockless — carrying a watermark the incremental
     * predicate can never satisfy.
     *
     * Reads a property off a row the caller already fetched — no query, no
     * second pass. NULL and 0 are the two watermark values that make
     * `wmcol > :since` unsatisfiable for a row for ever, which is precisely
     * what makes it invisible to the 15-minute lane.
     *
     * A pure test: the verification sweep tallies the result (sweep_window()),
     * evaluating it before asking the buffer to take the row.
     *
     * @param \stdClass $row Row as fetched.
     * @param string $wmcol Resolved watermark column.
     * @return bool True when the watermark is NULL or 0.
     */
    private static function is_clockless_row(\stdClass $row, string $wmcol): bool {
        $wmvalue = isset($row->{$wmcol}) ? $row->{$wmcol} : null;
        return $wmvalue === null || (int)$wmvalue === 0;
    }

    /**
     * Export one entity restricted to a [from, to] time window (targeted
     * re-fetch). Thin wrapper over export_entity()'s WHERE-clause path.
     *
     * The window is applied on the entity's detected change-timestamp column —
     * the SAME column watermark_column() drives incremental export on
     * (timemodified/timecreated/… or a per-entity `wmcol` override). Entities
     * with NO such column, and derived aggregates, cannot be windowed, so the
     * whole table is re-emitted (a full re-fetch); downstream deterministic
     * uuid5 ids + the middleware content-hash dedup make the overlap harmless.
     *
     * Reads/writes NO watermark config: this is a repair emit, independent of
     * the incremental (`cdc_state`) and historical-backfill (`backfill_*`) state.
     *
     * @param string $entity Registry key.
     * @param string $batch snapshot_batch UUID shared by this run.
     * @param array|null $registry Pre-resolved effective registry (or null).
     * @param int|null $from Window start (epoch seconds), inclusive.
     * @param int|null $to Window end (epoch seconds), inclusive.
     * @param bool $stoponrefusal Passed to export_entity(): end the scan at the
     *        first record the buffer refuses for now, rather than reading the rest
     *        of the table to have every row refused too. For targeted_refetch,
     *        which reports the run incomplete and is run again whole.
     * @return int Rows exported for this entity.
     */
    public static function export_entity_range(
        string $entity,
        string $batch,
        ?array $registry = null,
        ?int $from = null,
        ?int $to = null,
        bool $stoponrefusal = false
    ): int {
        buffer::keepalive(); // See export_entity().
        $registry = $registry ?? self::registry_with_overrides();
        if (!isset($registry[$entity])) {
            mtrace("local_intellistream: unknown entity '{$entity}' — skipped.");
            return 0;
        }
        $def = $registry[$entity];

        // Derived aggregates and any entity with no recognised change-timestamp
        // column cannot be time-windowed: re-emit the whole table.
        $outcome = null;
        if (!empty($def['derived']) || $from === null || $to === null) {
            return self::export_entity($entity, $batch, $registry, '', null, $outcome, $stoponrefusal);
        }
        // Resolve against the live schema first: a drifted entity whose declared
        // timestamp column is absent would otherwise build a WHERE on a column
        // that is not there and return 0 rows, which on this path is a customer
        // clicking "re-fetch" and seeing nothing happen. Falling through to
        // export_entity() both re-fetches everything and reports the drift once.
        $wmcol = self::resolve_entity_columns(
            $entity,
            $def['table'],
            $def['columns'] ?? null,
            $def['wmcol'] ?? null
        )['wmcol'];
        if ($wmcol === null) {
            // No time column → full re-fetch.
            return self::export_entity($entity, $batch, $registry, '', null, $outcome, $stoponrefusal);
        }
        return self::export_entity(
            $entity,
            $batch,
            $registry,
            "{$wmcol} >= :ib_from AND {$wmcol} <= :ib_to",
            ['ib_from' => $from, 'ib_to' => $to],
            $outcome,
            $stoponrefusal
        );
    }

    /**
     * Build and buffer one entity_snapshot record for a Moodle row.
     *
     * Extracted so the whole-table export (export_entity), the verification sweep and the resumable
     * historical backfill (export_entity_window) emit BYTE-IDENTICAL payloads:
     * same deterministic id, same envelope keys/order, same role display-name
     * resolution. A regression test pins this shape — do not diverge the two
     * callers' payloads.
     *
     * @param string $siteid pairing/tenant id
     * @param string $entity registry key
     * @param \stdClass $row raw table row (must carry ->id)
     * @param int $pluginversion plugin version code
     * @param int|null $moodleversion Moodle $CFG->version, or null
     * @param string $batch shared snapshot_batch UUID
     * @param bool $bulk Whether the caller is streaming a whole table. Affects
     *        only HOW a derived value is fetched (map-cached vs point read),
     *        never WHAT is emitted — the payload is identical either way, which
     *        is what keeps the three callers byte-identical.
     * @param string|null $reason Out-param: buffer::REFUSED_PERMANENT or
     *        buffer::REFUSED_TRANSIENT on refusal, null on acceptance.
     * @return bool Whether the buffer accepted the record. Was void, which
     *         discarded buffer::append_record()'s false — an over-size record or
     *         a full buffer counted as shipped and let the caller advance a
     *         watermark past it.
     */
    private static function buffer_entity_row(
        string $siteid,
        string $entity,
        \stdClass $row,
        int $pluginversion,
        ?int $moodleversion,
        string $batch,
        bool $bulk = true,
        ?string &$reason = null
    ): bool {
        return self::buffer_entity_data(
            $siteid,
            $entity,
            $row->id,
            self::entity_row_data($entity, $row, $bulk),
            $pluginversion,
            $moodleversion,
            $batch,
            $reason
        );
    }

    /**
     * The `entity_data` a row is shipped as: the row plus the derived display values
     * some entities carry. Split out of buffer_entity_row() so the verification sweep
     * fingerprints exactly what would be sent, without sending it.
     *
     * @param string $entity registry key
     * @param \stdClass $row raw table row
     * @param bool $bulk See buffer_entity_row().
     * @return array
     */
    private static function entity_row_data(string $entity, \stdClass $row, bool $bulk): array {
        $data = (array)$row;
        if ($entity === 'role') {
            $data['name'] = self::role_display_name($row);
        } else if ($entity === 'modules') {
            // The modules table has no display-name column; resolve it from the
            // module's own language pack so a localised site stops shipping shortnames.
            [$data['title'], $data['titleplural']] =
                self::module_display_names((string)($row->name ?? ''));
        } else if ($entity === 'course_modules') {
            // The activity's own title, resolved against whatever module-instance
            // table this row points at — including module types the registry does
            // not export (core `subsection`/`qbank`, and every third-party module).
            $data['instancename'] = self::activity_instance_name($row, $bulk);
        }
        return $data;
    }

    /**
     * Buffer one entity_snapshot record from already-built `entity_data`.
     *
     * @param string $siteid pairing/tenant id
     * @param string $entity registry key
     * @param mixed $rowid the row's id
     * @param array $data from entity_row_data()
     * @param int $pluginversion plugin version code
     * @param int|null $moodleversion Moodle $CFG->version, or null
     * @param string $batch shared snapshot_batch UUID
     * @param string|null $reason See buffer_entity_row().
     * @return bool Whether the buffer accepted the record.
     */
    private static function buffer_entity_data(
        string $siteid,
        string $entity,
        $rowid,
        array $data,
        int $pluginversion,
        ?int $moodleversion,
        string $batch,
        ?string &$reason = null
    ): bool {
        $payload = [
            'id'             => self::entity_uuid($siteid, $entity, $rowid),
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => $pluginversion,
            'moodle_version' => $moodleversion,
            'record_type'    => 'entity_snapshot',
            'entity'         => $entity,
            'snapshot_batch' => $batch,
            'entity_data'    => $data,
        ];
        return buffer::append_record($payload, $reason);
    }

    /**
     * Hot-path-safe, mtrace-free capture of the entity rows matching $conditions.
     *
     * Used by the per-entity event observers (see observers/entity_observer.php)
     * to snapshot a changed definition row the instant Moodle fires its
     * create/update event, so timestamp-less tables (course_modules, etc.) reach
     * the warehouse in ~1 min via ship_events instead of waiting for the daily
     * verification pass. The payload is BYTE-IDENTICAL to the one the sweep sends
     * (same entity_uuid, same envelope via buffer_entity_row) so the downstream
     * UPSERT-on-id merges it in place — no duplicates.
     *
     * MUST NOT be confused with export_entity(): that method emits mtrace()
     * output (fine in cron, corrupts a web page) and logs. This one is silent
     * and swallows ALL throwables — it runs on the host page render path.
     *
     * Deletes are intentionally NOT handled here; row removals are reconciled by
     * the daily entity_census (there is no per-record delete signal).
     *
     * @param string $entity Registry key (non-derived table entity).
     * @param array  $conditions Equality conditions passed to get_records (e.g. ['id' => 5]).
     */
    public static function capture_entity_match(string $entity, array $conditions): void {
        global $DB, $CFG;
        try {
            if (!config::enabled()) {
                return;
            }
            $siteid = config::site_id();
            if ($siteid === '') {
                return; // Unpaired — buffer would no-op anyway.
            }
            $registry = self::registry_with_overrides();
            if (!isset($registry[$entity]) || !empty($registry[$entity]['derived'])) {
                return;
            }
            $table = $registry[$entity]['table'] ?? null;
            if ($table === null) {
                return;
            }
            $columns = $registry[$entity]['columns'] ?? '*';
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                return;
            }
            // Same narrowing as export_entity(), so an entity that is drifted on
            // this site still captures its surviving columns here instead of
            // throwing into the catch below and losing the row entirely. Costs one
            // get_columns() on one table — cached after the first observed event.
            $columns = self::resolve_entity_columns(
                $entity,
                $table,
                $columns,
                $registry[$entity]['wmcol'] ?? null
            )['columns'];
            if ($columns === null) {
                return;
            }
            $pluginversion = (int) config::plugin_version();
            $moodleversion = isset($CFG->version) ? (int) $CFG->version : null;
            $batch = \core\uuid::generate();
            // Recordset, not get_records(): the observers all match a small indexed
            // set, but this method is public and its $conditions are the caller's,
            // so a wide match would previously have held every row in memory at
            // once. Streaming bounds the memory
            // whatever is asked for.
            $rs = $DB->get_recordset($table, $conditions, '', $columns);
            try {
                foreach ($rs as $row) {
                    // Not bulk: this is the page-render observer path, matching a small
                    // indexed set. It must point-read a derived value, never build a
                    // whole-table map. Same payload as the bulk path either way.
                    self::buffer_entity_row(
                        $siteid,
                        $entity,
                        $row,
                        $pluginversion,
                        $moodleversion,
                        $batch,
                        false
                    );
                }
            } finally {
                $rs->close();
            }
        } catch (\Throwable $e) {
            // Observer hot path: capture must never break the host page render.
            return;
        }
    }

    /**
     * Convenience wrapper: capture a single entity row by primary key.
     *
     * @param string $entity Registry key.
     * @param int|string $pk Primary key of the row to capture.
     */
    public static function capture_entity_row(string $entity, $pk): void {
        self::capture_entity_match($entity, ['id' => $pk]);
    }

    /**
     * Resumable historical-backfill export of ONE keyset entity.
     *
     * Streams the whole table in `id ASC` order past a persisted per-entity
     * watermark (`id > :backfillwm`) with NO row limit — one continuous pass,
     * not a fixed-size chunk. The watermark is advanced (per page) ONLY after
     * the backlog has been checked against half the disk cap, so a crash /
     * timeout / reboot resumes from where it stopped instead of from zero, and
     * the backfill cannot fill the buffer to the point where capture is refused.
     *
     * On a mid-stream error the progress made so far is persisted and the
     * exception is RE-THROWN so the caller does NOT mark the entity complete
     * (unlike export_entity(), which swallows errors for the best-effort full
     * snapshot) — a re-run then resumes past the last durably-shipped id.
     *
     * A refused append takes that same path: the buffer declining a row is
     * treated as a mid-stream error precisely so the watermark stops behind it.
     *
     * @param string $entity Registry key (must be a non-derived, id-keyed table).
     * @param string $batch  snapshot_batch UUID shared by this backfill campaign.
     * @param array|null $registry Pre-resolved effective registry.
     * @param string $wmkey  Config key holding this entity's watermark.
     * @param bool $seed Whether to stage block fingerprints from what is sent (a
     *        new install, see change_ledger).
     * @return int Rows buffered on this run.
     */
    public static function export_entity_window(
        string $entity,
        string $batch,
        ?array $registry,
        string $wmkey,
        bool $seed = false
    ): int {
        global $DB, $CFG;

        buffer::keepalive(); // See export_entity().
        $registry = $registry ?? self::registry_with_overrides();
        if (!isset($registry[$entity])) {
            mtrace("local_intellistream: unknown entity '{$entity}' — skipped.");
            return 0;
        }

        $table = $registry[$entity]['table'];
        $columns = $registry[$entity]['columns'] ?? null;

        // Derived entities have no monotonic id — the caller must route them to
        // export_entity()/export_derived(). Guard defensively so a mis-route is
        // a no-op-with-warning, not a broken keyset query.
        if (!empty($registry[$entity]['derived'])) {
            mtrace("local_intellistream: derived entity '{$entity}' is not keyset-resumable — skipped.");
            return 0;
        }

        if (!\local_intellistream\services\config_service::table_is_real($table)) {
            mtrace("local_intellistream: table '{$table}' absent — entity '{$entity}' skipped.");
            return 0;
        }

        // As export_entity(). Returns 0 rather than throwing: $lastid never
        // advances, so the caller correctly leaves the entity not-done, and one
        // permanently unresolvable entity cannot abort the whole backfill campaign.
        $resolvedcols = self::resolve_entity_columns(
            $entity,
            $table,
            $columns,
            $registry[$entity]['wmcol'] ?? null
        );
        $columns = $resolvedcols['columns'];
        if ($columns === null) {
            mtrace("local_intellistream: entity '{$entity}' — no declared column of "
                . "'{$table}' exists on this site (table-name collision?) — skipped.");
            return 0;
        }
        $fpskip = self::fp_skip_column($registry[$entity], $resolvedcols['wmcol']);

        $siteid = config::site_id();
        // Defensive twin of the guard in backfill::run(). Every append is refused
        // while unpaired, so scanning here could only produce a run of TRANSIENT
        // refusals, the first of which stops the scan below. Returning 0 before the
        // first read leaves the watermark exactly where it was without reading
        // anything, the same contract the two unresolvable-entity returns above use.
        if ($siteid === '') {
            mtrace("local_intellistream: site id not set (unpaired) — entity '{$entity}' "
                . 'not scanned and its watermark left untouched.');
            return 0;
        }
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $chunk = config::export_batch_size();

        // The role entity needs Moodle's display-name resolution; load once.
        if ($entity === 'role') {
            require_once($CFG->libdir . '/accesslib.php');
        }

        $startwm = (int)get_config(config::COMPONENT, $wmkey);
        $lastid = $startwm;
        $rows = 0;
        $refused = 0;
        $sincechunk = 0;

        // Fingerprint seeding (new installs): hash each block from the very data sent,
        // as the verification sweep would, so its first pass does not send the table
        // again. Only blocks this call saw from their first id to their last are kept;
        // the block a resume starts inside, and one a stop cuts, are left for the sweep.
        $n = change_ledger::FP_SIZE;
        $seedprefix = $seed ? change_ledger::fingerprint() . "\0" . $entity . "\0" : '';
        $seedentries = '';
        $seedctx = null;
        $seedblock = null;
        $seedskip = $startwm > 0 ? intdiv($startwm, $n) : null;   // Partly sent before.
        $seedfresh = $startwm === 0;   // The first call of a campaign starts the set afresh.

        $rs = null;
        try {
            // Keyset resume: no LIMIT — a single ordered pass past the watermark.
            $rs = $DB->get_recordset_select(
                $table,
                'id > :backfillwm',
                ['backfillwm' => $startwm],
                'id ASC',
                $columns
            );
            foreach ($rs as $row) {
                // Honour the refusal. buffer_entity_row() returns false when the
                // buffer would not take the record, and its docblock says the
                // return value exists precisely so a caller does not advance a
                // watermark past it. Discarding it counted a refused row as
                // shipped and moved the resume point beyond it, so the backfill
                // never re-read it — up to exportbatchsize rows per chunk silently
                // missing, recoverable only by the verification sweep, which can
                // be disabled.
                //
                /* Which refusal it was decides what to do, and getting this wrong
                   trades one defect for another. The buffer says which it was
                   ($reason): deciding it here by re-measuring the buffer instead
                   took a refused row for a permanent one whenever the measurement
                   disagreed (a failed write, a buffer just under its cap), and
                   skipped it for good.

                   - TRANSIENT (the buffer is full, the site is unpaired, a write
                     failed): it clears, so the row will be accepted on a later
                     pass. Throw, which takes the mid-stream path below: progress
                     is persisted up to the last ACCEPTED id and the entity is left
                     not-done, so the next run resumes exactly here and nothing is
                     lost.
                   - PERMANENT (a single row over MAX_EVENT_BYTES, or one that will
                     not JSON-encode): retrying can never succeed, so stopping here
                     would wedge this entity's backfill forever on one row — and
                     rows of that size do occur in practice. Count it, say so
                     loudly, and carry on past it, exactly as the sibling
                     export_entity() does. */
                $data = self::entity_row_data($entity, $row, true);
                if ($seed) {
                    $b = intdiv((int)$row->id, $n);
                    if ($b !== $seedblock) {
                        if ($seedctx !== null) {
                            $seedentries .= change_ledger::entry($seedblock, hash_final($seedctx, true));
                            if (strlen($seedentries) >= change_ledger::SEED_ROW_BLOCKS * change_ledger::ENTRY_BYTES) {
                                // Staged a row at a time, the size a sweep window reads, so a large
                                // table never builds one huge string and a window never reads a big row.
                                self::seed_stage($entity, $seedentries, $seedfresh);
                                $seedfresh = false;
                                $seedentries = '';
                            }
                        }
                        $seedblock = $b;
                        $seedctx = ($b === $seedskip) ? null : hash_init('sha256');
                        if ($seedctx !== null) {
                            hash_update($seedctx, $seedprefix);
                        }
                    }
                    if ($seedctx !== null) {
                        self::hash_row($seedctx, $data, $fpskip);
                    }
                }
                $reason = null;
                if (!self::buffer_entity_data($siteid, $entity, $row->id, $data, $pluginversion, $moodleversion, $batch, $reason)) {
                    // Anything but PERMANENT stops here: a state of the SITE, not of
                    // the row, which gets fixed — so it may not advance the watermark.
                    if ($reason !== buffer::REFUSED_PERMANENT) {
                        throw new \RuntimeException(
                            'buffer would not accept a row while backfilling \'' . $entity . '\' at id '
                            . (isset($row->id) ? (int)$row->id : '?')
                            . ' — the buffer is at its disk cap, the site is unpaired, or the write '
                            . 'failed. Stopped short of that row so the watermark cannot pass it. '
                            . 'Resumable: the next pass continues from the watermark.',
                            self::REFUSED_TRANSIENT_CODE
                        );
                    }
                    $refused++;
                    mtrace("local_intellistream: {$entity} — row id "
                        . (isset($row->id) ? (int)$row->id : '?')
                        . ' REFUSED by the buffer and NOT exported (over-size record, or it would '
                        . 'not encode). Permanent for this row, so the backfill continues past it '
                        . 'rather than stalling; the row is reported here because nothing else '
                        . 'will surface it.');
                    // The watermark MUST advance past a permanently-refused row, or
                    // the next pass re-reads it, refuses it again and never gets
                    // further — the same wedge, just quieter. This is the one case
                    // where moving the watermark past an unexported row is correct,
                    // and it is correct only because the line above makes it visible.
                    if (isset($row->id) && (int)$row->id > $lastid) {
                        $lastid = (int)$row->id;
                    }
                    continue;
                }
                $rows++;
                $sincechunk++;
                if (isset($row->id) && (int)$row->id > $lastid) {
                    $lastid = (int)$row->id;
                }

                if ($sincechunk >= $chunk) {
                    $sincechunk = 0;
                    // Keep the backlog well under the disk cap BEFORE advancing the
                    // watermark, so the backfill never fills the buffer to the point
                    // where live capture is refused. May pause (host load) or throw
                    // (cannot drain) — then the caller resumes later.
                    self::backfill_apply_backpressure();
                    if ($lastid > (int)get_config(config::COMPONENT, $wmkey)) {
                        set_config($wmkey, $lastid, config::COMPONENT);
                    }
                    mtrace("local_intellistream: {$entity} — {$rows} rows buffered (watermark id {$lastid})...");
                }
            }
        } catch (\Throwable $e) {
            // Persist progress so the re-run resumes past it, then propagate so
            // the caller leaves the entity NOT done.
            if ($lastid > $startwm) {
                set_config($wmkey, $lastid, config::COMPONENT);
            }
            if ($seed) {
                // The block being read when it stopped is cut: not kept.
                self::seed_stage($entity, $seedentries, $seedfresh);
            }
            mtrace("local_intellistream: entity '{$entity}' backfill error: " . $e->getMessage());
            throw $e;
        } finally {
            // Close on EVERY exit, not just the happy path.
            // Several of these loops throw by design, so a close() placed after
            // the foreach leaked the cursor on the paths that matter most.
            if ($rs instanceof \moodle_recordset) {
                $rs->close();
            }
        }

        // Tail page: advance the watermark to the final id scanned.
        if ($lastid > $startwm) {
            set_config($wmkey, $lastid, config::COMPONENT);
        }
        if ($seed) {
            if ($seedctx !== null) {
                $seedentries .= change_ledger::entry($seedblock, hash_final($seedctx, true));
            }
            self::seed_stage($entity, $seedentries, $seedfresh);
        }

        if ($refused > 0) {
            mtrace("local_intellistream: {$entity} — {$refused} row(s) PERMANENTLY REFUSED by the "
                . 'buffer and not exported. The watermark has moved past them because retrying '
                . 'cannot help; they will not appear downstream until the row itself is smaller.');
        }
        mtrace("local_intellistream: {$entity} — {$rows} rows backfilled (batch {$batch}).");
        return $rows;
    }

    /**
     * Exception code for "the buffer refused a record it may take later", or "the
     * backlog could not be drained" (backfill_apply_backpressure()). A state of the
     * site (full, unpaired, a failed write, a loaded host), not of the entity, so the backfill
     * does not count it towards giving up on the entity (backfill::note_no_progress()).
     */
    const REFUSED_TRANSIENT_CODE = 1520;

    /**
     * Stage the fingerprints one backfill call collected, as pending for the entity.
     * The first call of a campaign (watermark 0) starts the entity's pending set afresh,
     * so parts always follow block order.
     *
     * @param string $entity
     * @param string $entries Packed entries in block order.
     * @param bool $fresh First call of the campaign for this entity.
     * @return void
     */
    private static function seed_stage(string $entity, string $entries, bool $fresh): void {
        try {
            if ($fresh) {
                change_ledger::clear_entity($entity);
            }
            change_ledger::stage($entity, $entries, (int)get_config(config::COMPONENT, 'backfill_t0_' . $entity));
        } catch (\Throwable $e) {
            // Seeding is an optimisation: without it the sweep's first pass sends the
            // table again, which is today's behaviour. Never fail the backfill for it.
            change_ledger::clear_entity($entity);
            mtrace("local_intellistream: {$entity} — fingerprint seeding skipped: " . $e->getMessage());
        }
    }

    /**
     * Whether the verification sweep may keep writing: false when the buffer backlog is
     * over the gate and one shipping pass could not drain it (same test the historical
     * backfill applies).
     *
     * @return bool
     */
    public static function sweep_backpressure_ok(): bool {
        try {
            self::backfill_apply_backpressure();
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Backfill flow-control: keep the buffer backlog well under
     * `config::max_buffer_bytes()`, so the backfill never takes the buffer to its
     * cap, where new capture — live events included — is refused.
     *
     * Respects the host load gate: `shipper::run()` ships nothing while load is
     * over the gate, so under load we simply pause (do not buffer more) until it
     * drains. This is NOT chunking: there is no per-run row budget and no
     * schedule; it is pure backpressure that bounds the on-disk outbox.
     *
     * Drives ONE shipping pass and throws if that did not clear the backlog. An
     * earlier version slept in a `sleep(5)` loop for up to five minutes waiting
     * for the backlog to drain, which held a cron or adhoc-task slot doing
     * nothing, which is antisocial on contended shared infrastructure. Throwing
     * achieves the same result without
     * occupying the slot: the backfill is resumable, so the next pass continues
     * from the last durably-shipped watermark. The only thing lost is progress
     * within the current run, which the resume design already handles.
     */
    private static function backfill_apply_backpressure(): void {
        $dir  = config::buffer_dir();
        $cap  = config::max_buffer_bytes();
        $safe = (int)($cap / 2); // Keep the backlog under half the cap.

        // Judged on what buffer::have_capacity() counts — active + closed +
        // `.pulled` + parked. This used to count only `*.jsonl.closed`, so the test
        // that decides "keep streaming" and the test that decides "accept this
        // append" could disagree. It also used to size the whole directory on every
        // call: once per 500 rows here, and once per window and per whole-table
        // entity in the verification sweep, so a large backlog made each of those
        // calls a full scan. It reads the shipper's published measurement instead,
        // plus everything this process has appended since it (see
        // backlog_within()); only after the inline ship below, and only when that
        // measurement is unusable, does it measure.
        if (self::backlog_within($dir, $safe, false)) {
            return; // Headroom — keep streaming.
        }

        // Drive a shipping pass (gated by host load, exactly like the
        // ship_events task). Flush our own open file first so its rows
        // become shippable rather than lingering until age-rotation. The pass
        // republishes the measurement and restarts this process's own count.
        buffer::flush();
        shipper::run();
        if (self::backlog_within($dir, $safe, true)) {
            return;
        }

        throw new \RuntimeException(
            'backfill paused: buffer backlog is over half the disk cap and one shipping pass '
            . 'could not drain it (host load over the ship gate, or object storage unreachable) '
            . '— the run is resumable and continues from the watermark on the next pass.',
            self::REFUSED_TRANSIENT_CODE
        );
    }

    /**
     * Whether the buffer is at most $safe bytes, as far as this process can tell.
     *
     * A fresh published measurement, projected forward at the shipper's observed
     * inflow, plus every byte this process appended since that measurement: the
     * projection is the rest of the site's inflow, and a long writer like this one
     * can add far more than that on its own between two ship runs, so its own
     * output is counted too (buffer::own_bytes_since()). A stale or missing
     * measurement is unknown, never headroom: without $exact the answer is then
     * "no" (the caller ships first); with it, the buffer is measured.
     *
     * @param string $dir Buffer directory.
     * @param int $safe Bytes the buffer may hold.
     * @param bool $exact Measure when the published measurement cannot answer.
     * @return bool
     */
    private static function backlog_within(string $dir, int $safe, bool $exact): bool {
        $state = buffer::fresh_capacity($dir);
        $own = $state !== null ? buffer::own_bytes_since($state['at']) : null;
        if ($own !== null) {
            return buffer::projected_bytes($state) + $own <= $safe;
        }
        return $exact && buffer::measure($dir) <= $safe;
    }

    /**
     * Resolve a role's display name exactly as Moodle renders it.
     *
     * `mdl_role.name` is empty for the standard archetype roles — Moodle resolves
     * their human label ("Teacher", "Non-editing teacher", …) at runtime from the
     * language pack via role_get_name(). A raw snapshot therefore carries an empty
     * name, and the downstream ETL falls back to the shortname, so role-name
     * reports (filtering `roles.name = 'Teacher'`) come up empty on V2.
     *
     * This restores the resolution the ancestor local_intellidata plugin performed
     * (entities/roles/migration.php, role.php): role_get_name() with no context
     * returns the custom name when one is set (so renamed roles are preserved) and
     * the localized default otherwise. accesslib.php is loaded once by the caller.
     *
     * @param \stdClass $role A row from {role} (has id, name, shortname, archetype).
     * @return string The display name; falls back to the raw name, then shortname.
     */
    private static function role_display_name(\stdClass $role): string {
        try {
            $name = trim((string) role_get_name($role));
            if ($name !== '') {
                return $name;
            }
        } catch (\Throwable $e) {
            // Reachable from an observer on a web request: see buffer::trace().
            buffer::trace('local_intellistream: role_get_name failed for role '
                . ($role->id ?? '?') . ': ' . $e->getMessage());
        }
        $raw = trim((string)($role->name ?? ''));
        return $raw !== '' ? $raw : (string)($role->shortname ?? '');
    }

    /**
     * Resolve a module type's display name and plural, localised, exactly as
     * Moodle renders them.
     *
     * `mdl_modules` has NO display-name column — it stores only the frankenstyle
     * shortname (`forum`, `quiz`, `assign`). Moodle resolves the human label from
     * the module's own language pack at render time, so a raw snapshot ships
     * `title = titleplural = shortname`. The warehouse mart then falls back to a
     * hard-coded English list, and a Spanish/Portuguese tenant's report that
     * filters `activity_types.name LIKE '%Foro%'` silently returns zero rows.
     *
     * This restores the resolution the ancestor local_intellidata plugin performed
     * (entities/modules/module.php::before_export()): get_string('modulename', …)
     * and get_string('modulenameplural', …), each guarded by string_exists() so a
     * module with no language pack degrades to its shortname rather than throwing
     * or emitting Moodle's `[[mod_x]]` missing-string placeholder.
     *
     * NOTE on language: no language is forced. `modules` is exported only from
     * cron (the 15-minute task and its sweep; the historical backfill; a targeted
     * re-fetch adhoc task) — it has no watermark column, so export_incremental()
     * skips it, and no observer captures it. Cron resolves to the site language,
     * which is the language the seeded IntelliData history was captured in and the
     * one customer report filters are written against. Forcing $CFG->lang here
     * would be a no-op that could only ever diverge from that baseline.
     *
     * @param string $shortname The `mdl_modules.name` frankenstyle shortname.
     * @return array{0:string,1:string} [title, titleplural]; both fall back to $shortname.
     */
    private static function module_display_names(string $shortname): array {
        $title = $plural = $shortname;
        if ($shortname === '') {
            return [$title, $plural];
        }
        try {
            $sm = get_string_manager();
            $component = 'mod_' . $shortname;
            if ($sm->string_exists('modulename', $component)) {
                $title = (string) get_string('modulename', $component);
            }
            if ($sm->string_exists('modulenameplural', $component)) {
                $plural = (string) get_string('modulenameplural', $component);
            }
        } catch (\Throwable $e) {
            // A broken language pack must never cost us the row; ship shortnames.
            return [$shortname, $shortname];
        }
        // A string file can legitimately resolve to '' — never ship a blank where
        // the shortname is a usable last resort (and where stg__activity_types'
        // `title <> name` predicate needs a real value to prefer).
        $title = trim($title) !== '' ? $title : $shortname;
        $plural = trim($plural) !== '' ? $plural : $shortname;
        return [$title, $plural];
    }

    /**
     * Above this many rows a module's instance table is looked up per row instead
     * of being map-cached, so one pathological table cannot blow up memory.
     */
    const INSTANCE_NAME_MAP_MAX = 100000;

    /**
     * Ceiling on TOTAL cached instance names across every module type.
     *
     * The per-table cap alone does not bound memory: a site with twenty module
     * types just under {@see INSTANCE_NAME_MAP_MAX} would still cache millions of
     * names. That matters because the historical backfill deliberately runs at
     * MEMORY_EXTRA (not HUGE) on the stated grounds that every path streams one
     * row at a time and memory stays flat — a cache that can grow without limit
     * would quietly falsify that. Once the budget is spent, further module types
     * fall back to point reads, which is slower but cannot OOM.
     *
     * 200k names is roughly 20 MB, against a MEMORY_EXTRA of at least 384 MB on
     * 64-bit PHP (512 MB with Moodle's default extramemorylimit), and
     * comfortably covers the largest tenant measured (77,186 activities).
     */
    const INSTANCE_NAME_TOTAL_MAX = 200000;

    /**
     * Names currently held across all of {@see $instancenames}.
     *
     * @var int
     */
    private static $instancenamesheld = 0;

    /**
     * `mdl_modules.id` => frankenstyle shortname, loaded once per process.
     *
     * @var array<int,string>|null
     */
    private static $moduleshortnames = null;

    /**
     * module shortname => whether its instance table can be read for a name at all
     * (it exists and has `id` + `name`). Independent of how we read it, so unlike
     * the map below this verdict is valid for every caller.
     *
     * @var array<string, bool>
     */
    private static $instancetableok = [];

    /**
     * module shortname => (instance id => name) map. Populated ONLY on a
     * whole-table scan, and only while {@see INSTANCE_NAME_TOTAL_MAX} allows; a
     * table absent from here is point-read, which is always correct, just slower.
     *
     * Deliberately NOT used to memoise "don't map this one": that verdict depends
     * on the caller ($bulk) and on the remaining budget, so caching it under a
     * table-name key would let one bounded incremental pass poison the next full
     * snapshot in the same cron process.
     *
     * @var array<string, array<int,string>>
     */
    private static $instancenames = [];

    /**
     * module shortnames we decided NOT to map on this run (too large, or the budget
     * was already spent), so the size check runs once per type instead of once per
     * row — a per-row `count_records()` would cost more than the point read it is
     * deciding about.
     *
     * Written ONLY on the whole-table path, so a bounded incremental pass can never
     * leave a verdict behind for a later whole-table read in the same cron process.
     *
     * @var array<string, true>
     */
    private static $instancenomap = [];

    /**
     * Drop the per-process module/instance name caches.
     *
     * Only matters to a process that exports twice (CLI, tests); each cron run is
     * its own process and so starts cold.
     */
    public static function reset_instance_name_cache(): void {
        self::$moduleshortnames = null;
        self::$instancetableok = [];
        self::$instancenames = [];
        self::$instancenomap = [];
        self::$instancenamesheld = 0;
    }

    /**
     * Resolve a course-module's activity instance name (the title a teacher typed).
     *
     * `mdl_course_modules` carries only (module, instance) — the name lives on the
     * per-module-type table (`mdl_forum.name`, `mdl_page.name`, …). The loader used
     * to reconstruct this downstream by joining the module-instance entities the
     * plugin happens to export, which structurally cannot cover a module type that
     * is not in the registry: `subsection` and `qbank` are CORE Moodle modules
     * (4.6+ / 5.0) and were shipping NULL, along with every third-party module.
     *
     * So resolve it here, on the LMS, against whatever table the row actually
     * points at — which is what local_intellidata did
     * (entities/activities/activity.php: `$DB->get_record($modulename, …)->name`)
     * and why it reached ~99.9% coverage. Only `id` and `name` are ever read; no
     * third-party table contents leave the site.
     *
     * Two access patterns, because the callers differ by three orders of magnitude:
     *   - $bulk = true  (whole-table reads / backfill): one `get_records_menu` per module
     *     type, cached. The largest tenant measured is 77,186 course-modules across
     *     21 types — 21 queries instead of 77,186 point reads on a customer's
     *     production database.
     *   - $bulk = false (the course_module_created/updated observers): a single
     *     point read. Building a 41k-row map to name ONE row would be absurd on a
     *     page-render path.
     * Both return identical values, so the payload stays byte-identical whichever
     * caller produced it.
     *
     * @param \stdClass $cm A `course_modules` row (needs ->module and ->instance).
     * @param bool $bulk Whether the caller is streaming a whole table.
     * @return string|null The instance name, or null when it cannot be resolved.
     */
    private static function activity_instance_name(\stdClass $cm, bool $bulk): ?string {
        global $DB;

        $moduleid = (int)($cm->module ?? 0);
        $instance = (int)($cm->instance ?? 0);
        if ($moduleid <= 0 || $instance <= 0) {
            return null;
        }

        try {
            $table = self::module_shortname($moduleid, $bulk);
            if ($table === null) {
                return null;
            }

            // Respect an explicit operator decision. This lane deliberately reads module
            // tables the registry has no entry for — that is the whole point, since core
            // `subsection`/`qbank` and every third-party module are otherwise unnameable.
            // But a CURATED datatype an admin has switched off is a different case: the
            // operator has said "do not export this", and reading its titles here would
            // route around that toggle exactly as it would route around the credential
            // filter. Absent from the registry entirely = never decided = export;
            // present but dropped by the overrides = decided against = skip.
            $curated = self::registry();
            if (isset($curated[$table]) && !isset(self::registry_with_overrides()[$table])) {
                return null;
            }

            // Decided once per module type, for every caller: is there a name to read?
            if (!array_key_exists($table, self::$instancetableok)) {
                self::$instancetableok[$table] = self::instance_table_usable($table);
            }
            if (!self::$instancetableok[$table]) {
                return null;
            }

            // Map-cache only on a whole-table scan, and only within budget. BOTH
            // outcomes are recorded, so the decision costs one query per module type
            // rather than a count_records() on every row.
            if (
                $bulk
                    && !isset(self::$instancenames[$table])
                    && !isset(self::$instancenomap[$table])
            ) {
                $map = self::build_instance_name_map($table);
                if ($map !== null) {
                    self::$instancenames[$table] = $map;
                    self::$instancenamesheld += count($map);
                } else {
                    self::$instancenomap[$table] = true;
                }
            }

            if (isset(self::$instancenames[$table])) {
                return self::filtered_instance_name(self::$instancenames[$table][$instance] ?? null, $table);
            }

            // Point read: the observer path, a bounded incremental window, or a
            // table we declined to map.
            $name = $DB->get_field($table, 'name', ['id' => $instance], IGNORE_MISSING);
            return self::filtered_instance_name($name === false ? null : $name, $table);
        } catch (\Throwable $e) {
            // Never cost the row: an unnamed activity is what we already ship today.
            return null;
        }
    }

    /**
     * Resolve a `course_modules.module` id to its frankenstyle shortname.
     *
     * Split out because the two callers have opposite cost profiles, and one of them is
     * the page-render observer path. Reading the whole `modules` menu there would be an
     * unbounded read on the hot path: small on today's sites, but a PHP memory-limit
     * exhaustion is a FATAL, not a \Throwable, so the blanket catch in
     * {@see capture_entity_match()} would NOT honour the "never break the host page
     * render" guarantee. A primary-key lookup cannot fail that way.
     *
     * @param int $moduleid `course_modules.module`.
     * @param bool $bulk Whether the caller is streaming a whole table.
     * @return string|null The shortname, or null when it cannot be resolved.
     */
    private static function module_shortname(int $moduleid, bool $bulk): ?string {
        global $DB;

        if (!$bulk) {
            $name = $DB->get_field('modules', 'name', ['id' => $moduleid], IGNORE_MISSING);
            return ($name === false || $name === null || $name === '') ? null : (string) $name;
        }
        if (self::$moduleshortnames === null) {
            self::$moduleshortnames = $DB->get_records_menu('modules', null, '', 'id, name');
        }
        $name = self::$moduleshortnames[$moduleid] ?? null;
        return ($name === null || $name === '') ? null : (string) $name;
    }

    /**
     * Apply the credential filter to a resolved activity instance name.
     *
     * {@see activity_instance_name()} reads a module table directly rather than through
     * {@see registry_with_overrides()}, so it does not inherit that funnel. The rule is
     * that EVERY export lane resolves through the same filter — the legacy-migration
     * lane does it with {@see strip_forbidden_row_keys()}, and so does this one. Today
     * the column is always `name`, which no entry forbids; routing it through anyway
     * means a future addition to {@see FORBIDDEN_COLUMNS} or
     * {@see FORBIDDEN_TABLE_COLUMNS} cannot be silently bypassed here.
     *
     * @param mixed $name Raw value read from the module's instance table.
     * @param string $table Module shortname, which is also its table name.
     * @return string|null The name, or null when absent, empty or filtered.
     */
    private static function filtered_instance_name($name, string $table): ?string {
        if ($name === null || $name === '') {
            return null;
        }
        $kept = self::strip_forbidden_row_keys(['name' => $name], $table);
        return array_key_exists('name', $kept) ? (string) $kept['name'] : null;
    }

    /**
     * Whether a module's instance table can yield a name at all.
     *
     * Caller-independent, so it is safe to memoise for the whole process: the
     * table either exists with `id` + `name` or it does not.
     *
     * @param string $table Module shortname, which is also its table name.
     * @return bool
     */
    private static function instance_table_usable(string $table): bool {
        global $DB;

        try {
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                return false;
            }
            $columns = $DB->get_columns($table);
        } catch (\Throwable $e) {
            return false;
        }
        return isset($columns['id']) && isset($columns['name']);
    }

    /**
     * Load one module type's (instance id => name) map, or null to decline.
     *
     * Declining is not an error — the caller then point-reads, which is always
     * correct. We decline when this one table is pathologically large, or when the
     * whole-cache budget is spent, so the optimisation can never turn into an OOM.
     * The table's existence and shape were already settled by
     * {@see instance_table_usable()}.
     *
     * @param string $table Module shortname, which is also its table name.
     * @return array<int,string>|null
     */
    private static function build_instance_name_map(string $table): ?array {
        global $DB;

        if (self::$instancenamesheld >= self::INSTANCE_NAME_TOTAL_MAX) {
            return null;
        }
        // What this one map may hold: the per-table cap, or whatever is left of the
        // process-wide budget — whichever binds first.
        $budget = min(
            self::INSTANCE_NAME_MAP_MAX,
            self::INSTANCE_NAME_TOTAL_MAX - self::$instancenamesheld
        );
        try {
            // Cheap decline first, so a table far over budget costs one count
            // rather than a read of every row in it.
            if ($DB->count_records($table) > $budget) {
                return null;
            }
            // That count is a SEPARATE query from the read below, so by the time we
            // use it it is only an estimate — rows inserted in between would let the
            // map, and the budget the caller adds count($map) to, run past the cap.
            // Asking for one row more than the budget bounds the fetch itself, so
            // the overrun is detectable from the result without a second count.
            $map = $DB->get_records_menu($table, null, '', 'id, name', 0, $budget + 1);
            return count($map) > $budget ? null : $map;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Dispatch a derived entity to its bespoke exporter method.
     *
     * @param string $entity Registry key of a `'derived' => true` entity.
     * @param string $batch  snapshot_batch UUID shared by this export run.
     * @param string|null $error Out-param: the failure message, or null on success.
     * @return int Rows exported.
     */
    protected static function export_derived(string $entity, string $batch, ?string &$error = null): int {
        switch ($entity) {
            case 'userlogins':
                return self::export_userlogins($batch, $error);
            default:
                $error = 'no exporter for this derived entity';
                mtrace("local_intellistream: derived entity '{$entity}' has no exporter — skipped.");
                return 0;
        }
    }

    /**
     * Export the per-user login count, derived from the standard logstore.
     *
     * Moodle keeps no single "number of logins" counter. The legacy
     * IntelliBoard plugin derived it by counting \core\event\user_loggedin
     * events; this method does the same: it groups the standard logstore by
     * userid over site-context (contextid = 1) login events and emits one
     * `entity_snapshot` row per user with a real `logins` count.
     *
     * The row shape (`id`, `logins`) matches the legacy `userlogins` datatype
     * the warehouse `userlogins_raw` feed expects.
     *
     * Notes / limitations:
     *  - Only the standard logstore (`logstore_standard_log`) is read. Sites
     *    running an alternative log store will produce no rows here; the table
     *    is `table_exists()`-guarded by export_entity() before this runs.
     *  - The count covers whatever retention window the logstore holds, so it
     *    is a lower bound on a user's lifetime logins if old log rows have
     *    been pruned — this matches the legacy plugin's behaviour.
     *  - Users with zero login events simply produce no row (the warehouse
     *    coalesces a missing count to 0).
     *
     * @param string $batch snapshot_batch UUID shared by this export run.
     * @param string|null $error Out-param: the failure message, or null on success.
     * @return int Rows exported (= distinct users with >=1 login event).
     */
    protected static function export_userlogins(string $batch, ?string &$error = null): int {
        global $DB, $CFG;

        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;

        $rows = 0;

        $rs = null;
        try {
            // Contextid = 1 is the system context: a login event is fired in
            // the system context, mirroring the legacy `action = 'loggedin'
            // AND contextid = 1` derivation.
            $sql = "SELECT userid AS id, COUNT(1) AS logins
                      FROM {logstore_standard_log}
                     WHERE eventname = :eventname
                       AND contextid = :contextid
                       AND userid > 0
                  GROUP BY userid
                  ORDER BY userid ASC";
            $params = [
                'eventname' => '\\core\\event\\user_loggedin',
                'contextid' => 1,
            ];

            // A plain query where the result fits in memory, a recordset where it
            // might not. On PostgreSQL a recordset is a cursor, and the planner
            // optimises a cursor for its first rows: it walks the userid index
            // across the whole log in userid order, one random heap read per row,
            // and never parallelises. As a plain query the same SQL can be a
            // parallel scan with a hash or sorted aggregate. The cost is memory:
            // the whole result is held at once, one id => count pair per user who
            // has logged in (see USERLOGINS_INMEMORY_MAX_USERS), so it is taken
            // only while twice the budgeted size fits in the memory left. Past
            // that the recordset's fixed memory is worth its slower plan. On
            // MySQL the recordset also buffers the result, but at about 31 bytes
            // per row (measured), still far below the in-memory path.
            $maxuserid = (int)$DB->get_field_sql('SELECT MAX(id) FROM {user}');
            $limit = (string)ini_get('memory_limit');
            $budget = ($limit === '' || $limit === '-1')
                ? PHP_INT_MAX
                : get_real_size($limit) - memory_get_usage(true);
            if (
                $maxuserid <= self::USERLOGINS_INMEMORY_MAX_USERS
                    && $maxuserid * self::USERLOGINS_BYTES_PER_USER * 2 <= $budget
            ) {
                $counts = $DB->get_records_sql_menu($sql, $params);
            } else {
                $rs = $DB->get_recordset_sql($sql, $params);
                $counts = (function () use ($rs) {
                    foreach ($rs as $row) {
                        yield $row->id => $row->logins;
                    }
                })();
            }
            foreach ($counts as $userid => $logins) {
                $payload = [
                    'id'             => self::entity_uuid($siteid, 'userlogins', $userid),
                    'site_id'        => $siteid,
                    'captured_at'    => clock::now(),
                    'plugin_version' => $pluginversion,
                    'moodle_version' => $moodleversion,
                    'record_type'    => 'entity_snapshot',
                    'entity'         => 'userlogins',
                    'snapshot_batch' => $batch,
                    'entity_data'    => [
                        'id'     => (int)$userid,
                        'logins' => (int)$logins,
                    ],
                ];
                // Count only what the buffer accepted. No watermark rides on this
                // one — `userlogins` is a derived aggregate recomputed in full on
                // every pass, so a refused row is picked up next time rather than
                // lost — but reporting a refusal as an export is the same
                // mis-statement, and the count is what an operator reads.
                if (buffer::append_record($payload)) {
                    $rows++;
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            mtrace("local_intellistream: entity 'userlogins' export error: " . $e->getMessage());
        } finally {
            if ($rs instanceof \moodle_recordset) {
                $rs->close();
            }
        }

        mtrace("local_intellistream: userlogins — {$rows} rows exported (batch {$batch}).");
        return $rows;
    }

    /**
     * Effective registry with admin overrides applied.
     *
     * Other code paths (the bulk exporter, the scheduled refresh task, any
     * future audit tooling) should call this rather than {@see registry()}
     * directly. It is a thin wrapper that hands the static registry to
     * {@see \local_intellistream\services\config_service::apply_overrides_to_registry()},
     * which honours the per-datatype rows in `local_intellistream_config`:
     * disabled rows are dropped, custom_columns rewrites the SELECT list, and
     * custom_table rows are added as brand-new entities.
     *
     * Memoised for the life of the request. The build is not cheap — it reads
     * every row of `local_intellistream_config` with a get_records(), merges them
     * over the ~178-entry curated registry, then runs strip_forbidden_columns()
     * across the result — and capture_entity_match() calls it on the page-render
     * path from twelve event observers. Unmemoised, a 5,000-user CSV upload built
     * the whole registry 5,000 times synchronously inside the request, and a
     * 300-activity course restore 300 times. The sibling memo on
     * {@see resolve_entity_columns()} was already doing this one level down; this
     * is the level above it that was missed.
     *
     * The cache is per-request and invalidated explicitly by
     * {@see reset_registry_cache()}, which `config_repository::save()` and
     * `::delete()` call. That repository is the ONLY writer of the table, and
     * dynamic discovery registers through it too, so there is no path that
     * changes the effective registry without clearing this.
     *
     * @return array<string, array{table:string, columns?:string, derived?:bool}>
     */
    public static function registry_with_overrides(): array {
        if (self::$registrycache !== null) {
            return self::$registrycache;
        }
        $service = new \local_intellistream\services\config_service();
        self::$registrycache = self::strip_forbidden_columns(
            $service->apply_overrides_to_registry(self::registry())
        );
        return self::$registrycache;
    }

    /**
     * Drop the memoised effective registry.
     *
     * Called by config_repository::save()/delete() so a write is visible to any
     * later read in the same request — the control webhook does exactly that when
     * it saves datatype config and then reports the resulting catalogue back.
     * Also the hook a test uses between fixtures.
     *
     * @return void
     */
    public static function reset_registry_cache(): void {
        self::$registrycache = null;
    }

    /**
     * Final, unconditional credential filter over a resolved registry.
     *
     * Applied to the output of apply_overrides_to_registry(), so it covers the
     * curated registry, admin `custom_columns` overrides, admin `custom_table`
     * entities and dynamic-discovery widening in one pass — see
     * {@see FORBIDDEN_COLUMNS} for why this is enforced rather than merely
     * documented.
     *
     * Two shapes to handle:
     *
     *  - An explicit SELECT list: drop any forbidden token, preserving the order
     *    and the ', ' separator of the surviving columns.
     *  - `'*'` (whole row): '*' cannot be filtered at SELECT time, so the live
     *    table is introspected. If it carries no forbidden column — the normal
     *    case — `'*'` is left EXACTLY as it was, so no behaviour changes and the
     *    in_form whole-row parity for discovered tables is untouched. Only when a
     *    forbidden column is actually present is `'*'` expanded to the explicit
     *    list of the remaining columns. `$DB->get_columns()` is served from
     *    Moodle's databasemeta cache, so this is cheap enough for the observer
     *    path.
     *
     * Derived entities have no SELECT list (their rows are computed by a bespoke
     * method) and are passed through untouched.
     *
     * @param array $registry Entity => ['table' => string, 'columns' => ?string, 'derived' => ?bool].
     * @return array The registry with forbidden columns removed, same shape.
     */
    protected static function strip_forbidden_columns(array $registry): array {
        global $DB;

        $global = array_map('strtolower', self::FORBIDDEN_COLUMNS);

        // Read once, not per entity: this runs on the observer path.
        $bodiesallowed = (bool) (int) get_config(config::COMPONENT, 'exportmessagebodies');

        foreach ($registry as $datatype => $def) {
            if (!empty($def['derived'])) {
                continue;
            }
            $table = $def['table'] ?? '';
            $columns = isset($def['columns']) ? trim((string) $def['columns']) : '';
            $forbidden = $global;

            // Per-table credential columns whose bare name is too generic to
            // blocklist globally. Keyed on the TABLE, not the datatype, so an
            // admin `custom_table` row or a discovered entry aimed at the same
            // table is filtered too, whatever it chose to call itself.
            foreach (self::FORBIDDEN_TABLE_COLUMNS[strtolower((string) $table)] ?? [] as $col) {
                $forbidden[] = strtolower($col);
            }

            // Message bodies are opt-in, so absent the setting they behave exactly
            // like a forbidden column and are stripped by the same pass.
            if (!$bodiesallowed && in_array($datatype, self::MESSAGE_ENTITIES, true)) {
                foreach (self::MESSAGE_BODY_COLUMNS as $col) {
                    $forbidden[] = strtolower($col);
                }
            }

            if ($columns === '' || $columns === '*') {
                // Whole row: introspect, and only rewrite if there is something to strip.
                if ($table === '') {
                    continue;
                }
                try {
                    $live = array_keys($DB->get_columns($table));
                } catch (\Throwable $e) {
                    // Table absent/unreadable. The export path re-checks
                    // table_exists() before selecting, so leave the entry alone.
                    continue;
                }
                $safe = [];
                $stripped = false;
                foreach ($live as $col) {
                    if (in_array(strtolower((string) $col), $forbidden, true)) {
                        $stripped = true;
                        continue;
                    }
                    $safe[] = $col;
                }
                if ($stripped) {
                    // Same fallback as the explicit-list branch below: if every live
                    // column turned out to be forbidden, narrow to the primary key
                    // rather than leaving '*' in place. Guarding this on a non-empty
                    // $safe (as it used to) meant the one case where EVERY column is
                    // a credential was the one case that kept `SELECT *`, which is
                    // the exact opposite of the intent.
                    //
                    // Note $stripped is false when the table could not be introspected
                    // ($DB->get_columns() returns an EMPTY ARRAY for an absent table
                    // rather than throwing), so an absent table still falls through
                    // untouched here and is caught by the table_exists() re-check on
                    // the export path.
                    $registry[$datatype]['columns'] = $safe ? implode(', ', $safe) : 'id';
                }
                continue;
            }

            $safe = [];
            $stripped = false;
            foreach (preg_split('/\s*,\s*/', $columns) as $col) {
                $col = trim($col);
                if ($col === '') {
                    continue;
                }
                if (in_array(strtolower($col), $forbidden, true)) {
                    $stripped = true;
                    continue;
                }
                $safe[] = $col;
            }
            if ($stripped) {
                // An entry whose every column was forbidden would leave an empty
                // SELECT list, which is not valid SQL. Fall back to the primary
                // key so the entity still reconciles (and deletes still work)
                // without carrying any payload.
                $registry[$datatype]['columns'] = $safe ? implode(', ', $safe) : 'id';
            }
        }

        return $registry;
    }

    /**
     * Strip forbidden keys from an already-fetched row.
     *
     * {@see strip_forbidden_columns()} guards the registry funnel, which is where
     * every normal export path resolves its SELECT list. One path does not go
     * through it: the legacy-migration task reads `SELECT *` from five
     * `local_intelliboard_*` tables and buffers whole rows directly, so the funnel
     * never sees them and a credential-named column in a legacy table would ship
     * unfiltered — a defence-in-depth gap, and one that made the FORBIDDEN_COLUMNS
     * docblock's "single funnel" claim untrue. This closes it at the only other
     * place rows enter the buffer.
     *
     * Message bodies are deliberately NOT considered here: the legacy tables carry
     * no message text, and this filter has no entity name to key that rule on.
     *
     * @param array  $row   fetched row as an associative array
     * @param string $table source table name, for the per-table rules
     * @return array the same row minus any forbidden key
     */
    public static function strip_forbidden_row_keys(array $row, string $table = ''): array {
        $forbidden = array_map('strtolower', self::FORBIDDEN_COLUMNS);
        foreach (self::FORBIDDEN_TABLE_COLUMNS[strtolower($table)] ?? [] as $col) {
            $forbidden[] = strtolower($col);
        }
        foreach (array_keys($row) as $key) {
            if (in_array(strtolower((string) $key), $forbidden, true)) {
                unset($row[$key]);
            }
        }
        return $row;
    }

    /**
     * How the verification sweep treats an entity.
     *
     * 'blocks'  a table with a numeric `id`: walked in fixed id blocks and fingerprinted;
     * 'atomic'  a derived entity, or a table without an `id` column: sent whole once per
     *           cycle, as the daily snapshot did (no fingerprint, no census);
     * 'absent'  its table does not exist on this site.
     *
     * @param array $def Its effective registry definition.
     * @return string
     */
    public static function sweep_kind(array $def): string {
        global $DB;
        if (!empty($def['derived'])) {
            return 'atomic';
        }
        $table = $def['table'] ?? '';
        if ($table === '') {
            return 'absent';
        }
        try {
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                return 'absent';
            }
            $cols = $DB->get_columns($table);
        } catch (\Throwable $e) {
            return 'absent';
        }
        return isset($cols['id']) ? 'blocks' : 'atomic';
    }

    /**
     * Highest block number an entity's table currently reaches (-1 when empty).
     *
     * @param array $def Effective registry definition of a 'blocks' entity.
     * @return int
     */
    public static function sweep_max_block(array $def): int {
        global $DB;
        $max = $DB->get_field_sql('SELECT MAX(id) FROM {' . $def['table'] . '}');
        return ($max === null || $max === false) ? -1 : intdiv((int)$max, change_ledger::BLOCK_SIZE);
    }

    /**
     * One save window of the verification sweep for one entity: up to $maxblocks fixed
     * id blocks (floor(id / BLOCK_SIZE)) starting at the first existing id at or above
     * block $nextblock, fingerprinted per change_ledger::FP_SIZE ids.
     *
     * Each block is fingerprinted exactly as it would be sent (entity_row_data(), the
     * same envelope-free data the buffer carries, length-prefixed per row, seeded with
     * the ledger fingerprint and the entity). A block is sent only when its fingerprint
     * differs from the confirmed one for this window's range (change_ledger::trusted_range(),
     * read here so only that range is in memory) or it has none — it is then read again and
     * the fingerprint recorded is that of the rows actually sent, so a change between the
     * reads is never masked. With no confirmed entry in the range at all, every block is
     * sent as it is read.
     *
     * Nothing here is persisted: the caller writes the census page, stages the returned
     * entries and advances the position together, so a run killed part-way redoes the
     * window and loses nothing.
     *
     * @param string $entity Registry key.
     * @param array $def Effective registry definition ('blocks' kind).
     * @param int $nextblock First block to consider.
     * @param int $maxblocks Blocks in this window.
     * @param string $batch snapshot_batch of the pass.
     * @param int|null $pass Pass number, for the periodic re-send (change_ledger::trusted_range()).
     * @return array{status:string, entries:string, ids:int[], nextblock:int, rows:int,
     *     blocks:int, sent:int, dropped:int, clk:array{0:int,1:int,2:int,3:bool}, error:?string}
     *     status: 'ok', 'end' (no id at or above $nextblock), 'stopped' (the buffer refused
     *     a record it may take later; nothing to persist), 'error'.
     */
    public static function sweep_window(
        string $entity,
        array $def,
        int $nextblock,
        int $maxblocks,
        string $batch,
        ?int $pass = null
    ): array {
        global $DB, $CFG;
        $n = change_ledger::BLOCK_SIZE;       // Windows and census pages.
        $f = change_ledger::FP_SIZE;          // Fingerprint units.
        $out = ['status' => 'ok', 'entries' => '', 'ids' => [], 'nextblock' => $nextblock, 'rows' => 0,
            'blocks' => 0, 'sent' => 0, 'dropped' => 0, 'clk' => [0, 0, 0, false], 'error' => null];
        $table = $def['table'];
        $resolved = self::resolve_entity_columns($entity, $table, $def['columns'] ?? null, $def['wmcol'] ?? null);
        $columns = $resolved['columns'];
        if ($columns === null) {
            // None of its declared columns exists here. Never an empty pass: that would
            // end in an empty census, i.e. "every row was deleted". A failure instead,
            // which parks the entity and sends an id-only census built from its real ids.
            $out['status'] = 'error';
            $out['error'] = "no declared column of '{$table}' exists on this site";
            return $out;
        }
        if ($columns === '') {
            $columns = '*';
        }
        $wmcol = $resolved['wmcol'];
        $fpskip = self::fp_skip_column($def, $wmcol);
        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $seed = change_ledger::fingerprint() . "\0" . $entity . "\0";

        try {
            $first = $DB->get_field_sql('SELECT MIN(id) FROM {' . $table . '} WHERE id >= :lo', ['lo' => $nextblock * $n]);
        } catch (\Throwable $e) {
            $out['status'] = 'error';
            $out['error'] = $e->getMessage();
            return $out;
        }
        if ($first === null || $first === false) {
            $out['status'] = 'end';
            return $out;
        }
        $startblock = intdiv((int)$first, $n);
        $endblock = $startblock + max(1, $maxblocks);   // Exclusive.
        $out['nextblock'] = $endblock;

        $trusted = change_ledger::trusted_range($entity, intdiv($startblock * $n, $f), intdiv($endblock * $n, $f), $pass);
        $send = $trusted === '';
        $tn = change_ledger::entries($trusted);
        $ti = 0;
        $tosend = [];       // Blocks to send: block => true.
        $hashes = [];       // Block => raw hash, in block order.
        $ctx = null;
        $cur = null;
        $rs = null;
        $stopped = false;
        $clk = [0, 0, 0, false];
        try {
            $rs = $DB->get_recordset_select(
                $table,
                'id >= :lo AND id < :hi',
                ['lo' => $startblock * $n, 'hi' => $endblock * $n],
                'id ASC',
                $columns
            );
            foreach ($rs as $row) {
                $b = intdiv((int)$row->id, $f);
                if ($b !== $cur) {
                    if ($ctx !== null) {
                        $hashes[$cur] = substr(hash_final($ctx, true), 0, change_ledger::HASH_BYTES);
                    }
                    $ctx = hash_init('sha256');
                    hash_update($ctx, $seed);
                    $cur = $b;
                }
                $data = self::entity_row_data($entity, $row, true);
                self::hash_row($ctx, $data, $fpskip);
                $out['ids'][] = (int)$row->id;
                $isclockless = $wmcol !== null && self::is_clockless_row($row, $wmcol);
                if ($isclockless) {
                    $clk[0]++;
                }
                if (!$send) {
                    if ($isclockless) {
                        // Not re-sent: its block was confirmed delivered.
                        $clk[1]++;
                        if (!$clk[3]) {
                            $clk[2] = max($clk[2], (int)$row->id);
                        }
                    }
                    continue;
                }
                $reason = null;
                if (
                    !self::buffer_entity_data(
                        $siteid,
                        $entity,
                        $row->id,
                        $data,
                        $pluginversion,
                        $moodleversion,
                        $batch,
                        $reason
                    )
                ) {
                    if ($isclockless) {
                        $clk[3] = true;
                    }
                    if ($reason !== buffer::REFUSED_PERMANENT) {
                        $stopped = true;
                        break;
                    }
                    $out['dropped']++;   // Settled: fingerprinted, never retried.
                    continue;
                }
                $out['rows']++;
                if ($isclockless) {
                    $clk[1]++;
                    if (!$clk[3]) {
                        $clk[2] = max($clk[2], (int)$row->id);
                    }
                }
            }
            if ($ctx !== null && !$stopped) {
                $hashes[$cur] = substr(hash_final($ctx, true), 0, change_ledger::HASH_BYTES);
            }
        } catch (\Throwable $e) {
            $out['status'] = 'error';
            $out['error'] = $e->getMessage();
            return $out;
        } finally {
            if ($rs instanceof \moodle_recordset) {
                $rs->close();
            }
        }
        if ($stopped) {
            $out['status'] = 'stopped';
            return $out;
        }

        if (!$send) {
            // Decide each block against the trusted set (both in block order).
            foreach ($hashes as $b => $h) {
                while ($ti < $tn && change_ledger::read($trusted, $ti)[0] < $b) {
                    $ti++;
                }
                if (
                    $ti >= $tn || change_ledger::read($trusted, $ti)[0] !== $b
                        || !hash_equals(change_ledger::read($trusted, $ti)[1], $h)
                ) {
                    $tosend[$b] = true;
                }
            }
            // Send the changed blocks, re-read, and record what was actually sent.
            foreach (array_keys($tosend) as $b) {
                $ctx = hash_init('sha256');
                hash_update($ctx, $seed);
                $brs = null;
                $present = false;
                try {
                    $brs = $DB->get_recordset_select(
                        $table,
                        'id >= :lo AND id < :hi',
                        ['lo' => $b * $f, 'hi' => ($b + 1) * $f],
                        'id ASC',
                        $columns
                    );
                    foreach ($brs as $row) {
                        $present = true;
                        $data = self::entity_row_data($entity, $row, true);
                        self::hash_row($ctx, $data, $fpskip);
                        $reason = null;
                        if (
                            !self::buffer_entity_data(
                                $siteid,
                                $entity,
                                $row->id,
                                $data,
                                $pluginversion,
                                $moodleversion,
                                $batch,
                                $reason
                            )
                        ) {
                            if ($reason !== buffer::REFUSED_PERMANENT) {
                                $stopped = true;
                                break;
                            }
                            $out['dropped']++;
                            continue;
                        }
                        $out['rows']++;
                    }
                } catch (\Throwable $e) {
                    $out['status'] = 'error';
                    $out['error'] = $e->getMessage();
                    return $out;
                } finally {
                    if ($brs instanceof \moodle_recordset) {
                        $brs->close();
                    }
                }
                if ($stopped) {
                    $out['status'] = 'stopped';
                    return $out;
                }
                if ($present) {
                    $hashes[$b] = substr(hash_final($ctx, true), 0, change_ledger::HASH_BYTES);
                } else {
                    unset($hashes[$b]);   // Emptied between the reads.
                }
                $out['sent']++;
            }
        } else {
            $out['sent'] = count($hashes);
        }

        foreach ($hashes as $b => $h) {
            $out['entries'] .= change_ledger::entry($b, $h);
        }
        $out['blocks'] = count($hashes);
        $out['clk'] = $clk;
        return $out;
    }

    /**
     * Write one census page of a verification pass. Pages of consecutive passes go to
     * alternating slots, so a pass in progress never overwrites the pages of the last
     * complete one; the manifest (see sweep_census_manifest()) names the batch whose
     * pages form the census, which is how the server already matches them.
     *
     * The ids go in whichever form is smaller: the JSON list (`pks`), or a bitmap
     * (`lo`, `n`, `bits`: base64, bit i of the bitmap — least significant bit first in
     * each byte — set when id lo+i exists). A dense window of 50,000 ids is ~8 KB as a
     * bitmap against ~300 KB as a list; a sparse one stays a list.
     *
     * @param string $entity
     * @param int $slot 0 or 1.
     * @param string $batch Pass batch.
     * @param int $pageno 1-based.
     * @param int[] $ids Ascending.
     * @return bool Whether the buffer accepted it.
     */
    public static function sweep_census_page(string $entity, int $slot, string $batch, int $pageno, array $ids): bool {
        global $CFG;
        $siteid = config::site_id();
        $page = ['census_entity' => $entity, 'page' => $pageno];
        $bits = self::census_bits($ids);
        if ($bits !== null) {
            $page += $bits;
        } else {
            $page['pks'] = array_map('strval', $ids);
        }
        $page['snapshot_batch'] = $batch;
        return buffer::append_record([
            'id'             => self::entity_uuid($siteid, 'entity_census', "{$entity}|page_{$pageno}|g{$slot}"),
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => (int)config::plugin_version(),
            'moodle_version' => isset($CFG->version) ? (int)$CFG->version : null,
            'record_type'    => 'entity_snapshot',
            'entity'         => 'entity_census',
            'snapshot_batch' => $batch,
            'entity_data'    => $page,
        ]);
    }

    /**
     * The bitmap form of an ascending id list, or null when the JSON list is smaller.
     *
     * @param int[] $ids Ascending, at least one.
     * @return array{lo:int, n:int, bits:string}|null
     */
    public static function census_bits(array $ids): ?array {
        if (!$ids) {
            return null;
        }
        $lo = (int)$ids[0];
        $n = (int)end($ids) - $lo + 1;
        $bytes = intdiv($n + 7, 8);
        $listlen = 2;
        foreach ($ids as $id) {
            $listlen += strlen((string)$id) + 3;   // Quotes and comma, as json_encode writes strings.
        }
        if (4 * intdiv($bytes + 2, 3) >= $listlen) {
            return null;
        }
        $map = str_repeat("\0", $bytes);
        foreach ($ids as $id) {
            $i = (int)$id - $lo;
            $map[$i >> 3] = chr(ord($map[$i >> 3]) | (1 << ($i & 7)));
        }
        return ['lo' => $lo, 'n' => $n, 'bits' => base64_encode($map)];
    }

    /**
     * Close a verification pass's census: the manifest naming its batch and page count,
     * under the same record id as every earlier manifest of this entity. An empty table
     * gets today's single-record form with no ids.
     *
     * @param string $entity
     * @param string $batch
     * @param int $pages
     * @param int $count
     * @param string $scanstarted Plugin-clock stamp taken when the pass began.
     * @param string|null $reason Out-param, see buffer::append_record().
     * @return bool
     */
    public static function sweep_census_manifest(
        string $entity,
        string $batch,
        int $pages,
        int $count,
        string $scanstarted,
        ?string &$reason = null
    ): bool {
        global $CFG;
        $siteid = config::site_id();
        $base = [
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => (int)config::plugin_version(),
            'moodle_version' => isset($CFG->version) ? (int)$CFG->version : null,
            'record_type'    => 'entity_snapshot',
            'entity'         => 'entity_census',
            'snapshot_batch' => $batch,
        ];
        if ($pages === 0) {
            return buffer::append_record($base + [
                'id'          => self::entity_uuid($siteid, 'entity_census', $entity),
                'entity_data' => ['census_entity' => $entity, 'count' => 0, 'pks' => [],
                    'snapshot_batch' => $batch, 'scan_started_at' => $scanstarted],
            ], $reason);
        }
        // Format 2: pages may be bitmaps, so the page count goes in `page_total`, not
        // `page_count`. A server that cannot decode them sees no page count and skips the
        // entity (no deletes) rather than reading a bitmap page as "these ids are gone".
        return buffer::append_record($base + [
            'id'          => self::entity_uuid($siteid, 'entity_census', "{$entity}|manifest"),
            'entity_data' => ['census_entity' => $entity, 'is_census_manifest' => true, 'format' => '2',
                'page_total' => $pages, 'count' => $count, 'snapshot_batch' => $batch,
                'scan_started_at' => $scanstarted],
        ], $reason);
    }

    /**
     * Merge one entity's clockless observation from a completed pass into the detected
     * set the 15-minute lane reads. A merge, never a replace: other entities' entries
     * are left as they are.
     *
     * @param string $entity
     * @param int $seen Clockless rows the pass read.
     * @param int $maxid Highest clockless id delivered, below any refusal.
     * @return void
     */
    public static function sweep_note_clockless(string $entity, int $seen, int $maxid): void {
        $set = self::clockless_detected();
        $before = $set;
        if ($seen > 0) {
            $set[$entity] = $maxid;
        } else {
            unset($set[$entity]);
        }
        if ($set === $before) {
            return;
        }
        ksort($set);
        $payload = json_encode($set);
        if ($payload !== false) {
            set_config(self::CLOCKLESS_DETECTED_KEY, $payload, config::COMPONENT);
        }
    }

    /**
     * The column an entity leaves out of its sweep fingerprint, if any.
     *
     * Only the column the registry names in `fpskipwm`, and only while it is this
     * site's resolved watermark column: the 15-minute lane then sends every change to
     * it row by row. Anywhere that does not hold (an admin wmcol override, schema
     * drift, no watermark) the whole row is hashed, so a change to that column is still
     * caught by the sweep.
     *
     * @param array $def Registry definition.
     * @param string|null $wmcol The entity's resolved watermark column on this site.
     * @return string|null
     */
    private static function fp_skip_column(array $def, ?string $wmcol): ?string {
        $want = isset($def['fpskipwm']) ? strtolower((string)$def['fpskipwm']) : '';
        return ($want !== '' && $wmcol !== null && strtolower($wmcol) === $want) ? $wmcol : null;
    }

    /**
     * Feed one row's shipped data into a block fingerprint. Length-prefixed so row
     * boundaries are part of what is hashed; encoded with the buffer's own flags.
     *
     * @param \HashContext|resource $ctx
     * @param array $data From entity_row_data().
     * @param string|null $skip A column left out of the fingerprint (see fp_skip_column()).
     * @return void
     */
    private static function hash_row($ctx, array $data, ?string $skip = null): void {
        if ($skip !== null) {
            unset($data[$skip]);
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = "\0unencodable";
        }
        hash_update($ctx, strlen($json) . ':' . $json);
    }
}
