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
 * Scheduled dynamic-table discovery for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\task;

/**
 * Daily dynamic-table discovery — keeps the InForm dynamic-table candidate
 * catalog in sync with the live plugin-family tables (default
 * `local_intellicart_*`). Push-native parity with intellidata, which
 * auto-discovered optional tables on every export.
 *
 * Registered for ~02:50, daily. Registers CANDIDATES only (a discovered row per
 * matching table) — never activates; activation stays the admin's explicit
 * action in supernova. A candidate joins the export registry only while the
 * locally managed `dynamicdiscoveryexport` setting is on (off by default); then
 * the verification sweep inside the 15-minute task picks it up on its next run
 * (it re-reads the registry every run) — the design is eventually-consistent.
 * With the setting off, none of its rows leave the site; the datatype catalog
 * still names it, marked as not exporting.
 * No-ops when the master switch is off or no discovery prefixes are configured.
 */
class discover_dynamic_tables extends \core\task\scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_discover_dynamic_tables', 'local_intellistream');
    }

    /**
     * Register newly-appeared plugin-family tables as dynamic-table candidates.
     *
     * Only ever creates candidates; it never activates one or ships data, so a
     * new table cannot start being exported without an explicit decision.
     *
     * @return void
     */
    public function execute(): void {
        // A long-lived cron process may hold settings and a registry read before an
        // admin changed them; start from what is stored now.
        \local_intellistream\config::refresh();
        \local_intellistream\exporter::reset_registry_cache();

        if (!\local_intellistream\config::enabled()) {
            mtrace('local_intellistream: disabled — dynamic-table discovery skipped.');
            return;
        }

        $prefixes = \local_intellistream\services\dynamic_discovery_service::prefixes();
        if (empty($prefixes)) {
            mtrace('local_intellistream: no dynamic-table discovery prefixes configured — discovery skipped.');
            return;
        }

        $result = (new \local_intellistream\services\dynamic_discovery_service())->discover();
        mtrace(sprintf(
            'local_intellistream: dynamic-table discovery — %d table(s) registered as candidate(s) (prefixes: %s).',
            $result['registered'],
            implode(', ', $result['prefixes'])
        ));

        if (!\local_intellistream\services\config_service::dynamic_discovery_export_enabled()) {
            global $DB;
            $builtin = \local_intellistream\exporter::registry();
            $candidates = 0;
            $rows = $DB->get_records(
                \local_intellistream\repositories\config_repository::TABLE,
                ['discovered' => 1, 'enabled' => 1],
                '',
                'id, datatype'
            );
            foreach ($rows as $row) {
                if (!isset($builtin[$row->datatype])) {
                    $candidates++;
                }
            }
            mtrace(sprintf(
                "local_intellistream: %d discovered table(s) are candidates only, not exported: '%s' is off.",
                $candidates,
                get_string('dynamicdiscoveryexport', 'local_intellistream')
            ));
        }
    }
}
