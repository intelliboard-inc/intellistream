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
 * This one value is the entire authentication for the inbound control channel.
 * A holder of it can rewrite non-secret plugin config, rotate the S3 credential,
 * reschedule or disable this plugin's cron tasks, trigger full re-exports and
 * assign a Moodle role to an arbitrary set of users. Every one of those actions
 * is individually well guarded; none of those guards means anything if the
 * secret itself is guessable, and the field accepted any string at all.
 *
 * MIN_LENGTH is a floor against a human pasting something short, not a serious
 * entropy test — the plugin cannot tell a 32-character random token from 32
 * repeated characters, and pretending otherwise would be theatre. The control
 * plane mints the real secret; this stops a hand-typed one from silently
 * becoming the weakest part of the chain.
 *
 * Extends admin_setting_configpasswordunmask, NOT admin_setting_configtext, and
 * that matters beyond the masked input: plugin_report::setting_defs() decides
 * `'secret' => ($setting instanceof \admin_setting_configpasswordunmask)`, and
 * plugin_report::is_writable() refuses to let the control plane write a secret.
 * Changing the base class would quietly make the webhook secret remotely
 * writable and stop it being redacted in the config snapshot.
 *
 * Validated on SAVE only. A site already paired with a shorter secret keeps
 * authenticating and is not locked out of its own control channel; it has to
 * meet the bar the next time the value changes. Rejecting a short stored secret
 * at verification time instead would break live sites on upgrade, with the
 * failure showing up as unexplained webhook rejections.
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
