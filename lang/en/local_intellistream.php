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
 * Language strings for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'IntelliStream';

// Capability.
$string['intellistream:viewstatus'] = 'View the IntelliStream status page';

// Scheduled tasks.
$string['task_ship_events'] = 'Ship captured events to object storage';
$string['task_refresh_entities'] = 'Refresh bulk entity snapshots';
$string['task_discover_dynamic_tables'] = 'Discover dynamic (InForm) table candidates';

// Settings — connection.
$string['settings_connection'] = 'Object storage connection';
$string['siteid'] = 'IntelliStream Site ID';
$string['siteid_desc'] = 'The unique Site ID minted by the IntelliStream control plane for this connection. Paste it here to pair this Moodle with your IntelliStream account. Until it is set, captured events are held in the local buffer and nothing is shipped.';
$string['endpoint'] = 'S3 endpoint URL';
$string['endpoint_desc'] = 'S3-compatible endpoint URL. Must be https://.';
$string['bucket'] = 'Bucket name';
$string['bucket_desc'] = 'Target bucket for shipped events.';
$string['accesskey'] = 'Access key';
$string['accesskey_desc'] = 'S3 access key. Write-only credentials scoped to this bucket prefix.';
$string['secretkey'] = 'Secret key';
$string['secretkey_desc'] = 'S3 secret key. Never logged; masked in all output.';
$string['region'] = 'Region';
$string['region_desc'] = 'S3 region (informational; some endpoints require it).';
$string['prefix'] = 'Key prefix';
$string['prefix_desc'] = 'Key prefix inside the bucket.';
$string['allowinsecureendpoint'] = 'Allow insecure (http) endpoint';
$string['allowinsecureendpoint_desc'] = 'Off by default. When off, the endpoint must be https:// (shipped payloads contain personal data). Enable only for a trusted internal endpoint, e.g. self-hosted MinIO reached over http on a private network.';
$string['s3ignorecurlsecurity'] = 'Bypass cURL host security for shipping';
$string['s3ignorecurlsecurity_desc'] = 'Off by default. Enable only if this site sets $CFG->curlsecurityblockedhosts to block private address ranges AND the S3 endpoint is a self-hosted host on such a range that would otherwise be blocked.';

// Settings — buffer + behaviour.
$string['settings_behaviour'] = 'Capture and buffer behaviour';
$string['bufferdir'] = 'Buffer directory';
$string['bufferdir_desc'] = 'Local directory for on-disk event buffer files. Must be writable by the web-server user, within PHP open_basedir, and inside Moodle\'s data directory (moodledata) but not one of Moodle\'s own folders such as filedir, temp or cache. Leave blank to use the default folder under moodledata. On a clustered site this directory is therefore shared by every web server, which is supported: each buffer file records which server wrote it, and a file is only collected by another server once it has been idle long enough to be certain its writer has finished.';
$string['bufferdir_err_traversal'] = 'The buffer directory must not contain "..".';
$string['bufferdir_err_notabsolute'] = 'The buffer directory must be an absolute path.';
$string['bufferdir_err_isdataroot'] = 'The buffer directory must not be Moodle\'s data directory itself. Choose a dedicated folder inside it.';
$string['bufferdir_err_outsidedataroot'] = 'The buffer directory must be inside Moodle\'s data directory (moodledata).';
$string['bufferdir_err_coredir'] = 'The buffer directory must not be one of Moodle\'s own data folders (filedir, trashdir, temp, cache, localcache, sessions, muc, models, lang) or a folder inside one.';
$string['rotatesizemb'] = 'Rotation size (MB)';
$string['rotatesizemb_desc'] = 'Active buffer file rotates when it reaches this size.';
$string['rotateagesec'] = 'Rotation age (seconds)';
$string['rotateagesec_desc'] = 'Active buffer file rotates when it reaches this age.';
$string['maxbuffergb'] = 'Maximum buffer size (GB)';
$string['maxbuffergb_desc'] = 'Total buffer directory cap. What happens at the cap '
    . 'depends on how this site delivers records. When push-to-storage is '
    . 'configured, the oldest closed files are dropped beyond this, with an admin '
    . 'alert. When it is not (a pull-only site, or storage not yet set up), nothing '
    . 'is deleted: capture stops instead and raises the same alert, because deleting '
    . 'records a puller has not collected yet would lose data that is still owed. '
    . 'Either way an alert means the buffer is not draining.';
