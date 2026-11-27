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
 * Validated admin setting for the dynamic-table discovery prefixes.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\admin;

use local_intellistream\config;

/**
 * The `dynamic_discovery_prefixes` setting, validated on save.
 *
 * Discovery auto-registers every live table whose name starts with one of these
 * prefixes as a dynamic-table candidate for the reporting service. Each prefix
 * must start with `local_`: discovery is for local plugin families (default
 * `local_intellicart_`) and no core table lives in that namespace, so a prefix
 * such as `user` — which would match core's `user` table and widen its curated
 * entry to whole-row export, password hashes included — is rejected when typed.
 * The setting is locally managed: the control webhook can read it but not write it.
 *
 * This validator is a convenience, not the guard: a direct write to
 * mdl_config_plugins skips admin_setting::validate(), so
 * dynamic_discovery_service::prefixes() re-applies REQUIRED_PREFIX on every read.
 * config_service::may_widen_to_whole_row() and exporter::FORBIDDEN_COLUMNS are not
 * backstops: the first only covers widening a curated entry, and the second does
 * not cover columns such as `hash`, `sid`, `value`, `privatetoken` or `pushid`.
 */
class admin_setting_discovery_prefixes extends \admin_setting_configtextarea implements locally_managed {
    /**
     * Prefix every discovery prefix must itself start with.
     *
     * Aliases the service's constant so the form and the read-time guard in
     * dynamic_discovery_service::prefixes() cannot drift apart. The service
     * owns the value because its guard also runs from cron and CLI.
     */
    const REQUIRED_PREFIX = \local_intellistream\services\dynamic_discovery_service::REQUIRED_PREFIX;

    /**
     * Reject a prefix that could match a core table.
     *
     * @param string $data Submitted value (one prefix per line / comma-separated).
     * @return bool|string True when valid, else a localised error message.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        $raw = trim((string)$data);
        if ($raw === '') {
            // Empty disables discovery entirely, so it is always valid.
            return true;
        }

        foreach (preg_split('/[\s,]+/', $raw) as $prefix) {
            $prefix = trim($prefix);
            if ($prefix === '') {
                continue;
            }
            if (strpos($prefix, self::REQUIRED_PREFIX) !== 0) {
                return get_string('dynamic_discovery_prefixes_err', config::COMPONENT, s($prefix));
            }
        }

        return true;
    }
}
