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
 * This file is part of the local_intellistream plugin for Moodle.
 *
 * IntelliStream — minimal Moodle event-capture adapter.
 * Captures Moodle events and ships them to S3-compatible object storage.
 * The capture path writes zero rows to the host Moodle database. (LTI role
 * assignment, triggered by the control plane over the signed control webhook,
 * is the only component that writes — it assigns the configured LTI role in
 * role_assignments.)
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_intellistream';
// The integer MUST rise: db/upgrade.php gates the IBV2-998 clockless-lane
// seeding on `$oldversion < 2026090100`, and Moodle runs an upgrade step only
// when this number moves. Moodle orders plugin upgrades on THIS number alone,
// so it is the only one that has to go forwards.
//
// The 3.9 line names 0.9.32-intellistream+m39 for the same build.
$plugin->version   = 2026092600;
$plugin->requires  = 2022112800; // Moodle 4.1.
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.32-intellistream';
