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
 * Near-real-time entity capture for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\observers;

use local_intellistream\exporter;

/**
 * Per-entity event observers that capture definition/state rows the moment
 * Moodle changes them, mirroring the legacy local_intellidata plugin.
 *
 * WHY: the every-15-min incremental (exporter::export_incremental) can only
 * ship a table that has a change-timestamp watermark column. Timestamp-less
 * tables (course_modules, course_completions, user_info_*, grade_letters) are
 * otherwise captured ONLY by the once-daily full snapshot — so a newly created
 * activity would not appear in reports for up to ~24h. These
 * observers close that gap: on create/update they snapshot the changed row into
 * the buffer, which ship_events flushes to S3 within ~1 min.
 *
 * SAFETY: every handler delegates to exporter::capture_entity_row/match(), which
 * guards config::enabled(), emits a BYTE-IDENTICAL entity_snapshot (deterministic
 * entity_uuid -> UPSERT in place, never a duplicate), performs NO mtrace() output,
 * and swallows all throwables. So an observer can never break the host page render.
 *
 * Row volume: every handler here resolves to a small, indexed set of rows, which is
 * what makes inline capture appropriate. That is now true by construction — the one
 * path that could fan out to a whole course (course_completions with no
 * relateduserid) no longer does; see capture_completions() below.
 *
 * DELETES are intentionally NOT observed: row removals are reconciled by the
 * daily entity_census (reconcile_deletes) downstream — the canonical contract
 * carries no per-record delete signal. The generic observer::capture still logs
 * the delete to the raw event stream, unchanged.
 */
class entity_observer {
    /**
     * Activity (course module) created.
     *
     * @param \core\event\course_module_created $event
     * @return void
     */
    public static function course_module_created(\core\event\course_module_created $event): void {
        exporter::capture_entity_row('course_modules', $event->objectid);
    }

    /**
     * Activity (course module) updated (renamed, moved, visibility/completion changed).
     *
     * @param \core\event\course_module_updated $event
     * @return void
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        exporter::capture_entity_row('course_modules', $event->objectid);
    }

    /**
     * A user completed a course.
     *
     * @param \core\event\course_completed $event
     * @return void
     */
    public static function course_completed(\core\event\course_completed $event): void {
        self::capture_completions($event);
    }

    /**
     * Course completion state updated.
     *
     * @param \core\event\course_completion_updated $event
     * @return void
     */
    public static function course_completion_updated(\core\event\course_completion_updated $event): void {
        self::capture_completions($event);
    }

    /**
     * Snapshot the course_completions row this event affects.
     *
     * USER-SCOPED ONLY, by design. Previously this
     * fell back to matching on `course` alone when the event carried no
     * `relateduserid`, which meant a whole course's completion rows — tens of
     * thousands on a large course, each one read and appended to the buffer
     * synchronously while a teacher waited for the completion-settings form to
     * save.
     *
     * Skipping that case costs very little, because the analytically meaningful
     * transitions do not arrive through it:
     *
     *  - `course_completed` sets `relateduserid` (core's
     *    lib/classes/event/course_completed.php), so every actual completion —
     *    including the ones Moodle's completion cron detects after a criteria
     *    change — is still captured inline, one indexed row at a time.
     *  - `course_completion_updated` is fired from course/completion.php with only
     *    `courseid` and `context`. It signals that the completion CRITERIA changed;
     *    its immediate effect on these rows is the `reaggregate` bookkeeping flag,
     *    not a user-visible completion state.
     *
     * The residual gap is a row that changes without any per-user event — chiefly
     * an un-completion after criteria are tightened. `course_completions` has no
     * timemodified-style column, so exporter::WATERMARK_COLUMNS finds no watermark
     * and the 15-minute incremental cannot see it either; the daily full snapshot
     * is the backstop, so such a row can be up to a day stale. That trade is
     * deliberate, and preferable to the alternative of queueing an adhoc task,
     * which would have put a write to a CORE table (task_adhoc) on the capture
     * path — a property this plugin otherwise holds.
     *
     * @param \core\event\base $event
     * @return void
     */
    private static function capture_completions(\core\event\base $event): void {
        $courseid = (int) $event->courseid;
        $userid = (int) ($event->relateduserid ?? 0);
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        exporter::capture_entity_match('course_completions', ['course' => $courseid, 'userid' => $userid]);
    }

    /**
     * New user: capture that user's profile-field values.
     *
     * @param \core\event\user_created $event
     * @return void
     */
    public static function user_created(\core\event\user_created $event): void {
        exporter::capture_entity_match('user_info_data', ['userid' => $event->objectid]);
    }

    /**
     * User updated: profile-field values may have changed.
     *
     * @param \core\event\user_updated $event
     * @return void
     */
    public static function user_updated(\core\event\user_updated $event): void {
        exporter::capture_entity_match('user_info_data', ['userid' => $event->objectid]);
    }

    /**
     * Custom profile field definition created.
     *
     * @param \core\event\user_info_field_created $event
     * @return void
     */
    public static function user_info_field_created(\core\event\user_info_field_created $event): void {
        exporter::capture_entity_row('user_info_field', $event->objectid);
    }

    /**
     * Custom profile field definition updated.
     *
     * @param \core\event\user_info_field_updated $event
     * @return void
     */
    public static function user_info_field_updated(\core\event\user_info_field_updated $event): void {
        exporter::capture_entity_row('user_info_field', $event->objectid);
    }

    /**
     * Profile field category created.
     *
     * @param \core\event\user_info_category_created $event
     * @return void
     */
    public static function user_info_category_created(\core\event\user_info_category_created $event): void {
        exporter::capture_entity_row('user_info_category', $event->objectid);
    }

    /**
     * Profile field category updated.
     *
     * @param \core\event\user_info_category_updated $event
     * @return void
     */
    public static function user_info_category_updated(\core\event\user_info_category_updated $event): void {
        exporter::capture_entity_row('user_info_category', $event->objectid);
    }

    /**
     * Grade letter (grade-to-letter boundary) created.
     *
     * @param \core\event\grade_letter_created $event
     * @return void
     */
    public static function grade_letter_created(\core\event\grade_letter_created $event): void {
        exporter::capture_entity_row('grade_letters', $event->objectid);
    }

    /**
     * Grade letter updated.
     *
     * @param \core\event\grade_letter_updated $event
     * @return void
     */
    public static function grade_letter_updated(\core\event\grade_letter_updated $event): void {
        exporter::capture_entity_row('grade_letters', $event->objectid);
    }
}
