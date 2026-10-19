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
 *   - `ship_state`      ok | unconfigured | loadgated | lock_unavailable | s3_other_4xx
 *     | ... (`dropping` only as left by an earlier release: nothing produces it any more)
 *   - `ship_detail`     a JSON object with a lang string key and its parameters (see
 *     shipper::set_status_string()), or a plain string stored by an earlier release
 *   - `last_ship_ok`    RFC 3339 timestamp of the last successful ship, or ''
 *   - `last_ship_time`  RFC 3339 timestamp of the last ship attempt, or ''
 *   - `buffer_files`    count of `.jsonl.closed` files currently in the buffer
 *
 *   The buffer counts come from the measurement the shipper publishes on every run
 *   (see `buffer_measured_at`), not from listing the directory in this request:
 *   with tens of thousands of buffer files that listing took longer than the
 *   ingress in front of this service allows.
 *   - `undeletable_files` count of files that reached object storage but could not
 *     be removed from the buffer directory. Non-zero means shipping is STOPPED:
 *     the control plane must not read a fresh `last_ship_ok` as healthy while it is.
 *   - `buffer_files_pulled` count of legacy `.jsonl.pulled` files left behind
 *      by the pre-0.9.21 pull WS (which now deletes drained files); nonzero
 *      only until the shipper removes them (they were already delivered)
 *   - `buffer_files_previous` count of files still to ship from a directory the
 *      buffer used to be pointed at; the shipper drains them, so this trends to zero
 *      on its own. Parked files there are counted in `buffer_files_parked` instead.
 *      A value that stays nonzero means captured records are undelivered
 *   - `buffer_files_parked` count of files object storage refused on their own,
 *      set aside under a `.parked` name so shipping continues; kept, never deleted
 *   - `buffer_oldest_parked_at` RFC 3339 modification time of the oldest parked file,
 *      or '' when there is none; how long records have been held back
 *   - `buffer_measured_at` RFC 3339 time of that measurement, or '' when there is none
 *   - `ship_truncated`  1 when the last ship run stopped at its time limit with files
 *      still waiting (shipping works; the backlog is larger than one run), else 0
 *   - `capture_refused_recent` 1 while capture is being refused because the buffer
 *      is full (its last refusal is recent)
 *   - `plugin_enabled`  0 | 1
 *   - `s3_configured`   0 | 1
 *   - `encryption_enabled` 0 | 1
 *
 * Ship state, detail and `last_ship_ok` are rewritten when they change and
 * otherwise every few minutes (shipper::STATUS_HEARTBEAT_SEC), so a count in the
 * detail, and `last_ship_ok`, can lag by up to that long; `last_ship_time` comes
 * from the published measurement and moves every run.
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
            'undeletable_files'   => new external_value_compat(
                PARAM_INT,
                'Count of delivered buffer files that could not be removed; non-zero means shipping is stopped.'
            ),
            'buffer_files_pulled' => new external_value_compat(
                PARAM_INT,
                'Count of legacy pulled files pending cleanup (always 0 once drained).'
            ),
            'buffer_files_previous' => new external_value_compat(
                PARAM_INT,
                'Count of buffer files still to ship from a directory the buffer used to be pointed at '
                    . '(parked files there are counted in buffer_files_parked).'
            ),
            'buffer_files_parked' => new external_value_compat(
                PARAM_INT,
                'Count of buffer files object storage refused on their own, kept aside so shipping continues '
                    . '(in the buffer directory or a previous one).'
            ),
            'buffer_oldest_parked_at' => new external_value_compat(
                PARAM_RAW,
                'RFC 3339 modification time of the oldest parked buffer file, or empty when there is none.'
            ),
            'ship_truncated' => new external_value_compat(
                PARAM_INT,
                '1 when the last ship run stopped at its time limit with files still waiting.'
            ),
            'buffer_measured_at'  => new external_value_compat(
                PARAM_RAW,
                'When the buffer counts were measured (ISO), or empty when they have not been yet.'
            ),
            'capture_refused_recent' => new external_value_compat(
                PARAM_INT,
                '1 while capture is being refused because the buffer is at its cap.'
            ),
            'plugin_enabled'      => new external_value_compat(PARAM_INT, '0 / 1.'),
            's3_configured'       => new external_value_compat(PARAM_INT, '0 / 1.'),
            'encryption_enabled'  => new external_value_compat(PARAM_INT, '0 / 1.'),
            'sweep_unconfirmed'   => new external_value_compat(
                PARAM_INT,
                'Entities whose last verification pass could not be confirmed delivered (shipping not getting through). '
                    . 'A site that ships to object storage and is also pulled is not counted here: each pull that '
                    . 'returns records clears its fingerprints, so every pass there sends each table in full.'
            ),
            'sweep_overdue'       => new external_value_compat(
                PARAM_INT,
                'Entities not fully verified within the last cycle (deletes and timestamp-less tables not current).'
            ),
            'sweep_parked'        => new external_value_compat(
                PARAM_INT,
                'Entities parked after repeated read failures, or waiting after the buffer refused their rows.'
            ),
            'sweep_oldest_hours'  => new external_value_compat(PARAM_FLOAT, 'Age of the oldest completed verification pass.'),
            'refresh_task_disabled' => new external_value_compat(
                PARAM_INT,
                '1 when the 15-minute task, the only task that exports tables, is disabled.'
            ),
            'backfill_gate_held'  => new external_value_compat(
                PARAM_INT,
                'Entities a new install still holds for the historical backfill.'
            ),
            'backfill_complete'   => new external_value_compat(PARAM_INT, '1 once the historical backfill finished.'),
            'backfill_tables_done' => new external_value_compat(PARAM_INT, 'Tables the historical backfill has finished.'),
            'backfill_tables_total' => new external_value_compat(PARAM_INT, 'Tables the historical backfill covers.'),
            'backfill_current'    => new external_value_compat(PARAM_RAW, 'Table the backfill is on (empty when done).'),
            'backfill_released'   => new external_value_compat(
                PARAM_INT,
                'Tables released after making no progress (carried by the 15-minute task instead).'
            ),
            'backfill_last_progress' => new external_value_compat(
                PARAM_RAW,
                'Last time the backfill advanced (ISO); a stalled backfill stops moving.'
            ),
            'destination_ready'   => new external_value_compat(
                PARAM_INT,
                '1 when table exports run: site id plus all four destination settings, or a recent pull.'
            ),
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

        // From the shipper's published measurement: reading the directory here cost
        // one stat per buffer file, inside a request the ingress cuts off at 180 s.
        $measured = \local_intellistream\buffer::read_capacity(config::buffer_dir());
        $closedcount = $measured !== null ? $measured['closed'] : 0;
        $pulledcount = $measured !== null ? $measured['pulled'] : 0;
        $parkedcount = $measured !== null ? $measured['parked'] : 0;

        // Records left in a directory the buffer used to be pointed at. Reported
        // separately rather than folded into buffer_files, which describes the
        // directory in use: a puller that treats these as fetchable would keep
        // asking for records the pull service does not serve from there.
        $previouscount = 0;
        foreach (config::previous_tracked_dirs() as $previousdir) {
            $previouscount += $measured !== null ? ($measured['prev'][$previousdir] ?? 0) : 0;
        }

        $lastok = (int)config::get('last_ship_ok', 0);
        // The stored ship time is rewritten only on change or every few minutes
        // (shipper::set_status()); the published measurement carries the latest run start.
        $lasttime = max((int)config::get('ship_time', 0), $measured !== null ? $measured['start'] : 0);

        $s3configured = (
            (string)config::get('endpoint', '') !== ''
            && (string)config::get('bucket', '') !== ''
            && (string)config::get('accesskey', '') !== ''
            && (string)config::get('secretkey', '') !== ''
        ) ? 1 : 0;

        $encenabled = (int)(bool)(int)config::get('encryption_enabled', 0);
        $sweep = \local_intellistream\sweep::status();
        $bf = \local_intellistream\backfill::progress();

        return [
            'ship_state'          => (string)config::get('ship_state', ''),
            'ship_detail'         => (string)config::get('ship_detail', ''),
            'last_ship_ok'        => $lastok > 0 ? gmdate('Y-m-d\TH:i:s\Z', $lastok) : '',
            'last_ship_time'      => $lasttime > 0 ? gmdate('Y-m-d\TH:i:s\Z', $lasttime) : '',
            'buffer_files'        => $closedcount,
            'undeletable_files'   => (int)config::get('last_undeletable_count', 0),
            'buffer_files_pulled' => $pulledcount,
            'buffer_files_previous' => $previouscount,
            'buffer_files_parked' => $parkedcount,
            'buffer_oldest_parked_at' => $measured !== null && $parkedcount > 0 && $measured['parkedat'] > 0
                ? gmdate('Y-m-d\TH:i:s\Z', $measured['parkedat']) : '',
            'ship_truncated'      => $measured !== null && (string)config::get('ship_state', '') === 'ok'
                ? (int)$measured['truncated'] : 0,
            'buffer_measured_at'  => $measured !== null ? gmdate('Y-m-d\TH:i:s\Z', $measured['at']) : '',
            'capture_refused_recent' => (int)\local_intellistream\buffer::capture_refused_recently(),
            'plugin_enabled'      => config::enabled() ? 1 : 0,
            's3_configured'       => $s3configured,
            'encryption_enabled'  => $encenabled,
            'sweep_unconfirmed'   => $sweep['unconfirmed'],
            'sweep_overdue'       => $sweep['overdue'],
            'sweep_parked'        => $sweep['parked'],
            'sweep_oldest_hours'  => $sweep['oldest_hours'],
            'refresh_task_disabled' => $sweep['refresh_disabled'],
            'backfill_gate_held'  => $sweep['gate_held'],
            'backfill_complete'   => $bf['complete'],
            'backfill_tables_done' => $bf['done'],
            'backfill_tables_total' => $bf['total'],
            'backfill_current'    => $bf['current'],
            'backfill_released'   => $bf['released'],
            'backfill_last_progress' => $bf['last_progress'],
            'destination_ready'   => (int)config::destination_ready(),
        ];
    }
}
