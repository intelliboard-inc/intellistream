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
 * prefixes as an InForm dynamic-table candidate. It was PARAM_RAW free text with
 * no validation: a prefix of `user` matches core's `user` table, whose registry
 * key is also `user`, so the discovered row shadowed the curated built-in and
 * widened it to whole-row export — shipping the bcrypt hashes.
 *
 * Discovery is for LOCAL PLUGIN families (the default is `local_intellicart_`) and
 * no core Moodle table lives in the `local_` namespace, so requiring that prefix
 * rejects the dangerous input at the point it is typed without narrowing the
 * feature.
 *
 * This validator is a convenience, not the guard. A value reaches
 * mdl_config_plugins without passing through this form whenever it is written
 * by set_plugin_config over the control webhook, or by any other direct write,
 * and admin_setting::validate() is not called on those paths. The binding check
 * is therefore dynamic_discovery_service::prefixes(), which re-applies
 * REQUIRED_PREFIX on every read and drops anything that fails.
 *
 * Do not treat config_service::may_widen_to_whole_row() or
 * exporter::FORBIDDEN_COLUMNS as backstops for this: the first is only reached
 * on the branch that widens an existing curated entry, so a table with no
 * curated entry is registered whole-row without it, and the second is a
 * blocklist of column names that does not cover `hash`, `sid`, `value`,
 * `privatetoken`, `pushid` or `publickey`.
 */
class admin_setting_discovery_prefixes extends \admin_setting_configtextarea {
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
            // Empty disables discovery entirely — always valid.
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
