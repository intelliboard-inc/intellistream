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
 * Role-select options for the LTI role-assignment setting.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\helpers;

/**
 * Which roles may be configured as the LTI role, and which of them are too
 * powerful to hand out at system context without saying so.
 */
class lti_roles_helper {
    /**
     * Capabilities that core under-flags but whose holder can escalate further.
     *
     * Core does not flag the role-management capabilities as RISK_MANAGETRUST:
     * moodle/role:assign and moodle/role:manage both carry
     * RISK_XSS|RISK_PERSONAL|RISK_SPAM and nothing else, so a risk-driven check
     * alone would pass over the capabilities that matter most here. The same goes
     * for creating, editing and deleting user accounts. These are named; every
     * capability core does flag RISK_CONFIG or RISK_MANAGETRUST is added to them
     * by {@see escalation_list()}.
     *
     * @var string[]
     */
    const ESCALATION_CAPABILITIES = [
        'moodle/role:assign',
        'moodle/role:manage',
        'moodle/role:override',
        'moodle/role:safeoverride',
        'moodle/user:update',
        'moodle/user:create',
        'moodle/user:delete',
    ];

    /**
     * Every capability whose holder can escalate further, or take the site over.
     *
     * The union of {@see ESCALATION_CAPABILITIES} and every installed capability
     * whose risk bitmask has RISK_CONFIG or RISK_MANAGETRUST (moodle/site:config,
     * moodle/user:loginas and the like), from get_all_capabilities(), which core
     * caches.
     *
     * local/intellistream:viewlti itself is RISK_PERSONAL, so a role that
     * holds only the capability this setting exists to select is never
     * reported.
     *
     * @return string[]
     */
    protected static function escalation_list(): array {
        $names = self::ESCALATION_CAPABILITIES;
        foreach (get_all_capabilities() as $cap) {
            $cap = (array)$cap;
            if (!empty($cap['name']) && ((int)($cap['riskbitmask'] ?? 0) & (RISK_CONFIG | RISK_MANAGETRUST))) {
                $names[] = $cap['name'];
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * The escalation capabilities a role ALLOWS in its system-context definition.
     *
     * Scoped to the system context because that is the context
     * lti_service::set_lti_role() assigns at. An override that grants one of
     * these somewhere further down the context tree does not grant it
     * site-wide, and must not be reported as though it did.
     *
     * Answers "none" on any error: this drives a diagnostic, and a metadata
     * failure must not become an exception on the assignment path.
     *
     * @param int $roleid
     * @return string[] Capability names; empty when the role holds none.
     */
    public static function escalation_capabilities(int $roleid): array {
        global $DB;

        if ($roleid <= 0) {
            return [];
        }

        try {
            [$insql, $params] = $DB->get_in_or_equal(self::escalation_list(), SQL_PARAMS_NAMED, 'cap');
            $params['roleid'] = $roleid;
            $params['contextid'] = \context_system::instance()->id;
            $params['permission'] = CAP_ALLOW;

            return array_values($DB->get_fieldset_select(
                'role_capabilities',
                'capability',
                "roleid = :roleid AND contextid = :contextid AND permission = :permission AND capability {$insql}",
                $params
            ));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Options for the `ibnltirole` setting: a "not selected" entry plus every
     * role that allows the viewlti capability.
     *
     * @return array roleid => name
     */
    public static function options(): array {
        $options = ['' => get_string('notselected', 'local_intellistream')];
        if ($roles = get_roles_with_capability('local/intellistream:viewlti', CAP_ALLOW)) {
            foreach ($roles as $role) {
                $options[$role->id] = !empty($role->name)
                    ? format_string($role->name)
                    : ucfirst($role->shortname);
            }
        }
        return $options;
    }
}
