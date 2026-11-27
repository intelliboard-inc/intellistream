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
 * Opens the IntelliBoard dashboard: a signed LTI launch form, submitted by the
 * local_intellistream/launch AMD module.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
$datasetid = optional_param('data_set_id', 0, PARAM_INT);
$PAGE->set_url(new moodle_url('/local/intellistream/launch.php', ['data_set_id' => $datasetid]));

$PAGE->set_context($context);

require_login();
require_capability('local/intellistream:viewlti', $context);

$PAGE->set_pagelayout('embedded');
$PAGE->set_pagetype('local-intellistream-launch');
$PAGE->set_cacheable(false);
$PAGE->set_title(get_string('lti_launch_title', 'local_intellistream'));
$PAGE->requires->js_call_amd('local_intellistream/launch', 'init');

$ltiservice = new \local_intellistream\services\lti_service();
[$endpoint, $parms, $debug] = $ltiservice->lti_get_launch_data(['custom_data_set_id' => $datasetid]);

$renderer = $PAGE->get_renderer('local_intellistream');

// The page runs inside the dashboard frame of lti.php, so it uses the embedded
// layout. It is not cacheable: the signed launch data is valid once.
echo $OUTPUT->header();
echo $renderer->render(new \local_intellistream\output\lti_launch($parms, $endpoint, $debug));
echo $OUTPUT->footer();
