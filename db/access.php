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
 * Capability definitions for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // View the shipper/buffer status page. RISK_CONFIG, matching viewlogs: the
    // page discloses the object-storage destination, the buffer directory and
    // the plugin's operational state.
    'local/intellistream:viewstatus' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // Edit per-datatype configuration overrides (intellidata parity).
    'local/intellistream:manage' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // View the operational log feed (intellidata parity).
    'local/intellistream:viewlogs' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Pull the canonical record stream over the REST web service. Required
    // capability for both `local_intellistream_pull_export` and
    // `local_intellistream_get_status`.
    'local/intellistream:pullexport' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        // No default archetype grant. pull_export returns the full
        // buffered PII stream (event + entity snapshots), so this cap must be
        // granted explicitly to a dedicated service-account role — never to
        // the authenticated-user archetype. Matches viewstatus/viewlti.
        'archetypes'   => [],
        // The WS framework also requires `webservice/rest:use`; that is a
        // core cap and is not re-declared here. The customer is expected to
        // create a dedicated service-account user, grant it
        // `webservice/rest:use` + this cap, and bind the token to it.
    ],

    // View the embedded IntelliBoard LTI dashboard (intellidata parity).
    // No default grant: access is granted via an explicit role/cohort (the
    // control webhook's set_lti_role action assigns the configured LTI role to
    // the right learners).
    'local/intellistream:viewlti' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
];
