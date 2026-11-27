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
 * Entity registry: the Moodle tables the plugin exports.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\local;

/**
 * Entity registry: the Moodle tables the plugin exports, read by exporter::registry().
 */
class entity_registry {
    /**
     * Every entity the plugin exports.
     *
     * Each entry is snapshot name => definition:
     * - table: the Moodle table name without the prefix.
     * - columns: the SELECT list; '*' exports the whole row.
     * - derived: true when a task of its own computes and sends the rows (userlogins);
     *   table then names the table whose presence is still checked.
     * - clockless: true when the table has rows whose watermark column is NULL or 0.
     * - wmcol: the watermark column, when it is not the first one found.
     *
     * Column lists are explicit for the larger tables, so that the export is stable
     * when a site has extra columns, and so that sensitive columns are never exported
     * (exporter::FORBIDDEN_COLUMNS enforces this).
     *
     * @return array<string, array>
     */
    public static function definitions(): array {
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
                // Timestamp-less table: `added` (insert time) lets the 15-minute
                // incremental ship new activities as a safety net; create/update
                // capture is driven by the course_module_* event observers
                // (see db/events.php and \local_intellistream\observer). `added`
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
                // Here `modified` (changed on edit) drives the 15-minute incremental,
                // so it catches new posts and edits. High volume, so kept on the
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
            // Note `enrolmentkey` is not exported: it is the key a user types to join a
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
                // Append-only; `time` (answer timestamp) drives the 15-minute incremental.
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
                // Any `password`/secret-bearing field is not in this column
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
                // of both schemas costs nothing on 4.x (resolve_entity_columns()
                // intersects this list against $DB->get_columns(), so a column the host
                // does not have is dropped), and it is the only way the question ->
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
                // dropped by the resolver, as intended.
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
                // row, and question_versions is exported, so the join needs this
                // row to reach the category. `ownerid` is a user
                // reference of the same kind as question.createdby/modifiedby, which
                // are exported too; the other three are structural ids.
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

            // Commerce tables of the local_intellicart plugin.
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
            // Note `seatkey` is not exported. It is a bearer credential: local_intellicart's
            // own privacy metadata calls it "a seat key to use as a coupon code", and
            // seats::apply_seatkey() redeems a paid seat on possession of the value
            // alone. Same for local_intellicart_coupons.code below.
            //
            // Both are filtered: `seatkey` via FORBIDDEN_COLUMNS, `code` via
            // FORBIDDEN_TABLE_COLUMNS, because a bare `code` is legitimate on other
            // tables. They are left out of the SELECT lists here as well, so the
            // registry shows what is exported; the filter is the guarantee.
            //
            // Removing a column here also removes it from the dynamic-table schema
            // catalog (see export_inform_dyn_schema), so a downstream report that
            // still selects it fails.
            'local_intellicart_seats' => [
                'table'   => 'local_intellicart_seats',
                'columns' => 'id, userid, productid, quantity, '
                    . 'sessionid, checkoutid, active, expiration, timecreated, '
                    . 'timemodified',
            ],
            // Note `code` is not exported; see local_intellicart_seats above.
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
            // Note `code` is not exported: it is the token mod_customcert's
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
            // Per-user attendance is not in Moodle; it is pulled from the
            // Blackboard cloud API by the collab_sync task (see colpart below).
            'collaborate' => [
                'table'   => 'collaborate',
                'columns' => 'id, course, name, sessionid, sessionuid, '
                    . 'timestart, duration, timeend, grade, timecreated, '
                    . 'timemodified',
            ],
            // Collaborate per-user attendance: this plugin's own table, filled
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
            // are not exported.
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
            // Read this before changing anything here: these entries list the full
            // text of private messages (`fullmessage`, `fullmessagehtml` and
            // `smallmessage` are the complete message body, not metadata about it).
            //
            // The body columns are not exported by default:
            // strip_forbidden_columns() removes MESSAGE_BODY_COLUMNS from this entry
            // and from `message` below unless an admin has enabled
            // `exportmessagebodies`, which defaults off and is not writable over the
            // control webhook. Metadata (who, when, which conversation) still ships.
            //
            // The columns stay in this list on purpose: the filter enforces the
            // decision, so enabling the setting needs no registry edit.
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
            // in the standard logstore; this entity does likewise. Kept and sent
            // by its own task (userlogins, task\count_userlogins; see `derived`).
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

            // Questionnaire (mod_questionnaire, contrib module).
            'questionnaire' => [
                'table'   => 'questionnaire',
                'columns' => 'id, course, name, intro, introformat, qtype, '
                    . 'respondenttype, resp_eligible, resp_view, notifications, '
                    . 'opendate, closedate, resume, navigate, grade, sid, '
                    . 'timemodified, completionsubmit, autonum, progressbar',
            ],
            // Column list matches the reporting warehouse's questionnaire_dependency
            // table (`author_id` there is added downstream, not a Moodle column,
            // so it is not listed).
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
                // Do not add `feedbacknotifications`: mod_questionnaire has no such
                // column, and a missing column fails the whole table's SELECT.
                'columns' => 'id, name, courseid, realm, status, title, email, '
                    . 'subtitle, info, theme, thanks_page, thank_head, thank_body, '
                    . 'feedbacksections, feedbacknotes, feedbackscores, chart_type',
            ],
            'questionnaire_attempts' => [
                'table'   => 'questionnaire_attempts',
                'columns' => 'id, qid, userid, rid, timemodified',
            ],
            // Lesson (mod_lesson).
            // `password` is not exported: it is the gate a student types to open the
            // lesson, i.e. a bearer credential. The
            // `usepassword` boolean stays, so a report can still show whether a
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
            // Database, wiki, glossary and chat activities.
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
            // Resources (URL, page, file, book, folder, label, IMS content package).
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
            // Core: files, tags, calendar events and grade history.
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
            // Grading guides, quiz and question data, survey questions and assignment details.
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
            // Competencies, course format options, role context levels, cohort roles,
            // enrolment plugins and the legacy message table.
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
            // Note `secret` is not exported. It is the LTI 1.1 consumer shared secret:
            // enrol/lti/classes/tool_provider.php authenticates an inbound launch
            // with `$this->tool->secret == $this->consumer->secret`, so anyone
            // holding it, plus the `uuid` and role/provisioning columns exported
            // alongside, could forge a launch against this site's
            // enrol/lti/tool.php and be auto-provisioned into the course.
            // `uuid` is kept: without the secret it is an
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
            // unless an admin opts in; see the note there. This entity is named in
            // MESSAGE_ENTITIES, which is what makes the gate apply to it.
            'message' => [
                'table'   => 'message',
                'columns' => 'id, useridfrom, useridto, subject, fullmessage, fullmessageformat, fullmessagehtml, '
                    . 'smallmessage, notification, contexturl, contexturlname, timecreated, timeuserfromdeleted, '
                    . 'timeusertodeleted, component, eventtype, customdata',
            ],
        ];
    }
}
