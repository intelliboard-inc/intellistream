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
 * LTI 1.1 launch to the IntelliBoard dashboard.
 *
 * @package    local_intellistream
 * @copyright  2021 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\services;

use local_intellistream\config;
// Use Moodle core's bundled OAuth 1.0 library (mod/lti) instead
// of a private re-headered copy. The classes are require_once'd in
// lti_sign_parameters() because core's OAuth.php is not autoloaded.
use moodle\mod\lti\OAuthConsumer;
use moodle\mod\lti\OAuthRequest;
use moodle\mod\lti\OAuthSignatureMethod_HMAC_SHA1;

/**
 * Builds and signs the LTI launch to the IntelliBoard dashboard, and assigns the
 * LTI role the IntelliBoard service asks for.
 */
class lti_service {
    /** @var mixed LTI endpoint */
    private $endpoint;

    /** @var mixed LTI consumer key */
    private $key;

    /** @var mixed LTI shared secret */
    private $secret;

    /** @var bool LTI debug mode */
    private $debug;

    /**
     * Set endpoint for LTI
     *
     * @param string $endpoint
     */
    public function set_endpoint($endpoint) {
        $this->endpoint = $endpoint;
    }

    /**
     * lti_service constructor.
     * @throws \dml_exception
     */
    public function __construct() {
        $this->endpoint = config::lti_tool_url();
        $this->key = config::lti_consumer_key();
        $this->secret = config::lti_shared_secret();
        // Debug output is for site administrators only; other users get the normal launch.
        $this->debug = config::lti_debug() && is_siteadmin();
    }

    /**
     * Get signed parameters for LTI request
     *
     * @param array $customparameters [param_key => param_value]
     * @return array
     */
    private function lti_request_params($customparameters) {
        global $USER;

        $requestparams = [
            'user_id' => $USER->id,
            'lis_person_contact_email_primary' => $USER->email,
            'lis_person_name_given' => $USER->firstname,
            'lis_person_name_family' => $USER->lastname,
            'lis_person_name_full' => fullname($USER),
            'ext_user_username' => $USER->username,
            'lti_message_type' => 'basic-lti-launch-request',
            'lti_version' => 'LTI-1p0',
            'resource_link_id' => 0,
        ];

        $requestparams = array_merge($requestparams, $customparameters);

        return $this->lti_sign_parameters($requestparams);
    }

    /**
     * Get Lti role.
     *
     * The configured role must still carry local/intellistream:viewlti. The
     * settings dropdown restricts the choice, but only in the admin UI, so it is
     * re-checked here where the role is assigned. Otherwise a role id stored by
     * another route (a direct DB write, or a role that has since lost the
     * capability) would be assigned to every user in the set. Validating against
     * lti_roles_helper::options(), which also builds the dropdown, keeps the two
     * in step.
     *
     * @return \stdClass|false The role record, or false when unset/not permitted.
     */
    public static function get_lti_role() {
        global $DB;

        $roleid = (int)get_config(config::COMPONENT, 'ibnltirole');
        if ($roleid <= 0) {
            return false;
        }

        $allowed = \local_intellistream\helpers\lti_roles_helper::options();
        if (!array_key_exists($roleid, $allowed)) {
            debugging(
                'local_intellistream: configured LTI role ' . $roleid
                    . ' does not hold local/intellistream:viewlti — refusing to assign it.',
                DEBUG_NORMAL
            );
            return false;
        }

        // Viewlti says the role may see the dashboard, not what else it carries;
        // set_lti_role() assigns at system context, so the rest is granted
        // site-wide to a user set the IntelliBoard service chose.
        //
        // Not refused: ibnltirole is locally managed, so plugin_report::is_writable()
        // stops remote writes and the value came from a local administrator.
        // Overriding that decision would break a working site, but it must not
        // happen quietly.
        $escalating = \local_intellistream\helpers\lti_roles_helper::escalation_capabilities($roleid);
        if ($escalating) {
            \local_intellistream\buffer::trace('local_intellistream: the configured LTI role (' . $roleid . ') allows '
                . implode(', ', $escalating) . ' at system context, so assigning it grants those capabilities '
                . 'site-wide to every user in the synced set. Prefer a dedicated role holding only '
                . 'local/intellistream:viewlti.');
        }

        return $DB->get_record('role', ['id' => $roleid]);
    }