$string['loadgatefactor'] = 'Load gate factor';
$string['loadgatefactor_desc'] = 'Skip shipping when the 1-minute load average exceeds cores times this factor.';
$string['exceptionmaxperminute'] = 'Maximum exception records per address per minute';
$string['exceptionmaxperminute_desc'] = 'Exception records captured above this rate '
    . 'from a single remote address are discarded, along with anything above ten '
    . 'times this rate site-wide. This path is reached from failed logins, so '
    . 'unlike every other capture path it needs no session, no account and no '
    . 'sesskey; the limit exists so an unauthenticated caller cannot fill the '
    . 'shared buffer and stop all capture. A real site sees a handful of failed '
    . 'logins a minute, so the default of 30 is well clear of legitimate use. '
    . 'Set to 0 to disable the limit.';
$string['enabled'] = 'Enabled';
$string['enabled_desc'] = 'Master switch. When off, no events are captured or shipped.';

// Settings — page-dwell capture.
$string['settings_dwell'] = 'Page-dwell capture';
$string['dwellenabled'] = 'Capture page-dwell time';
$string['dwellenabled_desc'] = 'When on, a small client-side script measures how long each page is visible and beacons the elapsed time to the buffer. Requires the master switch.';
$string['dwellmaxms'] = 'Maximum dwell time (ms)';
$string['dwellmaxms_desc'] = 'Reported dwell times above this are clamped. A tab left open for days is noise, not signal. Default is 14400000 (4 hours).';
$string['dwellmaxperminute'] = 'Maximum dwell beacons per user per minute';
$string['dwellmaxperminute_desc'] = 'Beacons above this rate from a single user are discarded. A normal client sends a handful per minute (one per page hide, plus one per media bucket), so the default of 60 is well clear of legitimate use; it exists so one user cannot fill the shared buffer and cause other users\' unshipped events to be dropped. Set to 0 to disable the limit.';

// Settings — bulk entity export.
$string['settings_export'] = 'Bulk entity export';
$string['exportbatchsize'] = 'Export batch size';
$string['exportbatchsize_desc'] = 'Number of rows the entity exporter reads per chunk during a backfill or scheduled refresh.';
$string['exportmessagebodies'] = 'Export private-message bodies';
$string['exportmessagebodies_desc'] = 'When off (the default), the messaging entities ship metadata only — sender, conversation, subject and timestamps — and the full text of private messages never leaves this site. Turn this on only if your organisation has decided that message content should be included in the IntelliBoard warehouse, for example for communication-content reporting, and your privacy documentation discloses it. Only a site administrator can change this setting here; the IntelliStream control plane cannot enable it remotely.';
$string['dynamic_discovery_prefixes'] = 'Dynamic-table discovery prefixes';
$string['dynamic_discovery_prefixes_desc'] = 'Plugin-family table-name prefixes whose live Moodle tables are auto-registered as InForm dynamic-table candidates (push-native parity with intellidata\'s optional-table discovery). One prefix per line; default <code>local_intellicart_</code>. Clearing this disables discovery. Discovery only creates candidates — an admin still enables dynamic tables on the connection and activates each table. Each prefix must start with <code>local_</code>, so discovery can only ever match local-plugin tables and never a core one.';
$string['dynamic_discovery_prefixes_err'] = 'Prefix "{$a}" must start with <code>local_</code>. Discovery is for local-plugin table families; a broader prefix could match a core Moodle table and widen its export to every column.';

