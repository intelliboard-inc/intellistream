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
 * CLI: re-read a window of the standard logstore and ship it for count-once recovery.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_intellistream\window_tool;

[$options, $unrecognised] = cli_get_params(
    [
        'help'          => false,
        'from'          => '',
        'to'            => '',
        'course'        => 0,
        'dry-run'       => false,
        'max-rows'      => window_tool::DEFAULT_MAX_ROWS,
        'rows-per-hour' => '',
        'max-seconds'   => 0,
        'confirm-large' => false,
        'resume'        => '',
        'status'        => false,
        'stop'          => '',
        'background'    => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if (
    $options['help'] || ($options['from'] === '' && $options['resume'] === ''
        && !$options['status'] && $options['stop'] === '')
) {
    echo "IntelliStream recovery: re-read a window of the standard logstore.\n\n"
        . "Ships logstore rows of [from, to) so the pipeline can add back the activity events\n"
        . "the buffer refused. The pipeline adds only events it does not already have, so a\n"
        . "window can be re-read safely. Nothing in Moodle is changed.\n\n"
        . "Options:\n"
        . "  --from=TIME         Window start, ISO time with a zone (e.g. 2026-10-21T00:00:00Z).\n"
        . "  --to=TIME           Window end (exclusive), same format.\n"
        . "  --course=ID         Only this course.\n"
        . "  --dry-run           Check the window and count its rows; ship nothing.\n"
        . "  --max-rows=N        Refuse a bigger window (default " . window_tool::DEFAULT_MAX_ROWS
        . ", ceiling " . window_tool::HARD_MAX_ROWS . ").\n"
        . "  --confirm-large     Allow a window over --max-rows (never over the ceiling).\n"
        . "  --rows-per-hour=N   Pace in log rows read per hour (default " . window_tool::DEFAULT_ROWS_PER_HOUR
        . "; a resumed run keeps its own).\n"
        . "  --max-seconds=N     Stop after N seconds; resume later with --resume.\n"
        . "  --resume=REQUEST    Continue a paused run.\n"
        . "  --status            List runs and exit.\n"
        . "  --stop=REQUEST      Ask a run to stop at its next block ('*' = any).\n"
        . "  --background        Queue the run as an adhoc task (cron runs it in slices).\n\n"
        . "Exit codes: 0 done, 1 refused or error, 2 paused (resume later), 3 busy (another\n"
        . "window run holds the lock; try again later).\n\n"
        . "Example:\n"
        . "  php local/intellistream/cli/recover_window.php \\\n"
        . "      --from=2026-10-21T00:00:00Z --to=2026-11-02T00:00:00Z --dry-run\n";
    exit(0);
}

/**
 * An ISO time with a zone as epoch seconds; a time without a zone is refused (it would be
 * read in the server's zone and shift the window silently).
 *
 * @param string $text ISO 8601 time
 * @return int
 */
function local_intellistream_window_time(string $text): int {
    if (!preg_match('/(Z|[+-]\d\d:?\d\d)$/', $text)) {
        cli_error("'{$text}' has no time zone: add 'Z' or an offset such as +05:30");
    }
    $t = strtotime($text);
    if ($t === false) {
        cli_error("'{$text}' is not a time");
    }
    return (int)$t;
}

if ($options['status']) {
    $runs = window_tool::runs();
    if (!$runs) {
        cli_writeln('No recovery window runs on this site.');
    }
    foreach ($runs as $r) {
        cli_writeln(sprintf(
            '%s  %-8s %s -> %s  rows %d  (last id %d of %d)',
            $r['request_id'],
            $r['status'],
            gmdate('Y-m-d H:i', $r['window_from']),
            gmdate('Y-m-d H:i', $r['window_to']),
            $r['rows'],
            $r['last_id'],
            $r['upto_id']
        ));
    }
    exit(0);
}

if ($options['stop'] !== '') {
    $notset = window_tool::request_stop((string)$options['stop']);
    if ($notset !== null) {
        cli_error('NOT STOPPED: ' . $notset);
    }
    cli_writeln('Stop requested; the run stops at its next block.');
    exit(0);
}

$opts = [
    'resume'        => (string)$options['resume'],
    'max_rows'      => (int)$options['max-rows'],
    'rows_per_hour' => $options['rows-per-hour'] !== '' ? (int)$options['rows-per-hour'] : null,
    'max_seconds'   => (int)$options['max-seconds'],
    'confirm_large' => (bool)$options['confirm-large'],
    'output'        => function ($m) {
        cli_writeln($m);
    },
];
if ($opts['resume'] === '') {
    $opts['from'] = local_intellistream_window_time((string)$options['from']);
    $opts['to'] = local_intellistream_window_time((string)$options['to']);
    $opts['courseid'] = (int)$options['course'] ?: null;
}

if ($options['dry-run']) {
    if ($opts['resume'] !== '') {
        cli_error('--dry-run checks a new window; it does not combine with --resume');
    }
    $facts = window_tool::facts($opts['from'], $opts['to']);
    if ($facts['refuse']) {
        cli_error('REFUSED: ' . $facts['refuse']);
    }
    $est = window_tool::estimate($opts['from'], $opts['to'], $opts['courseid']);
    // The same size checks as the real run, so a dry run never says OK for a refused window.
    $sizerefusal = window_tool::size_refusal($est, $opts['max_rows'], $opts['confirm_large']);
    if ($sizerefusal !== null) {
        cli_error('REFUSED: ' . $sizerefusal);
    }
    cli_writeln("Window OK: {$est['rows']} row(s), logstore ids {$est['minid']}..{$est['maxid']}.");
    if ($facts['exclude_userids']) {
        cli_writeln('trackadmin was off: events of site admins ' . implode(',', $facts['exclude_userids'])
            . ' are left out, as the live capture did.');
    }
    if ($facts['webhook_log_since'] === null || $facts['webhook_log_since'] > $opts['from']) {
        cli_writeln('NOTE: IntelliBoard holds this window until it has confirmed that '
            . 'capture was not switched off over the control webhook.');
    }
    exit(0);
}

if ($options['background']) {
    // A new run gets its id now (as the webhook does), so a refusal when the task starts
    // is recorded under it and shows in --status.
    $requestid = '';
    if ($opts['resume'] === '') {
        $requestid = \core\uuid::generate();
        window_tool::save_queued($requestid, (int)$opts['from'], (int)$opts['to'], $opts['courseid']);
    }
    \local_intellistream\task\window_recovery_adhoc_task::queue([
        'resume' => $opts['resume'], 'request_id' => $requestid, 'from' => $opts['from'] ?? 0,
        'to' => $opts['to'] ?? 0, 'courseid' => $opts['courseid'] ?? null, 'max_rows' => $opts['max_rows'],
        'rows_per_hour' => $opts['rows_per_hour'], 'confirm_large' => $opts['confirm_large'],
    ]);
    cli_writeln($requestid !== '' ? "Queued request {$requestid}: the adhoc task runs the window in slices; see --status."
        : 'Queued: the adhoc task resumes the run in slices; see --status.');
    exit(0);
}

$state = window_tool::run($opts);
if (!empty($state['busy'])) {
    // Not final: another run holds the window lock. A distinct exit code lets a script
    // wait and try again instead of treating this as a refusal.
    cli_problem('BUSY: ' . $state['reason'] . '; try again later.');
    exit(3);
}
if ($state['status'] === 'refused') {
    cli_error('REFUSED: ' . $state['reason']);
}
cli_writeln(sprintf(
    'Request %s: %s, %d row(s) shipped%s.',
    $state['request_id'],
    $state['status'],
    $state['rows'],
    !empty($state['reason']) ? ' -- ' . $state['reason'] : ''
));
exit(!empty($state['paused']) ? 2 : 0);
