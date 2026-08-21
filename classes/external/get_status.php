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
 * Status web-service for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\external;

defined('MOODLE_INTERNAL') || die();

use local_intellistream\config;

// Reuse the namespace-compat aliases set up in pull_export.php. Loading the
// pull_export class file installs the class_alias()es exactly once per
// request — we require_once it here so a customer who calls get_status alone
// (e.g. as a healthcheck) does not skip the compat shim.
require_once(__DIR__ . '/pull_export.php');

/**
 * `local_intellistream_get_status` external function.
 *
 * Returns a small JSON blob describing capture and shipping health:
 *
 *   - `ship_state`      ok | unconfigured | dropping | http_4xx | ...
 *   - `ship_detail`     freeform human-readable status string
 *   - `last_ship_ok`    RFC 3339 timestamp of the last successful ship, or ''
 *   - `last_ship_time`  RFC 3339 timestamp of the last ship attempt, or ''
 *   - `buffer_files`    count of `.jsonl.closed` files currently in the buffer
 *   - `buffer_files_pulled` count of legacy `.jsonl.pulled` files left behind
 *      by the pre-0.9.21 pull WS (which now deletes drained files); nonzero
 *      only until the disk-cap sweep / upgrade cleanup removes them
 *   - `buffer_files_previous` count of files left in a directory the buffer used
 *      to be pointed at; the shipper drains them, so this trends to zero on its
 *      own. A value that stays nonzero means captured records are undelivered
 *   - `plugin_enabled`  0 | 1
 *   - `s3_configured`   0 | 1
 *   - `encryption_enabled` 0 | 1
 *
 * Intended for the puller's admin UI / monitoring. No state changes.
 */
class get_status extends external_api_compat {
    /**
     * Parameter signature (no parameters).
     *
     * @return \external_function_parameters|\core_external\external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters_compat([]);
    }

    /**
     * Return-shape signature.
     *
     * @return \external_single_structure|\core_external\external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure_compat([
            'ship_state'          => new external_value_compat(PARAM_RAW, 'Shipper state token.'),
            'ship_detail'         => new external_value_compat(PARAM_RAW, 'Shipper status detail.'),
            'last_ship_ok'        => new external_value_compat(PARAM_RAW, 'Last successful ship (ISO).'),
            'last_ship_time'      => new external_value_compat(PARAM_RAW, 'Last ship attempt (ISO).'),
            'buffer_files'        => new external_value_compat(PARAM_INT, 'Count of closed, shippable buffer files.'),
            'buffer_files_pulled' => new external_value_compat(
                PARAM_INT,
                'Count of legacy pulled files pending cleanup (always 0 once drained).'
            ),
            'buffer_files_previous' => new external_value_compat(
                PARAM_INT,
                'Count of buffer files left in a directory the buffer used to be pointed at.'
            ),
            'plugin_enabled'      => new external_value_compat(PARAM_INT, '0 / 1.'),
            's3_configured'       => new external_value_compat(PARAM_INT, '0 / 1.'),
            'encryption_enabled'  => new external_value_compat(PARAM_INT, '0 / 1.'),
        ]);
    }

    /**
     * Read shipper state from mdl_config_plugins and the buffer dir.
     *
     * @return array
     */
    public static function execute() {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/intellistream:pullexport', $context);

        $dir = config::buffer_dir();
        $closedcount = 0;
        $pulledcount = 0;
        if (is_dir($dir)) {
            $closedcount = count(\local_intellistream\buffer::safe_files($dir, ['*.jsonl.closed']));
            $pulledcount = count(\local_intellistream\buffer::safe_files($dir, ['*.jsonl.pulled']));
        }

        // Records left in a directory the buffer used to be pointed at. Reported
        // separately rather than folded into buffer_files, which describes the
        // directory in use: a puller that treats these as fetchable would keep
        // asking for records the pull service does not serve from there.
        $previouscount = 0;
        foreach (config::previous_tracked_dirs() as $previousdir) {
            $previouscount += \local_intellistream\buffer::entry_count($previousdir);
        }

        $lastok = (int)config::get('last_ship_ok', 0);
        $lasttime = (int)config::get('ship_time', 0);

        $s3configured = (
            (string)config::get('endpoint', '') !== ''
            && (string)config::get('bucket', '') !== ''
            && (string)config::get('accesskey', '') !== ''
            && (string)config::get('secretkey', '') !== ''
        ) ? 1 : 0;

        $encenabled = (int)(bool)(int)config::get('encryption_enabled', 0);

        return [
            'ship_state'          => (string)config::get('ship_state', ''),
            'ship_detail'         => (string)config::get('ship_detail', ''),
            'last_ship_ok'        => $lastok > 0 ? gmdate('Y-m-d\TH:i:s\Z', $lastok) : '',
            'last_ship_time'      => $lasttime > 0 ? gmdate('Y-m-d\TH:i:s\Z', $lasttime) : '',
            'buffer_files'        => $closedcount,
            'buffer_files_pulled' => $pulledcount,
            'buffer_files_previous' => $previouscount,
            'plugin_enabled'      => config::enabled() ? 1 : 0,
            's3_configured'       => $s3configured,
            'encryption_enabled'  => $encenabled,
        ];
    }
}