// Targeted re-fetch page.
$string['pluginname_refetch_page'] = 'Targeted re-fetch';
$string['targeted_intro'] = 'Re-pull a chosen date/time range for a chosen set of entities and ship it to '
    . 'object storage — a repair tool for when a specific time window or table\'s data never arrived '
    . 'downstream. Choose the window and entities, then run it. The re-fetch is queued and runs on the next '
    . 'scheduled-task pass (usually within a minute). It is a one-off repair emit: it does not affect the '
    . 'normal incremental / daily capture, and re-running the same window is harmless (records are '
    . 'de-duplicated downstream).';
$string['targeted_from'] = 'Re-fetch from';
$string['targeted_to'] = 'Re-fetch to (inclusive)';
$string['targeted_entities'] = 'Entities to re-fetch';
$string['targeted_entities_desc'] = 'Select one or more entity types to re-fetch for the window above. '
    . 'Leave empty to re-fetch <strong>all</strong> entities. Entities that have no recognised time column '
    . '(lookup / join / config tables) are re-fetched in full — the window cannot narrow them.';
$string['targeted_run'] = 'Run re-fetch now';
$string['targeted_all_entities'] = 'all';
$string['targeted_err_order'] = 'The "to" date must be after the "from" date.';
$string['targeted_err_queued'] = 'A re-fetch is already queued and has not started yet. '
    . 'Wait for it to run before queueing another — each one can re-read and re-send '
    . 'every exported table, so queueing several at once puts avoidable load on the '
    . 'database and the connection to IntelliBoard.';
$string['targeted_queued'] = 'Targeted re-fetch queued: {$a->count} entities, window {$a->from} → {$a->to}. '
    . 'It runs on the next scheduled-task pass (usually within a minute).';

// Status page.
$string['statuspage'] = 'IntelliStream status';
$string['status_field'] = 'Field';
$string['status_value'] = 'Value';
$string['status_never'] = 'never';
$string['status_norunyet'] = 'The shipper task has not run yet. Status will appear after its first run.';
$string['status_healthy'] = 'Capture and shipping are healthy — the last run succeeded and no buffer files have been dropped.';
$string['status_unhealthy'] = 'Attention needed — see the shipper state and disk-cap drop details below.';
$string['status_siteid'] = 'Site ID (IntelliStream tenant identifier)';
$string['status_enabled'] = 'Plugin enabled';
$string['status_shipstate'] = 'Last shipper state';
$string['status_shipdetail'] = 'Last shipper detail';
$string['status_lastrun'] = 'Last shipper run';
$string['status_lastok'] = 'Last successful ship';
$string['status_buffercount'] = 'Buffer files';
$string['status_buffercount_value'] = '{$a->closed} closed, {$a->active} active';
$string['status_buffercount_value_pulled'] = '{$a->closed} closed, {$a->active} active, {$a->pulled} pulled (pending cleanup)';
// Shipper status details. Persisted by cron as a key + parameters and rendered at
// display time, so the message appears in the viewer's language.
$string['ship_detail_unconfigured'] = 'Object storage settings are incomplete.';
$string['ship_detail_unpaired'] = 'Site ID not set — paste the IntelliStream Site ID from the control plane.';
$string['ship_detail_encryption_unsupported'] = 'Stopped: "Encrypt payloads" is on, but the IntelliBoard '
    . 'pipeline cannot decrypt these batches, so shipping them would discard every record in them. '
    . 'Nothing has been lost — records are being held in the local buffer. Turn "Encrypt payloads" off to '
    . 'resume. While this persists the buffer grows toward its cap, after which the oldest unshipped files '
    . 'are dropped.';
