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
 * Operational status page for local_intellistream (admin-only).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/intellistream:viewstatus', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/intellistream/status.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('statuspage', 'local_intellistream'));
$PAGE->set_heading(get_string('statuspage', 'local_intellistream'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('statuspage', 'local_intellistream'));

// Gather the real shipper status written by shipper::set_status().
$cfg = get_config('local_intellistream');

$shipstate  = isset($cfg->ship_state) ? (string)$cfg->ship_state : 'unknown';
// The ship_detail value is persisted by cron and rendered here, so the shipper stores a lang
// key + parameters rather than a rendered sentence; this resolves it in the VIEWER's
// language. A value that is not that shape (an s3_client transport message, or one
// stored by an older build) comes back verbatim.
$shipdetail = \local_intellistream\shipper::format_status_detail(
    isset($cfg->ship_detail) ? (string)$cfg->ship_detail : ''
);
$shiptime   = isset($cfg->ship_time) ? (int)$cfg->ship_time : 0;
$lastshipok = isset($cfg->last_ship_ok) ? (int)$cfg->last_ship_ok : 0;
$lastdroptime  = isset($cfg->last_drop_time) ? (int)$cfg->last_drop_time : 0;
$lastdropcount = isset($cfg->last_drop_count) ? (int)$cfg->last_drop_count : 0;
$lastdropbytes = isset($cfg->last_drop_bytes) ? (int)$cfg->last_drop_bytes : 0;

$fmttime = function (int $ts): string {
    return $ts > 0 ? userdate($ts) : get_string('status_never', 'local_intellistream');
};

// A capture/ship pipeline is healthy when the last run succeeded and nothing has
// been dropped. Both the banner colour and the sentence come from the SAME
// classification (see shipper::status_class()) so they cannot contradict each
// other — which they previously did, showing a green banner over "Attention
// needed" for every real shipping failure.
$statusclass = \local_intellistream\shipper::status_class($shipstate, $lastdropcount);

if ($statusclass === 'problem') {
    $notifylevel = \core\output\notification::NOTIFY_ERROR;
    $summary = get_string('status_unhealthy', 'local_intellistream');
} else if ($statusclass === 'norun') {
    $notifylevel = \core\output\notification::NOTIFY_INFO;
    $summary = get_string('status_norunyet', 'local_intellistream');
} else {
    $notifylevel = \core\output\notification::NOTIFY_SUCCESS;
    $summary = get_string('status_healthy', 'local_intellistream');
}
echo $OUTPUT->notification($summary, $notifylevel);

// Buffer directory inspection.
$bufferdir = \local_intellistream\config::buffer_dir();
$buffercount = 0;
$bufferbytes = 0;
$activecount = 0;
$pulledcount = 0;
if (is_dir($bufferdir)) {
    foreach (\local_intellistream\buffer::safe_files($bufferdir, ['*.jsonl.closed']) as $f) {
        $buffercount++;
        $bufferbytes += (int)@filesize($f);
    }
    foreach (\local_intellistream\buffer::safe_files($bufferdir, ['events-*.jsonl']) as $f) {
        $activecount++;
        $bufferbytes += (int)@filesize($f);
    }
    // Legacy `.pulled` leftovers from the pre-0.9.21 pull web service, which
    // parked drained files under that name and never removed them. Counting them
    // is the point of the fix: this page globbed only `.closed` and `.jsonl`, so
    // it under-reported the directory by exactly the amount that was leaking, and
    // nobody investigated. enforce_disk_cap() now evicts
    // them, so the count trends to zero on its own.
    foreach (\local_intellistream\buffer::safe_files($bufferdir, ['*.jsonl.pulled']) as $f) {
        $pulledcount++;
        $bufferbytes += (int)@filesize($f);
    }
}
// Two keys rather than one with a conditional fragment: the pulled clause is
// admin-facing copy and has to come from the lang pack, and a
// site with no backlog should not read as though it has one.
$buffercounts = $pulledcount > 0
    ? get_string(
        'status_buffercount_value_pulled',
        'local_intellistream',
        ['closed' => $buffercount, 'active' => $activecount, 'pulled' => $pulledcount]
    )
    : get_string(
        'status_buffercount_value',
        'local_intellistream',
        ['closed' => $buffercount, 'active' => $activecount]
    );

// S3 configured?
$s3 = \local_intellistream\s3_client::from_config();
$s3configured = $s3->is_configured();

// Render the status table.
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    get_string('status_field', 'local_intellistream'),
    get_string('status_value', 'local_intellistream'),
];
$table->data = [
    [get_string('status_siteid', 'local_intellistream'),
        html_writer::tag('code', s(\local_intellistream\config::site_id()))],
    [get_string('status_enabled', 'local_intellistream'),
        \local_intellistream\config::enabled()
            ? get_string('yes') : get_string('no')],
    [get_string('status_shipstate', 'local_intellistream'),
        s($shipstate)],
    [get_string('status_shipdetail', 'local_intellistream'),
        $shipdetail !== '' ? s($shipdetail) : '—'],
    [get_string('status_lastrun', 'local_intellistream'),
        $fmttime($shiptime)],
    [get_string('status_lastok', 'local_intellistream'),
        $fmttime($lastshipok)],
    [get_string('status_buffercount', 'local_intellistream'),
        $buffercounts],
    [get_string('status_bufferbytes', 'local_intellistream'),
        display_size($bufferbytes)],
    [get_string('status_s3configured', 'local_intellistream'),
        $s3configured ? get_string('yes') : get_string('no')],
];

