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
 * Install hook for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Install routine.
 *
 * Seeds every setting that has a non-empty default in settings.php, so a fresh
 * install behaves the way its own settings page says it does before an
 * administrator has ever opened that page. It mints nothing: the site id is
 * issued by the control plane and pasted in during pairing, so a fresh install
 * ships nothing until that happens.
 *
 * The plugin's four tables (local_intellistream_config, _logs, _colpart and
 * _colsync) are created by the installer from db/install.xml, not here.
 *
 * @return bool
 */
function xmldb_local_intellistream_install() {
    // Site id is no longer auto-generated: it is MINTED by the control plane (supernova) and
    // pasted into the plugin settings by the operator (IntelliStream pairing). A fresh install starts
    // with an empty site id and ships nothing until it is set.

    // The list lives on config so install and upgrade cannot drift apart.
    \local_intellistream\config::seed_declared_defaults();

    return true;
}
