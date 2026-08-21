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

// MOODLE 3.9 RELEASE LINE. This branch (dev-3.9 / stage-3.9 / master-3.9) exists only
// to support customers still on 3.9; the 4.1+ line lives on dev / stage / master. Only
// this file and classes/buffer.php differ between the two — everything else, including
// classes/exporter.php, is deliberately kept identical so ports stay mechanical.
//
// $plugin->version is held EQUAL to the 4.x line on purpose, with the release string
// carrying the distinction. A site is either 3.9 or 4.x, so the numbers never compete
// in place; keeping them equal means a customer who later upgrades Moodle 3.9 -> 4.x and
// swaps to the 4.x build still sees a strictly greater version on every subsequent 4.x
// release, so the plugin upgrade fires. Letting this line race ahead would silently
// wedge that path, because Moodle refuses a downgrade.
// What `incompatible` does and does NOT do, measured on Moodle 4.5.11 rather than
// assumed: it does NOT stop the ZIP being installed. lib/classes/update/validator.php
// never reads it (0 references) and only checks `requires`, which 2020061500 satisfies
// on every 4.x — so the files WILL land if someone uploads this build to a 4.x site.
// What it does do is fail core_plugin_manager::all_plugins_ok(), which gates
// admin/index.php and admin/cli/upgrade.php; those stop with the plugin-check page
// instead of continuing. So the plugin cannot be brought into service on 4.0+, but the
// symptom an admin sees is a HALTED SITE UPGRADE, not a refused install. Keep `requires`
// as the real first line of defence and treat this as the backstop.
$plugin->component    = 'local_intellistream';
$plugin->version      = 2026082100;
$plugin->requires     = 2020061500; // Moodle 3.9.0.
$plugin->supported    = [39, 39];   // 3.9 only.
$plugin->incompatible = 40;         // Backstop: unusable from Moodle 4.0 (see note above).
$plugin->maturity     = MATURITY_BETA;
$plugin->release      = '0.9.30-intellistream+m39';
