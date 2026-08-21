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
 * Text setting that the control plane may read but never write.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\admin;

/**
 * A text setting whose value the control plane must not be able to rewrite.
 *
 * Used for `siteid` — the identity that pairs this Moodle to one customer
 * connection. It is minted by the control plane and pasted in once by an
 * operator; letting it be rewritten remotely would let one connection's
 * events be re-pointed at another, so it is set locally and only read back.
 */
class admin_setting_localonly_text extends \admin_setting_configtext implements locally_managed {
}
