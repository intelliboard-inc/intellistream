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
 * Dynamic-table discovery for local_intellistream.
 *
 * Auto-registers live Moodle tables matching the configured plugin-family
 * prefixes (default `local_intellicart_`) as InForm dynamic-table CANDIDATES,
 * so a migrating Pro (local_intellidata) customer's IntelliCart tables surface
 * downstream as in_form_table_local_intellicart_* — push-native parity with
 * intellidata's `local_intellidata_get_dbschema_custom` auto-discovery.
 *
 * Registers candidates only. It NEVER activates a table or ships data — the
 * admin still enables dynamic tables on the connection and activates each
 * table in supernova. Read-only against the rest of the Moodle DB.
 *
 * Usage:
 * php local/intellistream/cli/discover_dynamic_tables.php [--dry-run]
 *
 * --dry-run   List the tables that would be registered; write nothing.
 * --help      Show this help.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help'    => false,
        'dry-run' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    echo "IntelliStream dynamic-table discovery.\n\n"
        . "Registers plugin-family tables (default prefix local_intellicart_) as\n"
        . "InForm dynamic-table candidates. Candidates only — activation stays an\n"
        . "explicit admin action in supernova.\n\n"
        . "Options:\n"
        . "  --dry-run   List what would be registered; write nothing.\n"
        . "  -h, --help  This help.\n";
    exit(0);
}

$prefixes = \local_intellistream\services\dynamic_discovery_service::prefixes();
if (empty($prefixes)) {
    cli_error("No discovery prefixes configured. Set local_intellistream/dynamic_discovery_prefixes "
        . "(default 'local_intellicart_') before running discovery.");
}

$service = new \local_intellistream\services\dynamic_discovery_service();

if ($options['dry-run']) {
    $match = $service->match_tables($prefixes);
    cli_writeln('DRY RUN — nothing registered.');
    cli_writeln('prefixes:       ' . implode(', ', $prefixes));
    cli_writeln('would register: ' . count($match));
    if ($match) {
        cli_writeln('  ' . implode("\n  ", $match));
    }
    exit(0);
}

cli_heading('IntelliStream dynamic-table discovery');
$result = $service->discover();
cli_writeln('prefixes:   ' . implode(', ', $result['prefixes']));
cli_writeln(sprintf('registered: %d table(s) as InForm dynamic-table candidate(s).', $result['registered']));
if (!empty($result['tables'])) {
    cli_writeln('  ' . implode("\n  ", $result['tables']));
}
cli_writeln('');
cli_writeln('Candidates only. Enable dynamic tables on the connection and activate '
    . 'each table in supernova to materialise it.');
exit(0);