    /**
     * (Re)assign the IntelliBoard LTI role at system context to exactly the given
     * user set, using Moodle's own role API.
     *
     * `$ids` ∪ (holders of any role in `$roles`) is the desired membership. The
     * IntelliBoard service sends the full set on every call, so this is a sync.
     * role_assign()/role_unassign() fire the core events and invalidate caches.
     * Only the difference is applied: this plugin observes `\core\event\base`, so
     * reassigning everyone would capture ~2N events; a re-sync that changes nothing
     * fires no events and writes nothing.
     *
     * Scoped to `component = ''`: existing assignments carry that value, and it
     * stops role_unassign() touching another component's assignments. Using
     * `component = 'local_intellistream'` would also stop admins removing them by hand.
     *
     * @param array $ids   Explicit Moodle user ids to hold the role.
     * @param array $roles Source role ids whose holders should also hold it.
     * @return void
     */
    public function set_lti_role($ids = [], $roles = []) {
        global $DB;

        // Force ids/roles to positive integers. User and role ids are always
        // positive integers, so this is a no-op for every legitimate value; it
        // only neutralises non-integer input.
        $ids   = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
        $roles = is_array($roles) ? array_values(array_filter(array_map('intval', $roles))) : [];

        if (!$role = self::get_lti_role()) {
            return;
        }

        $context = \context_system::instance();

        // Desired set.
        // Resolved in PHP with placeholder-bound queries, which works on every
        // database Moodle supports (`UNION DISTINCT` is not valid on SQL Server
        // or Oracle).
        $desired = [];

        // Both branches resolve through {user}, so an id that does not exist on
        // this site cannot produce an assignment. Deleted users are not filtered
        // out: narrowing the membership rule would be a product change.
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'uid');
            foreach ($DB->get_fieldset_select('user', 'id', "id $insql", $params) as $uid) {
                $desired[(int) $uid] = true;
            }
        }

        if ($roles) {
            [$insql, $params] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'rid');
            $sql = "SELECT DISTINCT ra.userid
                      FROM {role_assignments} ra
                      JOIN {user} u ON u.id = ra.userid
                     WHERE ra.roleid $insql";
            foreach ($DB->get_fieldset_sql($sql, $params) as $uid) {
                $desired[(int) $uid] = true;
            }
        }

        // Current set.
        $current = [];
        $existing = $DB->get_fieldset_select(
            'role_assignments',
            'userid',
            'roleid = :roleid AND contextid = :contextid AND component = :component',
            ['roleid' => $role->id, 'contextid' => $context->id, 'component' => '']
        );
        foreach ($existing as $uid) {
            $current[(int) $uid] = true;
        }

        // Converge.
        // role_assign()/role_unassign() handle the audit events, component and
        // modifierid, and the access-cache invalidation (mark_dirty + purge).
        foreach (array_keys($current) as $uid) {
            if (!isset($desired[$uid])) {
                role_unassign($role->id, $uid, $context->id, '');
            }
        }
        foreach (array_keys($desired) as $uid) {
            if (!isset($current[$uid])) {
                role_assign($role->id, $uid, $context->id, '');
            }
        }
    }

    /**
     * Lti sign parameters.
     *
     * @param array $oldparms LTI parameters to sign.
     * @return array|null
     */
    public function lti_sign_parameters($oldparms) {
        global $CFG;
        // Core's OAuth 1.0 library is not autoloaded — require it before the
        // moodle\mod\lti\OAuth* classes aliased above are instantiated.
        require_once($CFG->dirroot . '/mod/lti/OAuth.php');

        $parms = $oldparms;
        $hmacmethod = new OAuthSignatureMethod_HMAC_SHA1();
        $testconsumer = new OAuthConsumer($this->key, $this->secret, null);
        $accreq = OAuthRequest::from_consumer_and_token(
            $testconsumer,
            '',
            "POST",
            $this->endpoint,
            $parms
        );
        $accreq->sign_request($hmacmethod, $testconsumer, '');
        $newparms = $accreq->get_parameters();

        return $newparms;
    }

    /**
     * Return the launch data required for opening the attendance tool.
     *
     * @param array $customparams Extra custom LTI parameters.
     * @return array the endpoint URL and parameters (including the signature)
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function lti_get_launch_data($customparams = []) {
        if (!empty($this->key) && !empty($this->secret) && !empty($this->endpoint)) {
            $parms = $this->lti_request_params($customparams);

            $endpointurl = new \moodle_url(
                config::lti_tool_url()
            );
            $endpointparams = $endpointurl->params();

            // Strip querystring params in endpoint url from $parms to avoid duplication.
            if (!empty($endpointparams) && !empty($parms)) {
                foreach (array_keys($endpointparams) as $paramname) {
                    if (isset($parms[$paramname])) {
                        unset($parms[$paramname]);
                    }
                }
            }
        } else {
            // Admin misconfiguration (missing LTI key/secret/endpoint). Raise it
            // so the calling page renders Moodle's standard error output —
            // echoing a hardcoded English string and exit()ing from inside a
            // service bypasses both output handling and localisation.
            throw new \moodle_exception('error_lticredentials', config::COMPONENT);
        }

        return [$this->endpoint, $parms, $this->debug];
    }
}