$string['ship_detail_shipped'] = 'Shipped {$a->files} file(s) in {$a->objects} object(s), {$a->events} event(s).';
$string['ship_detail_shipped_mismatch'] = 'Shipped {$a->files} file(s) in {$a->objects} object(s), {$a->events} event(s). Dropped {$a->badrecords} record(s) / {$a->badfiles} file(s) with a mismatched Site ID.';
$string['ship_detail_dropping'] = 'Buffer over cap — dropped {$a->files} oldest unshipped file(s), {$a->bytes} byte(s).';
$string['ship_detail_loadgated'] = 'Paused: host load is above the shipping gate. Shipping resumes automatically when load drops. If this persists, the buffer will grow toward its cap and the oldest unshipped files will be dropped.';
$string['status_bufferbytes'] = 'Buffer size on disk';
$string['status_s3configured'] = 'Object storage configured';
$string['status_previousdir'] = 'Previous buffer directory';
$string['status_previousdir_value'] = '{$a->count} file(s) still to collect from {$a->dir}. This is a directory the buffer used to be pointed at. Shipping collects these automatically and this row disappears once the directory is empty; if it persists, check that the directory is readable by the web-server user and that shipping is not otherwise stalled.';
$string['status_lastdrop'] = 'Last disk-cap drop';
$string['status_dropsummary'] = '{$a->count} file(s), {$a->bytes} — {$a->time}';
$string['status_dropalert'] = 'The buffer directory exceeded its size cap and the oldest unshipped buffer file(s) were dropped. Those events are permanently lost. Increase the maximum buffer size, fix object storage shipping, or both.';
$string['status_capfail'] = 'Buffer full — capture refused';
$string['status_capfail_value'] = 'Last refused {$a->time}: the buffer had reached {$a->bytes} against a cap of {$a->cap}. New records are refused until the shipper drains it. Check that object storage is configured and that host load is not holding the ship gate closed.';
$string['status_unreadable'] = 'Unreadable buffer file names';
$string['status_unreadable_value'] = '{$a->count} reclaimed {$a->time}. A file in the buffer directory had a name this plugin could not read, so it was collected on idle time alone and shipped. Its records were not lost, but the cause is worth knowing: a rotation that crashed part-way, a partial restore of moodledata, or a file copied in by hand.';
$string['status_unsafe'] = 'Entries in the buffer directory that are not plain files';
$string['status_unsafe_value'] = '{$a->count} — last seen {$a->time}. The buffer directory contains entr(y/ies) that are not plain, single-linked files. Nothing has been read, written, shipped or deleted through them, and nothing here will remove them. A symbolic link, a FIFO or a directory can only have been created by something with write access as the web server user — treat it as a possible attempt to make this plugin read or overwrite a file elsewhere on the server, and review how it got there. A hard link is more often innocent: a moodledata restore made with cp -al or rsync --link-dest produces them. Either way, remove the entry (replacing a hard link with a real copy) so its records can ship. The names are in the scheduled-task output.';
$string['status_unsafealert'] = 'The buffer directory contains entries that are not plain files. They are being ignored, so no data has been read or written through them, but they should be investigated and removed — see the row above.';
$string['status_unowned'] = 'Files not written by this plugin';
$string['status_unowned_value'] = '{$a->count} — last seen {$a->time}. The buffer directory contains .jsonl file(s) this plugin did not create. They are left untouched and excluded from the buffer disk cap, so they cannot cause unshipped records to be deleted; nothing here will remove them either. Delete them, or move them out of the buffer directory.';
$string['status_foreignhosts'] = 'Other web nodes detected';
$string['status_foreignhosts_value'] = '{$a->count} — last seen {$a->time}. This site shares its buffer directory with other web servers, so their buffer files are collected on a timer instead of as soon as the writing process ends.';

