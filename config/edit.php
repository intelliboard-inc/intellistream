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
 * Admin page: edit one datatype's override row.
 *
 * Loads the existing override row (if any) and the matching built-in registry
 * entry, presents the local_intellistream_edit_config form, and persists changes
 * through the repository. Logs every save through log_service for the
 * operational audit trail.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
// For admin_externalpage_setup(), matching config/index.php + logs.php.
require_once($CFG->libdir . "/adminlib.php");

use local_intellistream\exporter;
use local_intellistream\helpers\settings_helper;
use local_intellistream\output\forms\local_intellistream_edit_config;
use local_intellistream\repositories\config_repository;
use local_intellistream\services\log_service;

$datatype = required_param('datatype', PARAM_ALPHANUMEXT);

require_login();

$context = \context_system::instance();
require_capability(settings_helper::CAP_MANAGE, $context);

admin_externalpage_setup('local_intellistream_datatypes');

$returnurl = new \moodle_url('/local/intellistream/config/index.php');
$pageurl = new \moodle_url('/local/intellistream/config/edit.php', ['datatype' => $datatype]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);

$registry = exporter::registry();
$registryentry = $registry[$datatype] ?? null;
$tablename = $registryentry['table'] ?? '';

$repo = new config_repository();
$record = $repo->get($datatype);

// Seed form data: either the persisted row or sane defaults.
$formdata = (object)[
    'datatype'       => $datatype,
    'enabled'        => $record ? (int)$record->enabled : 1,
    'custom_columns' => $record->custom_columns ?? '',
    'notes'          => $record->notes ?? '',
];

$form = new local_intellistream_edit_config($pageurl->out(false), [
    'data'     => $formdata,
    'datatype' => $datatype,
    'table'    => $tablename,
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    $tosave = (object)[
        'enabled'        => !empty($data->enabled) ? 1 : 0,
        'custom_columns' => isset($data->custom_columns) ? (string)$data->custom_columns : '',
        'custom_table'   => $record->custom_table ?? '',
        'notes'          => isset($data->notes) ? (string)$data->notes : '',
    ];
    $repo->save($datatype, $tosave);
    log_service::record(
        'config',
        $datatype,
        'save',
        'enabled=' . ((int)$tosave->enabled) . ' custom_columns_len=' . strlen($tosave->custom_columns)
    );
    redirect($returnurl, get_string('confirm_save', 'local_intellistream'));
}

$title = get_string('datatype', 'local_intellistream') . ': ' . $datatype;
$PAGE->set_title($title);
$PAGE->set_heading($title);

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->footer();
