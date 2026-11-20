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
 * Marker for plugin settings the control plane must not write.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\admin;

/**
 * Marks an admin setting as locally managed: readable in the control-plane
 * snapshot, but never writable over the control webhook.
 *
 * plugin_report::is_writable() already refuses secret-bearing settings. Some
 * non-secret settings are nonetheless unsafe to accept from the control plane
 * — either because a hostile value is destructive (`bufferdir`, whose value
 * the uninstall purge acts on) or because the admin UI applies a constraint
 * the webhook path cannot (`ibnltirole`, limited to roles holding
 * local/intellistream:viewlti).
 *
 * Implementing this interface is how a setting declares that. plugin_report
 * derives the flag by introspection, so no key names are hand-listed there and
 * settings added later are still picked up automatically.
 */
interface locally_managed {
}
