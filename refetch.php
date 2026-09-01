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
 * Admin page: targeted re-fetch.
 *
 * A form (date/time window + entity multi-select + Run) that queues a one-off
 * targeted re-fetch as an adhoc task. Replaces the earlier settings-fields +
 * scheduled-task-"Run now" flow. Wired into the admin tree as
 * `local_intellistream_refetch` (see settings.php).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_intellistream\helpers\settings_helper;
use local_intellistream\output\forms\refetch_form;
use local_intellistream\exporter;
use local_intellistream\task\run_targeted_refetch_adhoc_task;

global $CFG, $DB, $OUTPUT, $PAGE;
require_once($CFG->libdir . '/adminlib.php');

require_login();

$context = \context_system::instance();
require_capability(settings_helper::CAP_MANAGE, $context);

admin_externalpage_setup('local_intellistream_refetch');

$pageurl = new \moodle_url('/local/intellistream/refetch.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname_refetch_page', 'local_intellistream'));
$PAGE->set_heading(get_string('pluginname_refetch_page', 'local_intellistream'));

// Entity picker options = the effective registry keys. Fall back to the
// built-in registry (no DB) if the override lookup fails.
try {
    $entitykeys = array_keys(exporter::registry_with_overrides());
} catch (\Throwable $e) {
    $entitykeys = array_keys(exporter::registry());
}
sort($entitykeys);
$entityoptions = array_combine($entitykeys, $entitykeys);

$form = new refetch_form($pageurl, ['entityoptions' => $entityoptions]);

if ($form->is_cancelled()) {
    redirect(new \moodle_url('/admin/settings.php', ['section' => 'local_intellistream']));
} else if ($data = $form->get_data()) {
    $entities = !empty($data->entities) ? array_values($data->entities) : [];
    $from = (int)$data->from;
    $to = (int)$data->to;

    // Server-side guard mirroring the webhook path: never queue a task for an
    // invalid window (form validation is the first line of defence, but keep a
    // hard guard here so a bypassed/degenerate window can't reach the adhoc task).
    if ($from <= 0 || $to <= 0 || $from >= $to) {
        redirect(
            $pageurl,
            get_string('targeted_err_order', 'local_intellistream'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Bound the queue. This page is gated on local/intellistream:manage, which
    // db/access.php grants to the manager archetype by default, and each
    // submission queues a task that — with an empty entity selection — re-scans
    // and re-emits every registry table. Nothing deduplicated or rate-limited
    // repeated submissions, so a non-admin role on shared infrastructure could
    // queue an arbitrary number of full-site re-exports: a heavy DB-read and
    // egress lever, even though the destination already receives everything via
    // the daily snapshot.
    //
    // Refuse while one is still waiting to run. Deliberately counts only
    // NOT-YET-STARTED tasks (timestarted IS NULL), so a long-running re-export
    // does not lock the page for its whole duration — one queued follow-up is
    // legitimate, an unbounded pile is not.
    $pending = $DB->count_records_select(
        'task_adhoc',
        'classname = :cls AND timestarted IS NULL',
        ['cls' => '\\' . run_targeted_refetch_adhoc_task::class]
    );
    if ($pending > 0) {
        redirect(
            $pageurl,
            get_string('targeted_err_queued', 'local_intellistream'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $task = new run_targeted_refetch_adhoc_task();
    $task->set_custom_data(['entities' => $entities, 'from' => $from, 'to' => $to]);
    \core\task\manager::queue_adhoc_task($task);

    $a = (object)[
        'count' => $entities ? count($entities) : get_string('targeted_all_entities', 'local_intellistream'),
        'from'  => userdate($from),
        'to'    => userdate($to),
    ];
    redirect(
        $pageurl,
        get_string('targeted_queued', 'local_intellistream', $a),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Sensible defaults: the last 7 days.
$form->set_data(['from' => time() - (7 * DAYSECS), 'to' => time()]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname_refetch_page', 'local_intellistream'));
$form->display();
echo $OUTPUT->footer();
