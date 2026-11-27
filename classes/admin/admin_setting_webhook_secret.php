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
 * Validated admin setting for the inbound control-webhook shared secret.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see        http://intelliboard.net/
 */

namespace local_intellistream\admin;

use local_intellistream\config;

/**
 * The `webhook_secret` setting, with a minimum length enforced on save.
 *
 * This one value is the entire authentication for the inbound control channel,
 * whose commands can rewrite plugin config, rotate the S3 credential, reschedule
 * tasks, trigger re-exports and assign a Moodle role to users. MIN_LENGTH is a
 * floor against a short hand-typed value, not an entropy test; the IntelliBoard
 * service mints the real secret.
 *
 * It extends admin_setting_configpasswordunmask, not admin_setting_configtext:
 * plugin_report::setting_defs() marks a setting secret when it is an
 * \admin_setting_configpasswordunmask, and plugin_report::is_writable() refuses
 * remote writes to secrets. Changing the base class would make the webhook secret
 * remotely writable and stop it being redacted in the config snapshot.
 *
 * Validated on save only, so a site already paired with a shorter secret keeps
 * authenticating; it has to meet the minimum the next time the value changes.
 */
class admin_setting_webhook_secret extends \admin_setting_configpasswordunmask {
    /** Shortest accepted non-empty secret, in characters. */
    const MIN_LENGTH = 32;

    /**
     * Reject a non-empty secret that is too short to be a minted credential.
     *
     * An empty value stays valid: clearing this field is how an admin disables
     * the inbound command channel entirely, and refusing it would take away the
     * off switch.
     *
     * @param string $data Submitted value.
     * @return bool|string True when valid, else a localised error message.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        $data = (string) $data;
        if ($data !== '' && \core_text::strlen($data) < self::MIN_LENGTH) {
            return get_string('webhook_secret_err_short', config::COMPONENT, self::MIN_LENGTH);
        }

        return true;
    }
}
