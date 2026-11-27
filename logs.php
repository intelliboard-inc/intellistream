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
 * Lists rows from local_intellistream_logs, newest first, with optional filters
 * for type, datatype and action.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_intellistream\services\log_service;

// Filters come from the URL. Type and action must be values the plugin writes.
$type = optional_param('type', '', PARAM_ALPHANUMEXT);
$datatype = optional_param('datatype', '', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if (!in_array($type, log_service::types(), true)) {
    $type = '';
}
if (!in_array($action, log_service::actions(), true)) {
    $action = '';
}
$filters = array_filter(['type' => $type, 'datatype' => $datatype, 'action' => $action], static function ($v) {
    return $v !== '';
});

admin_externalpage_setup('local_intellistream_logs', '', $filters);

$mform = new \local_intellistream\form\log_filter($PAGE->url);
if ($data = $mform->get_data()) {
    // Redirect to a URL that holds only the filter values.
    $chosen = array_filter([
        'type'     => (string)($data->logtype ?? ''),
        'datatype' => (string)($data->logdatatype ?? ''),
        'action'   => (string)($data->logaction ?? ''),
    ], static function ($v) {
        return $v !== '';
    });
    redirect(new \moodle_url('/local/intellistream/logs.php', $chosen));
}
$mform->set_data(['logtype' => $type, 'logdatatype' => $datatype, 'logaction' => $action]);

$where = [];
$params = [];
foreach ($filters as $field => $value) {
    $where[] = $field . ' = :' . $field;
    $params[$field] = $value;
}
$select = implode(' AND ', $where);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('viewlogs', 'local_intellistream'));
$mform->display();

$table = new \flexible_table('local_intellistream_logs');
$table->define_columns(['timecreated', 'type', 'datatype', 'action', 'details']);
$table->define_headers([
    get_string('col_when', 'local_intellistream'),
    get_string('col_type', 'local_intellistream'),
    get_string('datatype', 'local_intellistream'),
    get_string('action', 'local_intellistream'),
    get_string('col_details', 'local_intellistream'),
]);
$table->define_baseurl($PAGE->url);
$table->sortable(false);
$table->collapsible(false);
$table->setup();

$perpage = 100;
$total = $DB->count_records_select('local_intellistream_logs', $select, $params);
$page = optional_param('page', 0, PARAM_INT);
$offset = max(0, $page) * $perpage;

$rs = $DB->get_recordset_select(
    'local_intellistream_logs',
    $select,
    $params,
    'id DESC',
    'id, timecreated, type, datatype, action, details',
    $offset,
    $perpage
);
try {
    foreach ($rs as $row) {
        $table->add_data([
            userdate((int)$row->timecreated),
            s(log_service::type_label((string)$row->type)),
            s($row->datatype),
            s(log_service::action_label((string)$row->action)),
            s($row->details),
        ]);
    }
} finally {
    // The recordset is closed even if a row fails to render.
    $rs->close();
}

$table->finish_output();

echo $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url);

echo $OUTPUT->footer();
