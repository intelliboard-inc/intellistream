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
 * Class lti
 *
 * @see    http://intelliboard.net/
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_intellistream\config;

$context = context_system::instance();
$title = config::lti_title();
$datasetid = optional_param('data_set_id', 0, PARAM_INT);

$PAGE->set_url(new moodle_url("/local/intellistream/lti.php", ['data_set_id' => $datasetid]));
$PAGE->set_pagetype('home');
$PAGE->set_context($context);
$PAGE->set_pagelayout(config::lti_page_layout());
$PAGE->set_title($title);
$PAGE->set_heading($title);

require_login();
require_capability('local/intellistream:viewlti', $context);

$PAGE->requires->js_call_amd('local_intellistream/lti', 'init');
$renderer = $PAGE->get_renderer("local_intellistream");

// Print the page header.
echo $OUTPUT->header();

echo $renderer->render(new \local_intellistream\output\lti_view(['datasetid' => $datasetid]));

// Finish the page.
echo $OUTPUT->footer();
