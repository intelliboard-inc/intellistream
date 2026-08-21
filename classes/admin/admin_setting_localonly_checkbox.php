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
 * Checkbox setting that the control plane may read but never write.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\admin;

/**
 * A checkbox whose only purpose is to switch a protection off.
 *
 * Used for the settings where "off" weakens the site — the HTTPS requirement
 * on the S3 ship, the curl/SSRF guard, the HTTPS requirement on the control
 * webhook itself, and payload encryption. Each is a legitimate local escape
 * hatch for an unusual deployment, but none has any reason to be flipped
 * remotely, and together they would let a compromised control plane redirect
 * the event stream somewhere unencrypted.
 *
 * Reading is unaffected: the value still appears in the config snapshot.
 */
class admin_setting_localonly_checkbox extends \admin_setting_configcheckbox implements locally_managed {
}