// Privacy.
$string['privacy:metadata'] = 'The IntelliStream plugin captures Moodle event-stream data, page-dwell timings, and bulk entity snapshots, and transmits them to externally configured object storage. Payloads may contain personal data such as user, course, and IP identifiers.';
$string['privacy:metadata:intellistream_events'] = 'Moodle event data captured and shipped to external object storage.';
$string['privacy:metadata:intellistream_events:eventdata'] = 'The full Moodle event payload as produced by the core event API.';
$string['privacy:metadata:intellistream_buffer'] = 'An on-disk staging area inside this site\'s own moodledata directory (not a third party) holding captured records for the short period between capture and transmission — normally about a minute, longer if transmission is backlogged. Data-subject export and erasure requests cover it: a request removes the subject\'s staged records as well as the transmitted copy. Records in a file that is still being written are the one exception, and those are transmitted and deleted within about a minute.';
$string['privacy:metadata:intellistream_buffer:userid'] = 'The id of the user a staged record belongs to.';
$string['privacy:metadata:intellistream_buffer:client_ip'] = 'The IP address a staged record was captured from.';
$string['privacy:metadata:intellistream_buffer:user_agent'] = 'The browser user-agent string a staged record was captured from.';
$string['privacy:metadata:intellistream_buffer:url'] = 'The page address a staged page-dwell record refers to.';
$string['privacy:metadata:intellistream_buffer:eventdata'] = 'The captured Moodle event or entity payload held in a staged record.';
$string['privacy:subcontext:buffer'] = 'Records awaiting transmission';
$string['privacy:metadata:intellistream_dwell'] = 'Page-dwell timings captured and shipped to external object storage.';
$string['privacy:metadata:intellistream_dwell:userid'] = 'The id of the user the page-dwell measurement belongs to.';
$string['privacy:metadata:intellistream_dwell:timespent'] = 'How long the user kept the page visible.';
$string['privacy:metadata:intellistream_dwell:url'] = 'The relative URL of the page the user dwelt on.';
$string['privacy:metadata:intellistream_entities'] = 'Bulk snapshots of Moodle entity rows (users, enrolments, grades, and so on) shipped to external object storage.';
$string['privacy:metadata:intellistream_entities:entitydata'] = 'Selected columns of Moodle core table rows, which may include personal data. The specific data classes below are called out individually so that a data protection officer can see exactly which sensitive categories leave the site.';
$string['privacy:metadata:intellistream_entities:messagemeta'] = 'Private-message METADATA: sender, conversation, subject and timestamps for every message on the site.';
$string['privacy:metadata:intellistream_entities:messagecontent'] = 'Private-message CONTENT (the full text of messages) — exported ONLY when the site administrator has explicitly enabled "Export private-message bodies" (default off).';
$string['privacy:metadata:intellistream_entities:forumposts'] = 'Forum discussion and post content, including the full post text, author and timestamps.';
$string['privacy:metadata:intellistream_entities:chatmessages'] = 'Chat activity message text, author and timestamps.';
$string['privacy:metadata:intellistream_entities:paypaltransactions'] = 'PayPal enrolment transaction records: payer and payee email addresses, PayPal transaction identifiers, payment status and course/user references. No card numbers are stored by Moodle or exported.';
$string['privacy:metadata:intellistream_entities:commercedata'] = 'IntelliCart commerce records: products, orders, seats, vendors and coupon usage counts, linked to purchasing users. Redeemable credentials (seat keys and coupon codes) are never exported.';
$string['privacy:metadata:intellistream_events:client_ip'] = 'The IP address the event was generated from.';
$string['privacy:metadata:intellistream_events:user_agent'] = 'The browser user-agent string the event was generated from.';
$string['privacy:metadata:intellistream_dwell:client_ip'] = 'The IP address the page-dwell measurement was generated from.';
$string['privacy:metadata:intellistream_dwell:user_agent'] = 'The browser user-agent string the page-dwell measurement was generated from.';
// Local table storing Blackboard Collaborate attendance.
$string['privacy:metadata:local_intellistream_colpart'] = 'Blackboard Collaborate session attendance pulled from the Collaborate cloud API and stored on this site.';
$string['privacy:metadata:local_intellistream_colpart:external_user_id'] = 'The Moodle user id the attendance row belongs to.';
$string['privacy:metadata:local_intellistream_colpart:useruid'] = 'The Collaborate user identifier.';
$string['privacy:metadata:local_intellistream_colpart:display_name'] = 'The display name recorded by Collaborate for the attendee.';
$string['privacy:metadata:local_intellistream_colpart:role'] = 'The role the user held in the Collaborate session.';
$string['privacy:metadata:local_intellistream_colpart:sessionuid'] = 'The Collaborate session identifier.';
$string['privacy:metadata:local_intellistream_colpart:first_join_time'] = 'When the user first joined the session.';
$string['privacy:metadata:local_intellistream_colpart:last_left_time'] = 'When the user last left the session.';
$string['privacy:metadata:local_intellistream_colpart:duration'] = 'Total time the user spent in the session.';

