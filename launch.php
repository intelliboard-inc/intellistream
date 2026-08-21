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


$context = context_system::instance();
$datasetid = optional_param('data_set_id', 0, PARAM_INT);

require_login();
require_capability('local/intellistream:viewlti', $context);

$PAGE->set_context($context);

$ltiservice = new \local_intellistream\services\lti_service();
[$endpoint, $parms, $debug] = $ltiservice->lti_get_launch_data(['custom_data_set_id' => $datasetid]);

$renderer = $PAGE->get_renderer("local_intellistream");

// Emit a minimal but VALID document. The template is a bare <form> plus an
// auto-submitting script, so this page previously had no doctype at all and the
// browser rendered the LTI relay in quirks mode.
//
// Deliberately not $OUTPUT->header()/footer(): this document exists only to POST
// itself to the tool provider in an iframe and is never seen. A full theme header
// would pull in the navigation, the theme CSS and Moodle's JS bootstrap ahead of
// the auto-submit, which is both wasted work and a needless risk to a relay that
// has to fire immediately. A doctype, a charset and a title are what it was
// missing; noindex because a self-posting relay should never be indexed.
echo '<!DOCTYPE html>' . "\n";
echo '<html lang="' . s(current_language()) . '">' . "\n";
echo '<head>' . "\n";
echo '<meta charset="utf-8">' . "\n";
echo '<meta name="robots" content="noindex, nofollow">' . "\n";
echo '<title>' . s(get_string('lti_launch_title', 'local_intellistream')) . '</title>' . "\n";
echo '</head>' . "\n";
echo '<body>' . "\n";
echo $renderer->render(new \local_intellistream\output\lti_launch($parms, $endpoint, $debug));
echo "\n" . '</body>' . "\n";
echo '</html>' . "\n";
