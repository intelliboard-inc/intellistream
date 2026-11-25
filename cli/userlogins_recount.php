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
 * Ask for every user's login count to be counted again from the standard log.
 *
 * Only records the request: the count_userlogins task does the work on its next runs,
 * in short blocks. The warehouse keeps the current totals until the new count is
 * complete; then every total is sent.
 *
 * Usage:
 * php local/intellistream/cli/userlogins_recount.php
 *
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
        'help' => false,
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
    echo "IntelliStream login counts: count again from the standard log.\n\n"
        . "Records the request only. The 'Count user logins' task counts the whole log again\n"
        . "on its next runs, then sends every total.\n\n"
        . "Options:\n"
        . "  -h, --help  This help.\n";
    exit(0);
}

if (\local_intellistream\userlogins::request_recount()) {
    echo "Recount requested.\n";
} else {
    echo "No new request was recorded: a count from zero is in progress, or the task has not run yet.\n"
        . "Either one counts the whole log, which gives the same result.\n";
}
$status = \local_intellistream\userlogins::status();
echo "Current state: {$status['state']}"
    . ($status['task_disabled'] ? ' (the task is DISABLED: enable it in Scheduled tasks)' : '') . ".\n";
exit(0);
