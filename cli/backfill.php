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
 * One-time RESUMABLE historical backfill for local_intellistream.
 *
 * Snapshots every registered Moodle entity into the buffer under a single
 * snapshot_batch, so a fresh IntelliStream install captures the state that
 * existed before the event observer was switched on. The buffered records
 * ship through the normal shipper task.
 *
 * Resumable: progress is tracked with a per-entity keyset watermark in plugin
 * config, so an interrupted run (timeout / reboot) is resumed from where it
 * stopped on the next run — never restarted from zero. Re-run this command to
 * resume; it is a no-op once the campaign is complete.
 *
 * Read-only against Moodle: issues only SELECTs.
 *
 * Usage:
 * php local/intellistream/cli/backfill.php [--entity=NAME] [--list]
 * [--status] [--restart] [--dry-run]
 *
 * --entity=NAME  Backfill only the named entity/entities (comma-separated,
 * partial run — does not emit the terminal census/schema and
 * does not mark the campaign complete).
 * --status       Print backfill progress (entities done, watermarks) and exit.
 * --restart      Clear all backfill state and start a fresh campaign.
 * --list         Print the entity registry and exit.
 * --dry-run      Report what would run; export nothing.
 * --help         Show this help.
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
        'list'    => false,
        'status'  => false,
        'restart' => false,
        'dry-run' => false,
        'entity'  => '',
    ],
    [
        'h' => 'help',
        'l' => 'list',
        's' => 'status',
    ]
);

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    echo "IntelliStream resumable historical backfill.\n\n"
        . "Snapshots Moodle entity rows into the IntelliStream buffer, resumably.\n\n"
        . "Options:\n"
        . "  --entity=NAME   Backfill only this entity (repeatable, comma-separated; partial run).\n"
        . "  --status        Show backfill progress and exit.\n"
        . "  --restart       Clear all backfill state and start over.\n"
        . "  --list          List the entity registry and exit.\n"
        . "  --dry-run       Report what would run without exporting.\n"
        . "  -h, --help      This help.\n\n"
        . "Examples:\n"
        . "  php local/intellistream/cli/backfill.php\n"
        . "  php local/intellistream/cli/backfill.php --status\n"
        . "  php local/intellistream/cli/backfill.php --entity=user,course\n";
    exit(0);
}

$registry = \local_intellistream\exporter::registry();

if ($options['list']) {
    cli_writeln('IntelliStream entity registry:');
    foreach ($registry as $name => $spec) {
        cli_writeln(sprintf('  %-26s -> {%s}', $name, $spec['table']));
    }
    cli_writeln(sprintf('%d entities.', count($registry)));
    exit(0);
}

if ($options['status']) {
    $status = \local_intellistream\backfill::status();
    cli_writeln('IntelliStream historical backfill status:');
    cli_writeln('  complete:      ' . ($status['complete'] ? 'yes' : 'no'));
    cli_writeln('  entities done: ' . $status['entities_done'] . ' / ' . $status['entities_total']);
    cli_writeln('  batch:         ' . ($status['batch'] !== '' ? $status['batch'] : '(not started)'));
    $wmcount = empty($status['watermarks'])
        ? '(none)'
        : count($status['watermarks']) . ' entit(y/ies) in progress';
    cli_writeln('  watermarks:    ' . $wmcount);
    foreach ($status['watermarks'] as $entity => $wm) {
        cli_writeln(sprintf('    %-26s id > %d', $entity, $wm));
    }
    exit(0);
}

// The backfill writes records the shipper later sends off-site, so respect the
// master switch — running it while disabled would buffer records that never ship.
if (!\local_intellistream\config::enabled()) {
    cli_error('local_intellistream is disabled (enable it in the plugin settings '
        . 'before backfilling).');
}

// Resolve the requested entity set (for --entity / --dry-run reporting).
$selected = array_keys($registry);
if ($options['entity'] !== '') {
    $requested = array_filter(array_map('trim', explode(',', (string)$options['entity'])));
    $selected = [];
    foreach ($requested as $name) {
        if (!isset($registry[$name])) {
            cli_error("Unknown entity '{$name}'. Use --list to see valid names.");
        }
        $selected[] = $name;
    }
}

if ($options['restart']) {
    \local_intellistream\backfill::reset();
    cli_writeln('Backfill state cleared — starting a fresh campaign.');
}

if ($options['dry-run']) {
    cli_writeln('DRY RUN — nothing will be exported.');
    cli_writeln('buffer dir:   ' . \local_intellistream\config::buffer_dir());
    cli_writeln('would export: ' . implode(', ', $selected));
    exit(0);
}

cli_heading('IntelliStream resumable historical backfill');
cli_writeln('buffer dir: ' . \local_intellistream\config::buffer_dir());
cli_writeln('entities:   ' . count($selected) . ($options['entity'] !== '' ? ' (partial run)' : ''));
cli_writeln('');

$started = microtime(true);
$only = $options['entity'] !== '' ? $selected : [];
$result = \local_intellistream\backfill::run($only);
$elapsed = round(microtime(true) - $started, 1);

cli_writeln('');
cli_writeln(sprintf(
    'Done: %d row(s) buffered, %d/%d entities done, %s in %ss (batch %s).',
    $result['rows'],
    $result['entities_done'],
    $result['entities_total'],
    $result['complete'] ? 'COMPLETE' : ('NOT complete — ' . ($result['reason'] ?? 're-run to resume')),
    $elapsed,
    $result['batch']
));
if (!$result['complete']) {
    cli_writeln('Re-run this command to resume from the saved watermark.');
}
cli_writeln('Records ship on the next ship_events run (and inline during the backfill).');
exit(0);
