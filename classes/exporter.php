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
     * this list has to be maintained, so the cost of that choice is paid by
     * `tests/cli/forbidden_columns_coverage_smoke.php`, which scans the live schema
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
     * @return array<string, array{table:string, columns?:string, derived?:bool}>
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
            ],
            'grade_grades' => [
                'table'   => 'grade_grades',
                'columns' => 'id, itemid, userid, rawgrade, rawgrademax, rawgrademin, '
                    . 'finalgrade, hidden, locked, overridden, excluded, feedbackformat, '
                    . 'usermodified, timecreated, timemodified, aggregationstatus, '
                    . 'aggregationweight',
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
     * Export every registered entity.
     *
     * @param string|null $batch Shared snapshot_batch UUID; generated if null.
     * @return array{batch:string, rows:int, entities:int}
     */
    public static function export_all(?string $batch = null): array {
        $batch = $batch ?? \core\uuid::generate();
        $total = 0;
        $entities = 0;

        // Nothing may run before the site is paired — same guard as
        // export_incremental(). buffer::append_record() refuses every record
        // while the site id is empty, so a run here would full-scan (and census)
        // every table, ship nothing, and — on a large site — hold the single
        // serial Moodle cron worker long enough to starve other plugins' tasks
        // (this was IBV2-720: an installed-but-unpaired site whose daily full
        // snapshot blocked Turnitin's cron). Stop before reading. (IBV2-720)
        if (config::site_id() === '') {
            mtrace('local_intellistream: no site id — full snapshot skipped until the site is paired.');
            return ['batch' => $batch, 'rows' => 0, 'entities' => 0];
        }

        // Moodle's cron runs every due task in ONE process, so without this the
        // drift summary below would also list entities seen by an earlier task's
        // export and over-report this run.
        self::$columndrift = [];
        // Resolve the effective registry (built-in + admin overrides) ONCE per
        // run and thread it into export_entity(), so admin-registered custom
        // datatypes get snapshotted and the per-entity loop does not re-query
        // the config table N times.
        $registry = self::registry_with_overrides();
        foreach (array_keys($registry) as $entity) {
            // Keeping the buffer file's mtime fresh across this run is handled
            // inside export_entity(), which every iteration goes through.
            $total += self::export_entity($entity, $batch, $registry);
            $entities++;
        }
        // InForm dynamic tables: push the SCHEMA catalog alongside the snapshots
        // so the downstream ETL can build in_form_table_* candidate views without
        // ever calling back into Moodle. Push-native replacement for the legacy
        // `local_intellidata_get_dbschema_custom` web service — and, like that
        // service, it catalogues every table we export, not a hand-picked subset.
        // Candidates are zero-row views; nothing exports data until
        // an admin activates a table in supernova.
        self::export_inform_dyn_schema($batch, $registry);
        // Delete-reconciliation census: emit an authoritative list of the
        // current primary keys per entity (a full table scan, independent of
        // the row-level content dedup). The downstream reconciler diffs the
        // warehouse against this to remove rows for entities deleted in Moodle.
        // Only the FULL snapshot (export_all) can produce a complete census —
        // incremental refreshes never do, and the middleware content-dedup means
        // no single data batch holds the full current state. See export_census().
        self::export_census($batch, $registry);
        // Surface schema drift once for the whole run. Machine-readable form rides
        // on the per-datatype datatype_config snapshot (`columns_missing`); this
        // is so it is visible to anyone reading cron output at all.
        if (($drift = self::column_drift_summary()) !== null) {
            mtrace($drift);
        }
        return ['batch' => $batch, 'rows' => $total, 'entities' => $entities];
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
     * until the daily full snapshot.
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
     * Read the incremental lane's state document.
     *
     * @return array{wm: array<string, int>, migrated: bool}
     */
    private static function cdc_state(): array {
        $state = ['wm' => [], 'migrated' => false];

        $raw = get_config(config::COMPONENT, self::CDC_STATE_KEY);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ((array)($decoded['wm'] ?? []) as $entity => $wm) {
                    $state['wm'][(string)$entity] = (int)$wm;
                }
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
                $state['wm'][$entity] = (int)$value;
            }
        }
        if ($state['wm']) {
            $state['migrated'] = true;
        }
        return $state;
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
        $payload = json_encode(['wm' => array_map('intval', (array)$state['wm'])]);
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
     * entity, or null when it has none (those are covered only by the daily full
     * snapshot — see export_all()).
     *
     * Derived from the registry 'columns' list when explicit; when '*'/null the
     * live table is introspected. No hardcoded per-entity map to drift.
     *
     * @param string|null $columns Registry columns string ('*'/null = whole row).
     * @param string $table Moodle table name (unprefixed).
     * @param string|null $override Explicit per-entity watermark column (registry
     *        'wmcol'); used when present so tables whose real change column is not
     *        one of WATERMARK_COLUMNS (e.g. forum_posts.modified, course_modules.added)
     *        can still drive the 15-min incremental.
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
        if ($override !== null && $override !== '' && isset($have[strtolower($override)])) {
            return strtolower($override);
        }
        foreach (self::WATERMARK_COLUMNS as $col) {
            if (isset($have[$col])) {
                return $col;
            }
        }
        return null;
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
     * .quizgradeitemid in 4.4, and the plugin supports 4.1+), and third-party
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
            // its string-parsing branch, so this costs no extra query.
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
     * Incremental (CDC) export: ship only rows changed since the last run, per
     * entity, using a persisted per-entity watermark on the entity's change
     * timestamp. This is the every-15-min path (refresh_entities task).
     *
     * NOT exported here (covered by the daily full snapshot, export_all()):
     *   - derived entities (aggregates, no row-level change timestamp),
     *   - timestamp-less tables (context/role/modules/course_modules/user_info_*),
     *   - the inform_dyn_schema catalog (rarely changes).
     *
     * Watermarks live in ONE config document, self::CDC_STATE_KEY, holding the
     * max change-timestamp shipped so far per entity. First run (no entry = 0)
     * ships the full table once, then deltas. The daily full is the safety net
     * for any timestamp=0 / timestamp-less rows.
     *
     * Two properties this depends on, both of which used to be missing:
     *
     * The scan window is CLOSED at the high-water mark read before the scan
     * (`> since AND <= max`), not left open-ended. Advancing to a MAX read after
     * the scan means a row inserted between the two carries a timestamp at or
     * below the new watermark and is never picked up by this lane again.
     *
     * A watermark advances only when the scan for that entity ran to the end.
     * export_entity() swallows a mid-scan failure by design, so one bad entity
     * cannot end a run — but the watermark was advanced regardless, which turned
     * a transient database error into a permanently skipped window that only a
     * daily full (which a site may have disabled) would ever have recovered.
     *
     * A record the buffer REFUSES is counted and reported but does not hold the
     * watermark, because every refusal reason is permanent for that record; see
     * export_entity(). The unpaired case is handled by not running at all,
     * below — without that guard every append fails and the lane would either
     * hold every watermark forever or, as it used to, advance all of them while
     * shipping nothing.
     *
     * @param string|null $batch snapshot_batch UUID; generated if null.
     * @return array{batch:string, rows:int, entities:int}
     */
    public static function export_incremental(?string $batch = null): array {
        global $DB;
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

        // Report only this run's drift — see export_all().
        self::$columndrift = [];
        $registry = self::registry_with_overrides();
        $state = self::cdc_state();
        foreach ($registry as $entity => $def) {
            // See export_all(): keep the sweeper from mistaking a long run with
            // few appends for an abandoned file. First statement in the loop so
            // it still runs for the entities that `continue` out below.
            buffer::keepalive();
            if (!empty($def['derived'])) {
                continue; // Aggregate — refreshed by the daily full.
            }
            $table = $def['table'] ?? null;
            if ($table === null) {
                continue;
            }
            try {
                if (!$DB->get_manager()->table_exists($table)) {
                    continue;
                }
            } catch (\Throwable $e) {
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
                continue; // No change timestamp — daily full only.
            }
            $since = (int)($state['wm'][$entity] ?? 0);

            // Read the high-water mark BEFORE the scan and bind the scan to it,
            // so a row written while this entity is being scanned falls in the
            // NEXT window instead of under a watermark that has already passed
            // it. Also lets an unchanged entity skip the scan entirely.
            try {
                $newmax = $DB->get_field_sql("SELECT MAX({$wmcol}) FROM {" . $table . "}");
            } catch (\Throwable $e) {
                $newmax = null;
            }
            if ($newmax === null || $newmax === false || (int)$newmax <= $since) {
                continue; // Nothing changed — no scan, no write.
            }
            $newmax = (int)$newmax;

            $outcome = null;
            $total += self::export_entity(
                $entity,
                $batch,
                $registry,
                "{$wmcol} > :cdcwm AND {$wmcol} <= :cdcmax",
                ['cdcwm' => $since, 'cdcmax' => $newmax],
                $outcome
            );
            $entities++;

            if (!empty($outcome['complete'])) {
                $state['wm'][$entity] = $newmax;
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
     * Folding them into registry() would make export_all() / export_incremental()
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
        global $DB;
        $comp = config::COMPONENT;
        $reg = self::task_registry();
        $rows = 0;

        // Task_log: completed runs since the last watermark.
        // timeend is a decimal seconds timestamp; the watermark is the max
        // timeend already shipped. First run (unset) ships this plugin's whole
        // task_log history once, then only newer completions. The stored value
        // keeps full string precision; the WHERE bind uses a float, which is
        // DB-portable and precise enough to separate distinct completions
        // (a boundary re-ship is harmless — the pipeline dedups on id+content).
        $wmkey = 'task_wm_task_log';
        $since = get_config($comp, $wmkey);
        if ($since === false || $since === null || $since === '') {
            $since = '0';
        }
        $rows += self::export_entity(
            'task_log',
            $batch,
            $reg,
            'component = :comp AND timeend > :wm',
            ['comp' => $comp, 'wm' => (float)$since]
        );
        $newmax = null;
        try {
            $newmax = $DB->get_field_sql(
                'SELECT MAX(timeend) FROM {task_log} WHERE component = ?',
                [$comp]
            );
        } catch (\Throwable $e) {
            $newmax = null;
        }
        if ($newmax !== null && $newmax !== false && (float)$newmax > (float)$since) {
            set_config($wmkey, (string)$newmax, $comp);
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
     *                        daily snapshot); 0 when it ships incrementally.
     *   - filterbyid         1 for id-keyed tables (this plugin collects/backfills
     *                        them by keyset row id); 0 for derived aggregates.
     *   - events_tracking    1 only for the log datatypes (captured live from
     *                        Moodle events); 0 for entity snapshots, which are
     *                        snapshotted, not event-tracked. This is the honest
     *                        push-model value and intentionally differs from
     *                        IntelliData's per-datatype observers.
     *   - status/exportenabled  1 — presence in the effective registry means the
     *                        datatype is enabled and being exported.
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

            $catalog[] = [
                'datatype'           => $datatype,
                'tabletype'          => $tabletype,
                'status'             => $enabled,
                'exportenabled'      => $enabled,
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
                if (!$DB->get_manager()->table_exists($table)) {
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
     * Emitted only from export_all() (the daily FULL snapshot); an incremental
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
            try {
                if (!$DB->get_manager()->table_exists($table)) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }
            $rs = null;
            try {
                // Stream ids in pages so an arbitrarily large table is never
                // materialised whole and each census record stays well under the
                // 1 MiB per-record cap. A table that fits ONE page ships a single
                // record (unchanged wire format); a larger one ships N page
                // records + a manifest, which the reconciler unions.
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
                        'census_entity'  => $entity,
                        'count'          => count($page),
                        'pks'            => $page,
                        'snapshot_batch' => $batch,
                    ],
                ]);
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
                    ],
                ]);
            }

            // Only count what was actually written. append_record() returns false
            // without writing when the serialised record exceeds MAX_EVENT_BYTES;
            // counting regardless told an operator reading the cron output that
            // delete reconciliation was covered for an entity where the census had
            // in fact been discarded. A census that does not arrive makes the
            // downstream reconciler under-clean, which is safe — believing it
            // arrived is not.
            if (!$written) {
                mtrace("local_intellistream: census — '{$entity}' record exceeded the "
                    . 'per-record size cap and was NOT written; census skipped for this '
                    . 'entity (delete reconciliation will under-clean until it fits).');
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
     *        ['complete' => bool, 'rows' => int, 'dropped' => int, 'error' => ?string].
     *        `complete` is true only when the scan finished AND every row was
     *        accepted by the buffer, which is what export_incremental() requires
     *        before it may advance a watermark past this window. Optional, so
     *        every existing caller is unaffected.
     * @return int Rows exported for this entity.
     */
    public static function export_entity(
        string $entity,
        string $batch,
        ?array $registry = null,
        string $select = '',
        ?array $params = null,
        ?array &$outcome = null
    ): int {
        global $DB, $CFG;

        $outcome = ['complete' => false, 'rows' => 0, 'dropped' => 0, 'error' => null];

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
        try {
            $exists = $DB->get_manager()->table_exists($table);
        } catch (\Throwable $e) {
            $exists = false;
        }
        if (!$exists) {
            mtrace("local_intellistream: table '{$table}' absent — entity '{$entity}' skipped.");
            return 0;
        }

        // Derived entities are not straight table snapshots: they compute
        // their rows in a bespoke method rather than streaming a recordset.
        // Resolved AFTER this branch so a derived entity's backing table is
        // never introspected.
        if (!empty($registry[$entity]['derived'])) {
            return self::export_derived($entity, $batch);
        }

        // Narrow the declared list to what this Moodle actually has, so one
        // absent column costs that column instead of the whole entity.
        $columns = self::resolve_entity_columns(
            $entity,
            $table,
            $columns,
            $registry[$entity]['wmcol'] ?? null
        )['columns'];
        if ($columns === null) {
            mtrace("local_intellistream: entity '{$entity}' — no declared column of "
                . "'{$table}' exists on this site (table-name collision?) — skipped.");
            return 0;
        }

        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $chunk = config::export_batch_size();

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
                if (!self::buffer_entity_row($siteid, $entity, $row, $pluginversion, $moodleversion, $batch)) {
                    // Refused by the buffer — over-size record, disk cap, or an
                    // unpaired site. buffer records its own durable marker; what
                    // matters here is not counting it as shipped.
                    $dropped++;
                    continue;
                }
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
            // Reached only when the scan ran to the end.
            $outcome['complete'] = true;
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
            // Reported, but deliberately NOT folded into `complete`. Every reason
            // append_record() returns false is permanent for that record — it
            // could not be serialised, or it exceeds MAX_EVENT_BYTES — so a
            // caller that refused to advance past it would retry it forever and
            // never succeed. Losing a named, counted record is bounded; wedging
            // a lane is not. buffer sets its own durable drop marker, which is
            // what surfaces this on the status page.
            mtrace("local_intellistream: {$entity} — {$dropped} row(s) REFUSED by the buffer "
                . '(unserialisable or over the record size cap) and cannot be retried.');
        }

        mtrace("local_intellistream: {$entity} — {$rows} rows exported (batch {$batch}).");
        return $rows;
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
     * @return int Rows exported for this entity.
     */
    public static function export_entity_range(
        string $entity,
        string $batch,
        ?array $registry = null,
        ?int $from = null,
        ?int $to = null
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
        if (!empty($def['derived']) || $from === null || $to === null) {
            return self::export_entity($entity, $batch, $registry);
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
            return self::export_entity($entity, $batch, $registry); // No time column → full re-fetch.
        }
        return self::export_entity(
            $entity,
            $batch,
            $registry,
            "{$wmcol} >= :ib_from AND {$wmcol} <= :ib_to",
            ['ib_from' => $from, 'ib_to' => $to]
        );
    }

    /**
     * Build and buffer one entity_snapshot record for a Moodle row.
     *
     * Extracted so the full snapshot (export_entity) and the resumable
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
        string $batch
    ): bool {
        $data = (array)$row;
        if ($entity === 'role') {
            $data['name'] = self::role_display_name($row);
        }
        $payload = [
            'id'             => self::entity_uuid($siteid, $entity, $row->id),
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => $pluginversion,
            'moodle_version' => $moodleversion,
            'record_type'    => 'entity_snapshot',
            'entity'         => $entity,
            'snapshot_batch' => $batch,
            'entity_data'    => $data,
        ];
        return buffer::append_record($payload);
    }

    /**
     * Hot-path-safe, mtrace-free capture of the entity rows matching $conditions.
     *
     * Used by the per-entity event observers (see observers/entity_observer.php)
     * to snapshot a changed definition row the instant Moodle fires its
     * create/update event, so timestamp-less tables (course_modules, etc.) reach
     * the warehouse in ~1 min via ship_events instead of waiting for the daily
     * full snapshot. The payload is BYTE-IDENTICAL to the periodic snapshot
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
            if (!$DB->get_manager()->table_exists($table)) {
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
                    self::buffer_entity_row($siteid, $entity, $row, $pluginversion, $moodleversion, $batch);
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
     * the shippable backlog has been drained below the disk cap, so a crash /
     * timeout / reboot resumes from where it stopped instead of from zero, and
     * `shipper::enforce_disk_cap()` can never evict rows the watermark already
     * counted as done.
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
     * @return int Rows buffered on this run.
     */
    public static function export_entity_window(
        string $entity,
        string $batch,
        ?array $registry,
        string $wmkey
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

        try {
            $exists = $DB->get_manager()->table_exists($table);
        } catch (\Throwable $e) {
            $exists = false;
        }
        if (!$exists) {
            mtrace("local_intellistream: table '{$table}' absent — entity '{$entity}' skipped.");
            return 0;
        }

        // As export_entity(). Returns 0 rather than throwing: $lastid never
        // advances, so the caller correctly leaves the entity not-done, and one
        // permanently unresolvable entity cannot abort the whole backfill campaign.
        $columns = self::resolve_entity_columns(
            $entity,
            $table,
            $columns,
            $registry[$entity]['wmcol'] ?? null
        )['columns'];
        if ($columns === null) {
            mtrace("local_intellistream: entity '{$entity}' — no declared column of "
                . "'{$table}' exists on this site (table-name collision?) — skipped.");
            return 0;
        }

        $siteid = config::site_id();
        // Defensive twin of the guard in backfill::run(). Every append is refused
        // while unpaired, so scanning here could only produce a run of refusals —
        // and a refusal that is not at-capacity is classified PERMANENT below,
        // which would advance the watermark over the whole table. Returning 0
        // leaves the watermark exactly where it was, the same contract the two
        // unresolvable-entity returns above use.
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
                // missing, recoverable only by the daily full snapshot, which can
                // be disabled.
                //
                /* Which refusal it was decides what to do, and getting this wrong
                   trades one defect for another:

                   - buffer AT CAPACITY: transient. It drains, so the row will be
                     accepted on a later pass. Throw, which takes the mid-stream
                     path below: progress is persisted up to the last ACCEPTED id
                     and the entity is left not-done, so the next run resumes
                     exactly here and nothing is lost.
                   - anything else (a single row over MAX_EVENT_BYTES, or one that
                     will not JSON-encode): PERMANENT. Retrying can never succeed,
                     so stopping here would wedge this entity's backfill forever on
                     one row — and rows of that size do occur in practice. Count it,
                     say so loudly, and carry on past it, exactly as the sibling
                     export_entity() does. buffer::append_record() has already
                     written its own durable marker. */
                if (!self::buffer_entity_row($siteid, $entity, $row, $pluginversion, $moodleversion, $batch)) {
                    // Transient conditions first, so PERMANENT is only ever reached
                    // by elimination. Both of these are states of the SITE, not of
                    // the row, and both get fixed — so neither may advance the
                    // watermark. Unpaired is guarded above and re-tested here
                    // because site_id can be cleared mid-run.
                    if (buffer::at_capacity() || config::site_id() === '') {
                        throw new \RuntimeException(
                            'buffer would not accept a row while backfilling \'' . $entity . '\' at id '
                            . (isset($row->id) ? (int)$row->id : '?')
                            . ' — the buffer is at its disk cap, or the site is unpaired. Stopped '
                            . 'short of that row so the watermark cannot pass it. Resumable: the '
                            . 'next pass continues from the watermark.'
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
                    // Keep the shippable backlog well under the disk cap BEFORE
                    // advancing the watermark, so the watermark never runs ahead
                    // of data that could be evicted. May pause (host load) or
                    // throw (cannot drain) — then the caller resumes later.
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

        if ($refused > 0) {
            mtrace("local_intellistream: {$entity} — {$refused} row(s) PERMANENTLY REFUSED by the "
                . 'buffer and not exported. The watermark has moved past them because retrying '
                . 'cannot help; they will not appear downstream until the row itself is smaller.');
        }
        mtrace("local_intellistream: {$entity} — {$rows} rows backfilled (batch {$batch}).");
        return $rows;
    }

    /**
     * Backfill flow-control: keep the shippable (`*.jsonl.closed`) backlog well
     * under `config::max_buffer_bytes()` so the shipper never has a reason to
     * drop un-shipped files.
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

        // Measure what buffer::have_capacity() measures — active + closed +
        // `.pulled` — via the one shared accounting method. This used to count
        // only `*.jsonl.closed`, so the test that decides "keep streaming" and
        // the test that decides "accept this append" could disagree: a site with
        // a large `.pulled` backlog got headroom reported here while every append
        // was being refused there. Backpressure that is blind to part of the
        // buffer is not backpressure.
        if (buffer::occupied_bytes($dir) <= $safe) {
            return; // Headroom — keep streaming.
        }

        // Drive a shipping pass (gated by host load, exactly like the
        // ship_events task). Flush our own open file first so its rows
        // become shippable rather than lingering until age-rotation.
        buffer::flush();
        shipper::run();
        if (buffer::occupied_bytes($dir) <= $safe) {
            return;
        }

        throw new \RuntimeException(
            'backfill paused: buffer backlog is over half the disk cap and one shipping pass '
            . 'could not drain it (host load over the ship gate, or object storage unreachable) '
            . '— the run is resumable and continues from the watermark on the next pass.'
        );
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
            mtrace('local_intellistream: role_get_name failed for role '
                . ($role->id ?? '?') . ': ' . $e->getMessage());
        }
        $raw = trim((string)($role->name ?? ''));
        return $raw !== '' ? $raw : (string)($role->shortname ?? '');
    }

    /**
     * Dispatch a derived entity to its bespoke exporter method.
     *
     * @param string $entity Registry key of a `'derived' => true` entity.
     * @param string $batch  snapshot_batch UUID shared by this export run.
     * @return int Rows exported.
     */
    protected static function export_derived(string $entity, string $batch): int {
        switch ($entity) {
            case 'userlogins':
                return self::export_userlogins($batch);
            default:
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
     * @return int Rows exported (= distinct users with >=1 login event).
     */
    protected static function export_userlogins(string $batch): int {
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

            $rs = $DB->get_recordset_sql($sql, $params);
            foreach ($rs as $row) {
                $payload = [
                    'id'             => self::entity_uuid($siteid, 'userlogins', $row->id),
                    'site_id'        => $siteid,
                    'captured_at'    => clock::now(),
                    'plugin_version' => $pluginversion,
                    'moodle_version' => $moodleversion,
                    'record_type'    => 'entity_snapshot',
                    'entity'         => 'userlogins',
                    'snapshot_batch' => $batch,
                    'entity_data'    => [
                        'id'     => (int)$row->id,
                        'logins' => (int)$row->logins,
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
            mtrace("local_intellistream: entity 'userlogins' export error: " . $e->getMessage());
        } finally {
            // Close on EVERY exit, not just the happy path.
            // Several of these loops throw by design, so a close() placed after
            // the foreach leaked the cursor on the paths that matter most.
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
     * @param array<string, array{table:string, columns?:string, derived?:bool}> $registry
     * @return array<string, array{table:string, columns?:string, derived?:bool}>
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
}