// Intellidata-parity: admin pages, capabilities, and operational log.
$string['datatypes'] = 'Datatypes';
$string['datatype'] = 'Datatype';
// The custom_columns / custom_columns_help / custom_table keys were defined
// twice; the later (authoritative) definitions live with custom_table_help
// further down this file.
$string['notes'] = 'Notes';
$string['viewlogs'] = 'Operational logs';
$string['pluginname_datatypes_page'] = 'IntelliStream: datatype config';
$string['pluginname_logs_page'] = 'IntelliStream: operational logs';
$string['confirm_save'] = 'Datatype configuration saved.';
$string['log_filter_type'] = 'Type (ship, export, webhook, …):';
// Previously hard-coded UI strings on the logs + datatype pages.
$string['action'] = 'Action';
$string['filter'] = 'Filter';
$string['col_when'] = 'When';
$string['col_details'] = 'Details';
$string['col_ship_or_type'] = 'ship / type';
$string['col_table'] = 'Table';
$string['status_disabled'] = 'disabled';
$string['label_custom'] = 'custom';

// Capability strings (shown by Moodle on the role-definitions screens).
$string['intellistream:manage'] = 'Manage IntelliStream datatype configuration overrides';
$string['intellistream:viewlogs'] = 'View the IntelliStream operational log feed';

// Datatype display names + descriptions, observer descriptions, and the
// custom_table / custom_columns config-field help.

// Datatype display names.
// Datatype descriptions (for admin UI / settings page).
// Field help texts for the local_intellistream_config admin form.
$string['custom_table'] = 'Custom table';
$string['custom_columns'] = 'Custom columns';
// Observer descriptions (shown on Site administration > Plugins > ... > Events list).
// Web-service capability + function descriptions, and payload-encryption settings.

// Capability strings (Moodle picks these up automatically from the
// `pluginname:capabilityname` lang key).
$string['intellistream:pullexport'] = 'Pull captured IntelliStream records over the REST web service';

// Web-service function descriptions. Moodle renders these on the admin.
// Encryption settings (used by 03_settings_additions.php).
$string['settings_encryption'] = 'Payload encryption (in transit)';
$string['settings_encryption_desc'] = 'When enabled, each batch of records is '
    . 'encrypted in memory immediately before it is gzipped and sent to object '
    . 'storage, and the pull web service returns encrypted blobs through the same '
    . 'code path; the receiver must hold the key to decrypt them. This is '
    . 'protection in transit, on top of the TLS the transfer already uses. '
    . 'It is NOT at-rest protection: buffer files staged on this server hold '
    . 'plain readable records whether this setting is on or off, so use '
    . 'OS-level disk encryption on the host to cover the staging area either '
    . 'way. Default off, and off is the supported setting — see below.';
$string['encryption_enabled'] = 'Encrypt payloads';
$string['encryption_enabled_desc'] = 'Encrypt every outgoing batch with '
    . 'libsodium-AEAD (or an AES-256-CBC fallback), using a 256-bit key '
    . 'auto-generated on first use. <strong>Leave this OFF.</strong> The '
    . 'IntelliBoard ingest pipeline has no decryption step, so an encrypted batch '
    . 'cannot be read on arrival. Turning this on now STOPS shipping rather than '
    . 'sending data nobody can read: records are held in the local buffer and the '
    . 'status page reports why. Nothing is lost, but the buffer will grow toward '
    . 'its cap until you turn this back off. Enable it only once IntelliBoard '
    . 'support confirms the receiving end can decrypt.';
$string['encryption_key'] = 'Encryption key (base64, 32 bytes)';
$string['encryption_key_desc'] = 'Symmetric key used to both encrypt and '
    . 'decrypt payloads. Generated automatically the first time a ship actually '
    . 'encrypts; leave blank to regenerate. '
    . 'Treat as a secret — anyone with this key can decrypt every shipped '
    . 'payload.';

