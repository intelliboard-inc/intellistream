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
 * Upgrade steps that need code outside db/upgrade.php.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\local;

/**
 * Upgrade steps that need code outside db/upgrade.php.
 *
 * db/upgrade.php calls these methods. Do not remove or rename a method while
 * an upgrade step still calls it.
 */
class upgrade_helper {
    /**
     * Authorise every user who holds a token for the IntelliBoard Pro service.
     *
     * The service is declared with restrictedusers = 1, so core accepts a call
     * only from a user listed in {external_services_users}. This adds the users
     * who already hold a token, so their working access continues.
     *
     * Rows are written through core's \webservice::add_ws_authorised_user(),
     * which owns the shape of that table and sets timecreated. validuntil stays
     * null, which means no expiry.
     *
     * @return void
     */
    public static function authorise_token_holders(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/webservice/lib.php');

        $service = $DB->get_record('external_services', ['shortname' => 'intelliboard_pro'], 'id');
        if (!$service) {
            return;
        }

        $wsmanager = new \webservice();
        $tokenholders = $DB->get_fieldset_sql(
            'SELECT DISTINCT userid FROM {external_tokens} WHERE externalserviceid = :sid',
            ['sid' => $service->id]
        );
        foreach ($tokenholders as $userid) {
            $exists = $DB->record_exists('external_services_users', [
                'externalserviceid' => $service->id,
                'userid'            => $userid,
            ]);
            if (!$exists) {
                $wsmanager->add_ws_authorised_user((object)[
                    'externalserviceid' => $service->id,
                    'userid'            => $userid,
                    'iprestriction'     => null,
                    'validuntil'        => null,
                ]);
            }
        }
    }
}