// Shown only while a directory the buffer USED to be pointed at still holds
// records. The shipper drains it automatically, so in the normal case this
// appears for a run or two after the buffer is relocated and then goes away. It
// has to be visible for the case where the drain cannot proceed — an unreadable
// directory, or shipping stalled for another reason — because those records are
// captured and undelivered, and the counts above describe the new directory
// only, so nothing else on this page would show them.
$previousdirs = [];
foreach (\local_intellistream\config::previous_tracked_dirs() as $previousdir) {
    $count = \local_intellistream\buffer::entry_count($previousdir);
    if ($count > 0) {
        $previousdirs[] = get_string('status_previousdir_value', 'local_intellistream', [
            'dir'   => s($previousdir),
            'count' => $count,
        ]);
    }
}
if ($previousdirs !== []) {
    $table->data[] = [
        get_string('status_previousdir', 'local_intellistream'),
        implode(html_writer::empty_tag('br'), $previousdirs),
    ];
}

if ($lastdropcount > 0 || $lastdroptime > 0) {
    $table->data[] = [
        get_string('status_lastdrop', 'local_intellistream'),
        get_string('status_dropsummary', 'local_intellistream', (object)[
            'count' => $lastdropcount,
            'bytes' => display_size($lastdropbytes),
            'time'  => $fmttime($lastdroptime),
        ]),
    ];
}

// Shown only while the buffer has actually refused a record. A refusal means
// capture is being dropped on the floor, so it must be visible here and not only
// in cron output — the cap applies backpressure precisely on the paths where the
// shipper returns early and therefore reports nothing.
$capfailtime = (int)\local_intellistream\config::get('last_capfail_time', 0);
if ($capfailtime > 0) {
    $table->data[] = [
        get_string('status_capfail', 'local_intellistream'),
        get_string('status_capfail_value', 'local_intellistream', (object)[
            'bytes' => display_size((int)\local_intellistream\config::get('last_capfail_bytes', 0)),
            'cap'   => display_size(\local_intellistream\config::max_buffer_bytes()),
            'time'  => $fmttime($capfailtime),
        ]),
    ];
}

// Only shown once another node has actually been seen writing here: on the
// single-node install that most sites are, an always-present "web nodes: 1" row
// is noise. When it does appear it explains why buffer files from elsewhere are
// reclaimed on a timer rather than the moment their worker exits.
$foreignhosts = (int)\local_intellistream\config::get('foreign_hosts', 0);
if ($foreignhosts > 0) {
    $table->data[] = [
        get_string('status_foreignhosts', 'local_intellistream'),
        get_string('status_foreignhosts_value', 'local_intellistream', (object)[
            'count' => $foreignhosts,
            'time'  => $fmttime((int)\local_intellistream\config::get('foreign_hosts_time', 0)),
        ]),
    ];
}

// Both rows appear only when something in the buffer directory was not written by
// the normal capture path — a crashed rotation, a partial moodledata restore, or a
// hand-copied file. Neither is reachable in normal operation, and each one used to
// be entirely silent: the file could sit in the buffer counting against the disk cap
// while real unshipped records were evicted to make room for it.
$unreadabletime = (int)\local_intellistream\config::get('last_unreadable_time', 0);
if ($unreadabletime > 0) {
    $table->data[] = [
        get_string('status_unreadable', 'local_intellistream'),
        get_string('status_unreadable_value', 'local_intellistream', (object)[
            'count' => (int)\local_intellistream\config::get('last_unreadable_count', 0),
            'time'  => $fmttime($unreadabletime),
        ]),
    ];
}

// Not a plain file: a symlink, FIFO or directory here is either operator error or
// someone probing, and every read path refuses it. Shown separately from the
// unowned row because the remedy differs and this one warrants an alert.
$unsafe = (int)\local_intellistream\config::get('unsafe_files', 0);
if ($unsafe > 0) {
    $table->data[] = [
        get_string('status_unsafe', 'local_intellistream'),
        get_string('status_unsafe_value', 'local_intellistream', (object)[
            'count' => $unsafe,
            'time'  => $fmttime((int)\local_intellistream\config::get('unsafe_files_time', 0)),
        ]),
    ];
}

$unowned = (int)\local_intellistream\config::get('unowned_files', 0);
if ($unowned > 0) {
    $table->data[] = [
        get_string('status_unowned', 'local_intellistream'),
        get_string('status_unowned_value', 'local_intellistream', (object)[
            'count' => $unowned,
            'time'  => $fmttime((int)\local_intellistream\config::get('unowned_files_time', 0)),
        ]),
    ];
}

echo html_writer::table($table);

// Louder than a table row: an entry that is not a plain file means something with
// write access as the web user put it there.
if ($unsafe > 0) {
    echo $OUTPUT->notification(
        get_string('status_unsafealert', 'local_intellistream'),
        \core\output\notification::NOTIFY_ERROR
    );
}

// A non-zero disk-cap drop is data loss — call it out explicitly.
if ($lastdropcount > 0) {
    echo $OUTPUT->notification(
        get_string('status_dropalert', 'local_intellistream'),
        \core\output\notification::NOTIFY_ERROR
    );
}

echo $OUTPUT->footer();
