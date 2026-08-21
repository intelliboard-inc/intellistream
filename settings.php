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
 * Admin settings for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_intellistream', get_string('pluginname', 'local_intellistream'));
    $ADMIN->add('localplugins', $settings);

    // Master switch.
    $settings->add(new admin_setting_configcheckbox(
        'local_intellistream/enabled',
        get_string('enabled', 'local_intellistream'),
        get_string('enabled_desc', 'local_intellistream'),
        1
    ));

    // Connection.
    $settings->add(new admin_setting_heading(
        'local_intellistream/settings_connection',
        get_string('settings_connection', 'local_intellistream'),
        ''
    ));
    // IntelliStream pairing: the control plane mints this Site ID; paste it here to pair this Moodle.
    // Read-only to the control webhook — rewriting it remotely would re-point
    // this site's events at another connection.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/siteid',
        get_string('siteid', 'local_intellistream'),
        get_string('siteid_desc', 'local_intellistream'),
        '',
        PARAM_TEXT
    ));
    // The four destination settings below are locally managed. Together they ARE
    // the destination: a holder of the webhook secret who could rewrite them would
    // repoint the whole capture stream at an attacker-controlled bucket, and since
    // the substitute endpoint would be https the allowinsecureendpoint guard never
    // fires. Keeping them local is what makes the marker interface match its own
    // stated purpose — protecting the transport toggles while leaving the
    // destination writable protected nothing.
    //
    // Credential rotation is unaffected: it goes through the webhook's dedicated
    // update_ingest_credentials action, which writes accesskey/secretkey directly
    // and never touches these.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/endpoint',
        get_string('endpoint', 'local_intellistream'),
        get_string('endpoint_desc', 'local_intellistream'),
        '',
        PARAM_URL
    ));
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/bucket',
        get_string('bucket', 'local_intellistream'),
        get_string('bucket_desc', 'local_intellistream'),
        '',
        PARAM_TEXT
    ));
    // Treated as a secret alongside secretkey: masked in the control-plane
    // config snapshot and not writable via set_plugin_config. Credential
    // rotation is unaffected — it goes through the webhook's dedicated
    // update_ingest_credentials action, which writes both keys directly.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_intellistream/accesskey',
        get_string('accesskey', 'local_intellistream'),
        get_string('accesskey_desc', 'local_intellistream'),
        ''
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_intellistream/secretkey',
        get_string('secretkey', 'local_intellistream'),
        get_string('secretkey_desc', 'local_intellistream'),
        ''
    ));
    // Locally managed for the same reason as endpoint/bucket above — see the note
    // there. `prefix` matters as much as the rest: it is the key namespace every
    // object is written under, so rewriting it strands the existing feed.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/region',
        get_string('region', 'local_intellistream'),
        get_string('region_desc', 'local_intellistream'),
        'us-ashburn-1',
        PARAM_TEXT
    ));
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/prefix',
        get_string('prefix', 'local_intellistream'),
        get_string('prefix_desc', 'local_intellistream'),
        'events',
        PARAM_TEXT
    ));
    // S3 transport is routed through Moodle's \curl wrapper. These two opt-ins relax the secure defaults for trusted self-hosted
    // endpoints only; leave both OFF for normal (https, public) endpoints.
    // Both are local-only escape hatches: switching either off weakens the
    // ship path (plaintext transport / no curl-security guard), so neither is
    // writable over the control webhook.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_checkbox(
        'local_intellistream/allowinsecureendpoint',
        get_string('allowinsecureendpoint', 'local_intellistream'),
        get_string('allowinsecureendpoint_desc', 'local_intellistream'),
        0
    ));
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_checkbox(
        'local_intellistream/s3ignorecurlsecurity',
        get_string('s3ignorecurlsecurity', 'local_intellistream'),
        get_string('s3ignorecurlsecurity_desc', 'local_intellistream'),
        0
    ));

    // Control webhook: the inbound endpoint the control plane calls to run
    // operations (reset migration/datatype, delete ad-hoc). Paste the secret
    // minted by the control plane for this connection to enable it.
    $settings->add(new admin_setting_heading(
        'local_intellistream/settings_webhook',
        get_string('settings_webhook', 'local_intellistream'),
        get_string('settings_webhook_desc', 'local_intellistream')
    ));
    // Minimum length enforced on save: this one value authenticates the whole
    // inbound command channel, so a hand-typed short string must not become the
    // weakest link. Clearing it stays valid — that is the off switch.
    $settings->add(new \local_intellistream\admin\admin_setting_webhook_secret(
        'local_intellistream/webhook_secret',
        get_string('webhook_secret', 'local_intellistream'),
        get_string('webhook_secret_desc', 'local_intellistream'),
        ''
    ));
    // Not writable over the webhook: the control plane must not be able to
    // downgrade the transport of the channel it is itself talking over.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_checkbox(
        'local_intellistream/webhook_require_https',
        get_string('webhook_require_https', 'local_intellistream'),
        get_string('webhook_require_https_desc', 'local_intellistream'),
        1
    ));

    // Capture and buffer behaviour.
    $settings->add(new admin_setting_heading(
        'local_intellistream/settings_behaviour',
        get_string('settings_behaviour', 'local_intellistream'),
        ''
    ));
    // Validated on save + not writable over the control webhook: the uninstall
    // purge acts on this path (see \local_intellistream\admin\admin_setting_bufferdir).
    $settings->add(new \local_intellistream\admin\admin_setting_bufferdir(
        'local_intellistream/bufferdir',
        get_string('bufferdir', 'local_intellistream'),
        get_string('bufferdir_desc', 'local_intellistream'),
        \local_intellistream\config::default_buffer_dir(),
        PARAM_TEXT
    ));
    // Locally managed for the same reason as loadgatefactor below: only a CLOSED
    // file is shippable, so a rotation threshold set absurdly high means the active
    // file never closes, nothing is ever shipped, and the buffer grows to its cap
    // as one file — the same halted-drain end state, reached a different way.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/rotatesizemb',
        get_string('rotatesizemb', 'local_intellistream'),
        get_string('rotatesizemb_desc', 'local_intellistream'),
        '64',
        PARAM_INT
    ));
    // Locally managed: the age half of the same rotation gate. See rotatesizemb.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/rotateagesec',
        get_string('rotateagesec', 'local_intellistream'),
        get_string('rotateagesec_desc', 'local_intellistream'),
        // Matches config::rotate_age_sec()'s documented default; the two
        // disagreed (120 vs 3600), so a fresh install got the slow value.
        '120',
        PARAM_INT
    ));
    // Locally managed: with dwellmaxperminute below, this is the entire defence
    // against buffer flooding. Raise it arbitrarily and enforce_disk_cap() never
    // fires. A compromised or buggy control plane could otherwise switch both off
    // remotely with no local trace, which is exactly the class of change the marker
    // interface exists to keep local.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/maxbuffergb',
        get_string('maxbuffergb', 'local_intellistream'),
        get_string('maxbuffergb_desc', 'local_intellistream'),
        '5',
        PARAM_INT
    ));
    // Locally managed, on the same principle as maxbuffergb: ANYTHING that can stop
    // the buffer draining must not be settable from off-site. health::ship_allowed()
    // skips shipping while the 1-minute load average exceeds cores times this
    // factor, so a near-zero value closes the gate permanently — the buffer then
    // grows to maxbuffergb, capture stops site-wide, and on a push-configured site
    // enforce_disk_cap() begins deleting the oldest un-shipped files. A remote
    // kill switch with data loss as its end state.
    //
    // It is also inherently a LOCAL property: it describes this host's capacity,
    // which the control plane has no way to know.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/loadgatefactor',
        get_string('loadgatefactor', 'local_intellistream'),
        get_string('loadgatefactor_desc', 'local_intellistream'),
        '0.7',
        PARAM_FLOAT
    ));
    // Exception-capture quota. Belongs with the buffer bounds above rather than
    // under a feature heading: the exceptions observer fires on
    // \core\event\user_login_failed, so it is the ONE capture path reachable with
    // no session, no account and no sesskey, and it was the one with no ceiling.
    // Locally managed for the same reason as maxbuffergb — 0 disables the limit,
    // so a compromised or buggy control plane must not be able to set it.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/exceptionmaxperminute',
        get_string('exceptionmaxperminute', 'local_intellistream'),
        get_string('exceptionmaxperminute_desc', 'local_intellistream'),
        '30',
        PARAM_INT
    ));

    // Page-dwell / time-on-task capture.
    $settings->add(new admin_setting_heading(
        'local_intellistream/settings_dwell',
        get_string('settings_dwell', 'local_intellistream'),
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_intellistream/dwellenabled',
        get_string('dwellenabled', 'local_intellistream'),
        get_string('dwellenabled_desc', 'local_intellistream'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'local_intellistream/dwellmaxms',
        get_string('dwellmaxms', 'local_intellistream'),
        get_string('dwellmaxms_desc', 'local_intellistream'),
        '14400000',
        PARAM_INT
    ));
    // Per-user beacon quota: dwell.php is reachable by any authenticated non-guest
    // user, so without a ceiling one user can grow the shared buffer until the disk
    // cap evicts other users' unshipped events.
    // Locally managed for the same reason as maxbuffergb: set to 0 and
    // dwell_quota::allow() returns true unconditionally by design, removing the
    // per-user beacon quota entirely.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/dwellmaxperminute',
        get_string('dwellmaxperminute', 'local_intellistream'),
        get_string('dwellmaxperminute_desc', 'local_intellistream'),
        '60',
        PARAM_INT
    ));

    // P3b: capture toggles (trackadmin, trackmedia).
    $settings->add(new admin_setting_configcheckbox(
        'local_intellistream/trackadmin',
        get_string('trackadmin', 'local_intellistream'),
        get_string('trackadmin_desc', 'local_intellistream'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_intellistream/trackmedia',
        get_string('trackmedia', 'local_intellistream'),
        get_string('trackmedia_desc', 'local_intellistream'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_intellistream/trackmediabucketsec',
        get_string('trackmediabucketsec', 'local_intellistream'),
        get_string('trackmediabucketsec_desc', 'local_intellistream'),
        '30',
        PARAM_INT
    ));

    // Bulk entity export / backfill.
    $settings->add(new admin_setting_heading(
        'local_intellistream/settings_export',
        get_string('settings_export', 'local_intellistream'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_intellistream/exportbatchsize',
        get_string('exportbatchsize', 'local_intellistream'),
        get_string('exportbatchsize_desc', 'local_intellistream'),
        '500',
        PARAM_INT
    ));

    // Private-message bodies. OFF by default: the messaging entities ship metadata
    // only, and exporter::strip_forbidden_columns() removes the body columns unless
    // this is on. Deliberately a locally-managed checkbox, so
    // the decision to send message content off-site cannot be made remotely by the
    // control plane — it has to be taken by a site administrator, on this page.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_checkbox(
        'local_intellistream/exportmessagebodies',
        get_string('exportmessagebodies', 'local_intellistream'),
        get_string('exportmessagebodies_desc', 'local_intellistream'),
        0
    ));

    // Dynamic-table discovery (InForm parity). Plugin-family table-name
    // prefixes whose live tables are auto-registered as InForm dynamic-table
    // CANDIDATES — the push-native equivalent of intellidata's
    // get_dbschema_custom auto-discovery. One prefix per line; clearing this
    // disables discovery. Discovery only creates candidates: an admin still
    // enables dynamic tables on the connection and activates each table.
    // A prefix must itself start with `local_`, so it can never match a core table
    // and get that table registered for whole-row export. The binding check is
    // dynamic_discovery_service::prefixes(), which re-applies the rule on every read
    // and drops anything that fails; the check in this form
    // (\local_intellistream\admin\admin_setting_discovery_prefixes) is a convenience
    // on top, because admin_setting::validate() is not called when the value is
    // written by set_plugin_config over the control webhook.
    $settings->add(new \local_intellistream\admin\admin_setting_discovery_prefixes(
        'local_intellistream/dynamic_discovery_prefixes',
        get_string('dynamic_discovery_prefixes', 'local_intellistream'),
        get_string('dynamic_discovery_prefixes_desc', 'local_intellistream'),
        'local_intellicart_',
        PARAM_RAW
    ));

    // Intellidata parity: encryption (A3).
    // Payload encryption (parity with intellidata's encryption_service).
    //
    // This encrypts the OUTGOING batch only — shipper::run() calls encrypt() on
    // the in-memory batch just before the gzip and the PUT. It does not encrypt
    // the buffer files on disk; buffer::append() writes plaintext either way. The
    // heading and help text used to say "at rest", which was wrong in a way that
    // mattered: an admin enabling it to cover the staging area named in the
    // plugin's privacy metadata got in-transit protection instead, on top of TLS.
    $settings->add(new admin_setting_heading(
        'local_intellistream/settings_encryption',
        get_string('settings_encryption', 'local_intellistream'),
        get_string('settings_encryption_desc', 'local_intellistream')
    ));
    // Not writable over the webhook: turning encryption off remotely would
    // silently downgrade every subsequent payload.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_checkbox(
        'local_intellistream/encryption_enabled',
        get_string('encryption_enabled', 'local_intellistream'),
        get_string('encryption_enabled_desc', 'local_intellistream'),
        0
    ));
    // Mask the key in admin pages and in config diffs (admin_setting_configpasswordunmask
    // renders an unmask toggle but stores the value plain; we use it for parity
    // with intellidata's settings UI). The key is auto-generated by
    // encryption_service::ensure_key() on the first ship that actually encrypts —
    // a write path, never a read, so a web-service call can no longer mint one as
    // a side effect. Leaving this field empty is the
    // expected state on a fresh install; the plugin fills it in on first use.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_intellistream/encryption_key',
        get_string('encryption_key', 'local_intellistream'),
        get_string('encryption_key_desc', 'local_intellistream'),
        ''
    ));

    // Blackboard Collaborate API (optional; Part B attendance pull).
    // One-time, OPTIONAL, per-site: the customer's own Blackboard Collaborate
    // REST API credentials. When all three are set, the collab_sync task pulls
    // per-user session attendance from the Blackboard cloud into
    // local_intellistream_colpart. Absent -> the task no-ops (skipped).
    $settings->add(new admin_setting_heading(
        'local_intellistream/settings_collab',
        get_string('settings_collab', 'local_intellistream'),
        get_string('settings_collab_desc', 'local_intellistream')
    ));
    $settings->add(new admin_setting_configtext(
        'local_intellistream/collab_api_endpoint',
        get_string('collab_api_endpoint', 'local_intellistream'),
        get_string('collab_api_endpoint_desc', 'local_intellistream'),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configtext(
        'local_intellistream/collab_consumer_key',
        get_string('collab_consumer_key', 'local_intellistream'),
        get_string('collab_consumer_key_desc', 'local_intellistream'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_intellistream/collab_secret',
        get_string('collab_secret', 'local_intellistream'),
        get_string('collab_secret_desc', 'local_intellistream'),
        ''
    ));

    // IntelliBoard Pro LTI launch.
    $settings->add(new admin_setting_heading(
        'local_intellistream/settings_lti',
        get_string('settings_lti', 'local_intellistream'),
        get_string('settings_lti_desc', 'local_intellistream')
    ));
    // Not writable over the webhook. lti_service::lti_request_params() sends the
    // launching user's email, username and name to whatever `ltitoolurl` names,
    // so a holder of the webhook secret who could rewrite it would harvest
    // identity attributes for every user who opens the dashboard. Masking
    // `ltisharedsecret` does not help, because the attacker controls the
    // receiving endpoint and never needs to verify the signature. Same reasoning
    // that already locks the S3 destination.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/ltitoolurl',
        get_string('ltitoolurl', 'local_intellistream'),
        get_string('ltitoolurl_desc', 'local_intellistream'),
        '',
        PARAM_URL
    ));
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_text(
        'local_intellistream/lticonsumerkey',
        get_string('lticonsumerkey', 'local_intellistream'),
        get_string('lticonsumerkey_desc', 'local_intellistream'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_intellistream/ltisharedsecret',
        get_string('ltisharedsecret', 'local_intellistream'),
        get_string('ltisharedsecret_desc', 'local_intellistream'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_intellistream/ltititle',
        get_string('ltititle', 'local_intellistream'),
        get_string('ltititle_desc', 'local_intellistream'),
        '',
        PARAM_TEXT
    ));
    // Not writable over the webhook: it changes site-wide navigation chrome for
    // every user of the site, which is a local administrative decision. Reading is
    // unaffected — the value still appears in the config snapshot.
    $settings->add(new \local_intellistream\admin\admin_setting_localonly_checkbox(
        'local_intellistream/custommenuitem',
        get_string('custommenuitem', 'local_intellistream'),
        get_string('custommenuitem_desc', 'local_intellistream'),
        0
    ));
    $settings->add(new admin_setting_configselect(
        'local_intellistream/ltipagelayout',
        get_string('ltipagelayout', 'local_intellistream'),
        get_string('ltipagelayout_desc', 'local_intellistream'),
        'base',
        ['base' => 'base', 'standard' => 'standard', 'admin' => 'admin', 'report' => 'report']
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_intellistream/ltidebug',
        get_string('ltidebug', 'local_intellistream'),
        get_string('ltidebug_desc', 'local_intellistream'),
        0
    ));

    // Role-assignment (control-plane "Set LTI role"): the Moodle role that
    // carries viewlti. The connection's role mapping + Enable Learner/Teacher
    // LTI toggles are the source of truth on the control plane; they are
    // delivered here over the signed control webhook (set_lti_role action).
    // Not writable over the control webhook: the option list below is the only
    // thing restricting this to roles that carry viewlti, and the webhook path
    // cannot apply it (see \local_intellistream\admin\admin_setting_ltirole).
    $settings->add(new \local_intellistream\admin\admin_setting_ltirole(
        'local_intellistream/ibnltirole',
        get_string('ibnltirole', 'local_intellistream'),
        get_string('ibnltirole_desc', 'local_intellistream'),
        '',
        \local_intellistream\helpers\lti_roles_helper::options()
    ));
    // NOTE: the former `ltiassigndefaultmethod` switch is gone. It
    // chose between Moodle's role_assign() and a silent bulk INSERT that fired no
    // events, and the bulk path was the default. Role assignment now always goes
    // through role_assign()/role_unassign(), so there is no second method to
    // choose. db/upgrade.php clears the stored value.

    // Link to the status page.
    $settings->add(new admin_setting_description(
        'local_intellistream/statuslink',
        get_string('statuspage', 'local_intellistream'),
        \html_writer::link(
            new \moodle_url('/local/intellistream/status.php'),
            get_string('statuspage', 'local_intellistream')
        )
    ));

    // Intellidata-parity admin pages: live under the same Local plugins parent
    // node as the settings page above, so a customer who knows the intellidata
    // UX finds them in the same place.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_intellistream_datatypes',
        get_string('pluginname_datatypes_page', 'local_intellistream'),
        new \moodle_url('/local/intellistream/config/index.php'),
        'local/intellistream:manage'
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_intellistream_logs',
        get_string('pluginname_logs_page', 'local_intellistream'),
        new \moodle_url('/local/intellistream/logs.php'),
        'local/intellistream:viewlogs'
    ));
    // Targeted re-fetch: a form page (date/time window + entity
    // multi-select + Run) that queues a one-off re-fetch. Replaces the earlier
    // settings-fields + scheduled-task-"Run now" flow.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_intellistream_refetch',
        get_string('pluginname_refetch_page', 'local_intellistream'),
        new \moodle_url('/local/intellistream/refetch.php'),
        'local/intellistream:manage'
    ));
}
