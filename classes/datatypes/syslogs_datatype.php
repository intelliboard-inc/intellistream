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
 * Syslogs datatype marker for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\datatypes;

/**
 * Marker class for the `syslogs` datatype.
 *
 * Implementation strategy
 * -----------------------
 * intellidata's `syslogs` entity (see
 * intellidata/classes/entities/syslogs/syslogs.php) is a thin
 * (timecreated, message) record fed only by
 * `DebugHelper::error_log()` (it captures intellidata's own debug
 * stream, NOT site-wide admin actions). For IntelliStream we need a
 * useful parity-of-coverage feed for downstream "admin actions" /
 * site-administration reporting -- so we DO NOT mirror intellidata's
 * narrow debug-log shape and DO NOT introduce a new shipped record
 * type.
 *
 * Instead, the existing event observer
 * (`\local_intellistream\observer::capture` registered on
 * `\core\event\base`) already captures every Moodle event the site
 * emits, including the core admin-action events that constitute
 * "system logs" in IntelliBoard's reporting sense. `syslogs` is
 * therefore implemented as a dbt FILTERED VIEW over the existing
 * `db_logstore_standard_log_raw` source (the warehouse copy of
 * Moodle's standard logstore), not as a new raw feed.
 *
 * Filter rule
 * -----------
 * "Admin actions" -- the event classes in core / a sub-component that
 * change site, role, capability, plugin or admin-touched user state:
 *
 *   role / capability changes
 *     \core\event\role_assigned, \core\event\role_unassigned,
 *     \core\event\role_capabilities_updated, \core\event\role_created,
 *     \core\event\role_updated, \core\event\role_deleted,
 *     \core\event\role_allow_*
 *
 *   plugin install / upgrade
 *     \core\event\plugin_*  (covers _installed, _upgraded, _uninstalled,
 *     _enabled, _disabled)
 *
 *   config edits
 *     \core\event\config_log_created
 *     (Moodle writes a config_log row for every admin config change;
 *      this event is the canonical signal.)
 *
 *   user actions by an admin
 *     \core\event\user_created, \core\event\user_updated,
 *     \core\event\user_deleted, \core\event\user_password_updated,
 *     \core\event\user_loggedinas
 *
 *   course-level admin
 *     \core\event\course_created, \core\event\course_updated,
 *     \core\event\course_deleted, \core\event\course_restored,
 *     \core\event\course_backup_created,
 *     \core\event\course_category_*
 *
 * The dbt view that materialises this filter is
 * `unified_tables/staging/stg__syslogs.sql`. See that file for the
 * exact LIKE patterns used; this class merely documents the
 * datatype's existence and its conceptual schema.
 *
 * Conceptual schema (column names match `db_logstore_standard_log_raw`):
 *
 *   id              bigint   logstore row id
 *   eventname       text     `\core\event\...` class
 *   action          text     verb (created/updated/deleted/...)
 *   crud            char(1)  c/r/u/d
 *   userid          bigint   acting user
 *   relateduserid   bigint   user the action was performed on (or null)
 *   courseid        bigint   course context (0 for site)
 *   contextid       bigint   raw context id
 *   contextlevel    int      Moodle context level (10/40/50/70/...)
 *   timecreated     bigint   unix seconds
 *   ip              text     acting user's IP (logstore-stored)
 *   other           text     event-specific JSON
 *
 * @copyright 2026 IntelliBoard / IntelliStream
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syslogs_datatype {
    /** Entity / registry key (warehouse-side, used by dbt). */
    const ENTITY = 'syslogs';

    /**
     * The eventname-LIKE patterns the dbt view filters on.
     *
     * Provided here so that integration tests and any future Moodle-side
     * helpers can stay in sync with the warehouse rule. The dbt model is
     * the source of truth; this list MUST be kept identical to the WHERE
     * clause in `stg__syslogs.sql`.
     *
     * @return string[]
     */
    public static function admin_action_eventname_patterns(): array {
        return [
            // Role / capability changes.
            '\\core\\event\\role\\_%',
            // Plugin install / upgrade / enable / disable.
            '\\core\\event\\plugin\\_%',
            // Site config edits.
            '\\core\\event\\config\\_log\\_created',
            // User actions performed by an admin.
            '\\core\\event\\user\\_created',
            '\\core\\event\\user\\_updated',
            '\\core\\event\\user\\_deleted',
            '\\core\\event\\user\\_password\\_updated',
            '\\core\\event\\user\\_loggedinas',
            // Course-level admin actions.
            '\\core\\event\\course\\_created',
            '\\core\\event\\course\\_updated',
            '\\core\\event\\course\\_deleted',
            '\\core\\event\\course\\_restored',
            '\\core\\event\\course\\_backup\\_created',
            '\\core\\event\\course\\_category\\_%',
        ];
    }
}