// Scheduled tasks.
$string['task_copy_intelliboard_tracking'] = 'Copy legacy IntelliBoard tracking '
    . '(one-time migration)';
$string['task_historical_backfill'] = 'Historical entity backfill '
    . '(one-time)';
$string['task_daily_snapshot'] = 'Daily full entity snapshot';
$string['task_run_targeted_refetch'] = 'Run IntelliStream targeted re-fetch';

// P3b — capture toggles.
$string['trackadmin'] = 'Track admin user activity';
$string['trackadmin_desc'] = 'When enabled (default), events emitted by '
    . 'site-admin users are captured along with everyone else. Disable to '
    . 'exclude admin activity from analytics so internal test / config '
    . 'clicks do not pollute usage stats.';
$string['trackmedia'] = 'Track media playback';
$string['trackmedia_desc'] = 'When enabled, the dwell tracker attaches '
    . 'listeners to <video> and <audio> elements and emits a '
    . '<code>media_segment</code> record once per bucket while the media '
    . 'is playing. Adds per-segment view time to the warehouse.';
$string['trackmediabucketsec'] = 'Media segment bucket (seconds)';
$string['trackmediabucketsec_desc'] = 'Bucket size in seconds for media-segment '
    . 'emissions (minimum 5). Smaller = finer-grained stats but more '
    . 'beacon traffic. Default 30.';

// Blackboard Collaborate attendance (Part B).
$string['settings_collab'] = 'Blackboard Collaborate API (attendance)';
$string['settings_collab_desc'] = 'Optional. Set the customer\'s own Blackboard '
    . 'Collaborate REST API credentials to pull per-user session attendance '
    . 'into the warehouse. Leave blank if Collaborate is not used — the '
    . 'collab_sync task then does nothing.';
$string['collab_api_endpoint'] = 'Collaborate API endpoint';
$string['collab_api_endpoint_desc'] = 'Base URL of the Blackboard Collaborate REST '
    . 'API (e.g. https://&lt;tenant&gt;.bbcollab.com). No trailing slash.';
$string['collab_consumer_key'] = 'Collaborate consumer key';
$string['collab_consumer_key_desc'] = 'OAuth consumer key issued by Blackboard for '
    . 'this institution\'s Collaborate tenant.';
$string['collab_secret'] = 'Collaborate secret';
$string['collab_secret_desc'] = 'OAuth secret paired with the consumer key '
    . '(used to sign the JWT). Stored masked.';
$string['task_collab_sync'] = 'Pull Blackboard Collaborate attendance';

// IntelliBoard LTI launch.
$string['intellistream:viewlti'] = 'View the IntelliBoard LTI dashboard';
$string['settings_lti'] = 'IntelliBoard Pro LTI launch';
$string['settings_lti_desc'] = 'Embed the IntelliBoard Pro analytics dashboard via an LTI 1.0 launch.';
$string['ltitoolurl'] = 'Tool URL';
$string['ltitoolurl_desc'] = 'The IntelliBoard Pro LTI launch endpoint URL.';
$string['lticonsumerkey'] = 'Consumer key';
$string['lticonsumerkey_desc'] = 'OAuth consumer key issued by IntelliBoard.';
$string['ltisharedsecret'] = 'Shared secret';
$string['ltisharedsecret_desc'] = 'OAuth shared secret issued by IntelliBoard. Masked in all output.';
$string['ltititle'] = 'LTI menu title';
$string['ltititle_desc'] = 'Title shown for the LTI navigation node. Defaults to "Analytics".';
$string['ltimenutitle'] = 'Analytics';
$string['custommenuitem'] = 'Display in custom menu';
$string['custommenuitem_desc'] = 'Also show the dashboard link in the site menu bar, alongside the navigation entry. Only users who are permitted to open the dashboard see it, because the link is added per page request rather than saved: nothing is written to Appearance > Custom menu items, and an entry added there by hand is left alone. The entry does not appear on Site administration pages. If an entry for the dashboard already exists under Appearance > Custom menu items, that one is used instead of adding a second.';
$string['error_lticredentials'] = 'Invalid LTI credentials. Set the Tool URL, consumer key and shared secret in the IntelliStream plugin settings.';
$string['ltipagelayout'] = 'Theme layout';
$string['ltipagelayout_desc'] = 'Moodle page layout used to render the LTI page.';
$string['ltidebug'] = 'Debug mode';
$string['ltidebug_desc'] = 'Show the LTI launch parameters instead of auto-submitting (do not enable in production).';
$string['lti_launch_title'] = 'Opening IntelliBoard';
$string['lti_toggle_debug_data'] = 'Toggle Debug Data';
$string['lti_basiclti_endpoint'] = 'LTI Endpoint';
$string['lti_basiclti_parameters'] = 'LTI Parameters';

