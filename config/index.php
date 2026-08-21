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
 * Admin page: list every registered datatype with its override state.
 *
 * Wired into the admin tree via settings.php as the external page
 * `local_intellistream_datatypes`. Lists the static exporter registry, joined
 * against `local_intellistream_config` so an admin can see at a glance which
 * datatypes have overrides and click through to edit each.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . "/adminlib.php");

use local_intellistream\exporter;
use local_intellistream\repositories\config_repository;
use local_intellistream\helpers\settings_helper;

require_login();

$context = \context_system::instance();
require_capability(settings_helper::CAP_MANAGE, $context);

admin_externalpage_setup('local_intellistream_datatypes');

$pageurl = new \moodle_url('/local/intellistream/config/index.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname_datatypes_page', 'local_intellistream'));
$PAGE->set_heading(get_string('pluginname_datatypes_page', 'local_intellistream'));

$registry = exporter::registry();
$repo = new config_repository();
$overrides = $repo->get_all();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('datatypes', 'local_intellistream'));

$table = new \html_table();
$table->head = [
    get_string('datatype', 'local_intellistream'),
    get_string('col_table', 'local_intellistream'),
    get_string('enabled', 'local_intellistream'),
    get_string('custom_columns', 'local_intellistream'),
    get_string('notes', 'local_intellistream'),
    '',
];
$table->attributes['class'] = 'generaltable local_intellistream_datatypes';

ksort($registry);
foreach ($registry as $datatype => $entry) {
    $override = $overrides[$datatype] ?? null;

    $enabled = $override ? ((int)$override->enabled !== 0) : true;
    $enabledcell = $enabled
        ? \html_writer::tag('span', get_string('enabled', 'local_intellistream'), ['class' => 'badge badge-success'])
        : \html_writer::tag('span', get_string('status_disabled', 'local_intellistream'), ['class' => 'badge badge-secondary']);

    $customcols = $override && !empty($override->custom_columns)
        ? format_string(shorten_text((string)$override->custom_columns, 60))
        : '—';

    $notes = $override && !empty($override->notes)
        ? format_string(shorten_text((string)$override->notes, 60))
        : '';

    $editurl = new \moodle_url('/local/intellistream/config/edit.php', ['datatype' => $datatype]);
    $editlink = \html_writer::link($editurl, get_string('edit'));

    // Cells are escaped here: html_writer::table() does not escape content, and the badge/link cells
    // below are already markup, so the plain values are escaped here rather than
    // relying on the caller.
    $table->data[] = [
        s($datatype),
        s((string)($entry['table'] ?? '')),
        $enabledcell,
        $customcols,
        $notes,
        $editlink,
    ];
}

// Also surface any admin-added "custom_table" datatypes that aren't in the
// built-in registry — they live only in `local_intellistream_config`.
foreach ($overrides as $datatype => $rec) {
    if (isset($registry[$datatype])) {
        continue;
    }
    $editurl = new \moodle_url('/local/intellistream/config/edit.php', ['datatype' => $datatype]);
    $editlink = \html_writer::link($editurl, get_string('edit'));
    $table->data[] = [
        s($datatype) . ' (' . get_string('label_custom', 'local_intellistream') . ')',
        s((string)($rec->custom_table ?? '')),
        ((int)$rec->enabled !== 0)
            ? \html_writer::tag('span', get_string('enabled', 'local_intellistream'), ['class' => 'badge badge-success'])
            : \html_writer::tag('span', get_string('status_disabled', 'local_intellistream'), ['class' => 'badge badge-secondary']),
        format_string(shorten_text((string)($rec->custom_columns ?? ''), 60)),
        format_string(shorten_text((string)($rec->notes ?? ''), 60)),
        $editlink,
    ];
}

echo \html_writer::table($table);

echo $OUTPUT->footer();
