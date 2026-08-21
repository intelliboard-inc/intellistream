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
 * Uninstall hook for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Uninstall routine.
 *
 * Core removes this plugin's config rows and its four tables (see
 * db/install.xml) automatically. The one thing it does NOT clean up is the
 * on-disk append-only buffer, which can still hold un-shipped event/entity
 * payloads (PII). Delete that here.
 *
 * The deletion itself lives in buffer::purge_all(), which removes only files
 * matching this plugin's own naming and then rmdir()s the directories — never
 * a recursive delete of a configured path. That is deliberate: this hook used
 * to hand the `bufferdir` value to core's fulldelete(), which erases whatever
 * directory it is given, so a bufferdir pointing at the dataroot or filedir
 * turned a routine uninstall into irreversible loss of the site's file store.
 *
 * @return bool
 */
function xmldb_local_intellistream_uninstall() {
    $result = \local_intellistream\buffer::purge_all();

    // Uninstall renders a plain progress page; mtrace leaves a record of what
    // was actually removed without failing the uninstall when nothing was.
    mtrace('local_intellistream: removed ' . $result['files'] . ' buffer file(s) and '
        . $result['dirs'] . ' directory/ies from ' . $result['dir']
        . ($result['previousdirs'] > 0
            ? ' and ' . $result['previousdirs'] . ' directory/ies the buffer used to be pointed at'
            : ''));

    return true;
}