// IntelliBoard LTI role assignment.
$string['ibnltirole'] = 'IntelliBoard LTI role';
$string['ibnltirole_desc'] = 'The Moodle role granted to users who may launch the LTI dashboard.';
$string['notselected'] = 'Not selected';
$string['task_set_lti_role'] = 'Assign IntelliBoard LTI role';

// Control webhook (inbound command channel for write operations).
$string['settings_webhook'] = 'Control webhook';
$string['settings_webhook_desc'] = 'Inbound endpoint the IntelliStream control plane calls to run operations on this site (reset migration, reset datatype, delete ad-hoc task). Requests are authenticated by a per-connection shared secret.';
$string['webhook_secret'] = 'Webhook secret';
$string['webhook_secret_desc'] = 'Shared secret minted by the IntelliStream control plane for this connection. Paste it here so this site can verify signed control-plane commands. Leave blank to disable inbound commands.';
$string['webhook_secret_err_short'] = 'The webhook secret must be at least {$a} characters. This value is the only authentication for the inbound command channel, so paste the secret minted by the control plane rather than choosing one by hand. Leave the field blank to disable inbound commands.';
$string['webhook_require_https'] = 'Require HTTPS for the control webhook';
$string['webhook_require_https_desc'] = 'When on (recommended), the control webhook only accepts requests over HTTPS. Turn off only for a co-located or test site served over plain HTTP — never in production.';
$string['task_run_backfill'] = 'Run IntelliStream historical backfill (control-triggered)';

// Privacy (external location for the LTI launch).
$string['privacy:metadata:intellistream_lti'] = 'Identity attributes sent to the IntelliBoard Pro LTI tool when launching the analytics dashboard.';
$string['privacy:metadata:intellistream_exceptions'] = 'Records of failed or rejected '
    . 'operations — failed sign-ins, passwords refused by site policy, and web-service '
    . 'errors — transmitted to IntelliBoard so operational problems can be diagnosed.';
$string['privacy:metadata:intellistream_exceptions:userid'] = 'The id of the account the '
    . 'failed operation related to, where the event names one. A failed sign-in names the '
    . 'account that was attempted.';
$string['privacy:metadata:intellistream_exceptions:url'] = 'The address of the request that '
    . 'failed, with sensitive query parameters removed before it is stored.';
$string['privacy:metadata:intellistream_exceptions:errorclass'] = 'The category of the failure, '
    . 'as a Moodle event or exception class name.';
$string['privacy:metadata:intellistream_exceptions:errormessage'] = 'The failure message.';
$string['privacy:metadata:intellistream_exceptions:stacktrace'] = 'For web-service failures, the '
    . 'sequence of code that produced the error, recorded without any argument values so it '
    . 'cannot carry passwords or tokens. Not recorded for failed sign-ins.';
$string['privacy:metadata:intellistream_lti:userid'] = 'The id of the user launching the tool.';
$string['privacy:metadata:intellistream_lti:email'] = 'The email address of the user launching the tool.';
$string['privacy:metadata:intellistream_lti:firstname'] = 'The first name of the user launching the tool.';
$string['privacy:metadata:intellistream_lti:lastname'] = 'The last name of the user launching the tool.';
$string['privacy:metadata:intellistream_lti:fullname'] = 'The full name of the user launching the tool.';
$string['privacy:metadata:intellistream_lti:username'] = 'The username of the user launching the tool.';
