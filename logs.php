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
 * Admin page: operational log viewer.
 *
 * Lists rows from `local_intellistream_logs` in reverse-chronological order with
 * optional filters for type / datatype / action. Wired into the admin tree as
 * `local_intellistream_logs` (see settings.php).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_intellistream\helpers\settings_helper;

global $CFG, $DB, $OUTPUT, $PAGE;
require_once($CFG->libdir . "/adminlib.php");
require_once($CFG->libdir . '/tablelib.php');

require_login();

$context = \context_system::instance();
require_capability(settings_helper::CAP_VIEW_LOGS, $context);

admin_externalpage_setup('local_intellistream_logs');

$type = optional_param('type', '', PARAM_ALPHANUMEXT);
$datatype = optional_param('datatype', '', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$baseurl = new \moodle_url('/local/intellistream/logs.php', array_filter([
    'type'     => $type,
    'datatype' => $datatype,
    'action'   => $action,
], static function ($v) {
    return $v !== '' && $v !== null;
}));

$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname_logs_page', 'local_intellistream'));
$PAGE->set_heading(get_string('pluginname_logs_page', 'local_intellistream'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('viewlogs', 'local_intellistream'));

// Filter form: three small text inputs + a submit button. Plain HTML keeps the
// dependency surface small and matches the "stock Moodle admin page" look.
echo '<form method="get" action="' . $PAGE->url->out_omit_querystring() . '" class="form-inline mb-3">';
echo '<label class="mr-2">' . get_string('log_filter_type', 'local_intellistream')
    . ' <input type="text" name="type" value="' . s($type) . '" size="10"></label> ';
echo '<label class="mr-2">' . get_string('datatype', 'local_intellistream')
    . ': <input type="text" name="datatype" value="' . s($datatype) . '" size="14"></label> ';
echo '<label class="mr-2">' . get_string('action', 'local_intellistream')
    . ': <input type="text" name="action" value="' . s($action) . '" size="10"></label> ';
echo '<button type="submit" class="btn btn-secondary">' . get_string('filter', 'local_intellistream') . '</button>';
echo '</form>';

$where = [];
$params = [];
if ($type !== '') {
    $where[] = 'type = :type';
    $params['type'] = $type;
}
if ($datatype !== '') {
    $where[] = 'datatype = :datatype';
    $params['datatype'] = $datatype;
}
if ($action !== '') {
    $where[] = 'action = :action';
    $params['action'] = $action;
}
$wheresql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$table = new \flexible_table('local_intellistream_logs');
$table->define_columns(['timecreated', 'type', 'datatype', 'action', 'details']);
$table->define_headers([
    get_string('col_when', 'local_intellistream'),
    get_string('col_ship_or_type', 'local_intellistream'),
    get_string('datatype', 'local_intellistream'),
    get_string('action', 'local_intellistream'),
    get_string('col_details', 'local_intellistream'),
]);
$table->define_baseurl($baseurl);
$table->sortable(false);
$table->collapsible(false);
$table->setup();

$perpage = 100;
$total = (int)$DB->count_records_sql("SELECT COUNT(1) FROM {local_intellistream_logs} {$wheresql}", $params);
$page = optional_param('page', 0, PARAM_INT);
$offset = max(0, $page) * $perpage;

$sql = "SELECT id, timecreated, type, datatype, action, details
          FROM {local_intellistream_logs}
          {$wheresql}
      ORDER BY id DESC";
$rs = $DB->get_recordset_sql($sql, $params, $offset, $perpage);
try {
    foreach ($rs as $row) {
        $table->add_data([
            userdate((int)$row->timecreated),
            s($row->type),
            s($row->datatype),
            s($row->action),
            s($row->details),
        ]);
    }
} finally {
    // Lower stakes than the cron loops — a web request tears the connection
    // down anyway — but kept consistent so every recordset in the plugin
    // closes the same way and a reader does not have to judge which is which.
    $rs->close();
}

$table->finish_output();

echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
