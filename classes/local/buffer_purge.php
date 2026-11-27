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
 * Removal of every buffer file when the plugin is uninstalled.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\local;

use local_intellistream\buffer;
use local_intellistream\change_ledger;
use local_intellistream\config;

/**
 * Deletes the plugin's buffer files and directories (db/uninstall.php).
 */
class buffer_purge {
    /**
     * Remove this plugin's buffer files and, if they leave nothing behind, the
     * directories that held them. Called from db/uninstall.php.
     *
     * Not a recursive delete: core's fulldelete() erases whatever directory it is
     * handed, so a `bufferdir` pointed at the dataroot would destroy the site's
     * files. Instead only files matching this plugin's own naming are unlinked, and
     * directories are removed with rmdir(), which fails on a non-empty directory.
     * No value of `bufferdir` can make this destructive.
     *
     * Every directory config::tracked_buffer_dirs() names is purged, including one
     * that no longer passes validation: uninstall is the last chance to remove this
     * personal data.
     *
     * @return array{files:int,dirs:int,dir:string,previousdirs:int} Counts removed, the
     *         current dir, and how many previous dirs were found on disk and purged.
     */
    public static function purge_all(): array {
        $dir = config::buffer_dir();
        $dirs = config::tracked_buffer_dirs();
        $removedfiles = 0;
        $removeddirs = 0;
        $previousdirs = 0;

        foreach ($dirs as $target) {
            if ($target === '' || !is_dir($target)) {
                continue;
            }
            if ($target !== $dir) {
                $previousdirs++;
            }
            // Rewrite temporaries are removed with no age guard: an uninstall has
            // nothing in flight, and one left behind would keep records on disk and
            // make the rmdir() below decline.
            $removedfiles += buffer::purge_residue($target);
            foreach (buffer::own_file_patterns() as $pattern) {
                foreach (glob($target . '/' . $pattern) ?: [] as $path) {
                    // Never follow a symlink out of the buffer dir. unlink() acts on
                    // the link itself, never its target, so removing it cannot delete
                    // anything outside the buffer dir, and leaving it would make the
                    // rmdir() below decline. Anything else that is not a plain file
                    // (a directory, a FIFO) is left alone and rmdir() then declines.
                    if ((buffer::is_safe_file($path) || is_link($path)) && @unlink($path)) {
                        $removedfiles++;
                    }
                }
            }
            // The published measurement, its lock and claim, the lane marks and the
            // per-worker pointers are this plugin's too, and would otherwise hold the
            // directory non-empty.
            $removedfiles += buffer::remove_bookkeeping($target);
            // Succeeds only if nothing else lives here.
            if (@rmdir($target)) {
                $removeddirs++;
            }
        }

        // Tidy the plugin's own moodledata root when the buffer was the only
        // thing in it. Same rmdir semantics: a no-op if anything else remains.
        $root = config::buffer_root();
        if (is_dir($root) && @rmdir($root)) {
            $removeddirs++;
        }

        // Whatever was un-shipped is gone: no fingerprint can be trusted any more. The
        // marker first, so a pass running right now cannot be confirmed either.
        if (change_ledger::armed()) {
            set_config('last_erasure_time', time(), config::COMPONENT);
        }
        change_ledger::clear_all('the buffer was purged');

        return [
            'files' => $removedfiles,
            'dirs' => $removeddirs,
            'dir' => $dir,
            'previousdirs' => $previousdirs,
        ];
    }
}
