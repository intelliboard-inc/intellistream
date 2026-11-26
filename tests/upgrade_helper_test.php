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

namespace local_intellistream;

/**
 * Tests for the upgrade steps in upgrade_helper.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\local\upgrade_helper
 */
final class upgrade_helper_test extends \advanced_testcase {
    /**
     * Give a user a token for the IntelliBoard Pro service.
     *
     * @param int $userid
     * @param int $serviceid
     * @return void
     */
    private function create_token(int $userid, int $serviceid): void {
        global $CFG, $DB;
        $context = \context_system::instance();
        if (class_exists('\core_external\util')) {
            $service = $DB->get_record('external_services', ['id' => $serviceid], '*', MUST_EXIST);
            \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $userid, $context);
        } else {
            require_once($CFG->libdir . '/externallib.php');
            external_generate_token(EXTERNAL_TOKEN_PERMANENT, $serviceid, $userid, $context);
        }
    }

    /**
     * A token holder who is not listed becomes listed; a listed one is not added twice.
     */
    public function test_authorise_token_holders_lists_each_holder_once(): void {
        global $DB;
        $this->resetAfterTest();

        $serviceid = (int) $DB->get_field('external_services', 'id', ['shortname' => 'intelliboard_pro'], MUST_EXIST);
        $holder = $this->getDataGenerator()->create_user();
        $listed = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        // Core issues a token for the service only to a user with its required capability.
        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/intellistream:pullexport', CAP_ALLOW, $roleid, $systemcontext->id);
        $this->getDataGenerator()->role_assign($roleid, $holder->id, $systemcontext->id);
        $this->getDataGenerator()->role_assign($roleid, $listed->id, $systemcontext->id);
        $this->create_token($holder->id, $serviceid);
        $this->create_token($listed->id, $serviceid);
        $DB->insert_record('external_services_users', (object)[
            'externalserviceid' => $serviceid,
            'userid'            => $listed->id,
            'timecreated'       => time(),
        ]);

        \local_intellistream\local\upgrade_helper::authorise_token_holders();

        $this->assertSame(1, $DB->count_records(
            'external_services_users',
            ['externalserviceid' => $serviceid, 'userid' => $holder->id]
        ));
        $this->assertSame(1, $DB->count_records(
            'external_services_users',
            ['externalserviceid' => $serviceid, 'userid' => $listed->id]
        ));
        $this->assertSame(0, $DB->count_records(
            'external_services_users',
            ['externalserviceid' => $serviceid, 'userid' => $other->id]
        ));
    }

    /**
     * A site without the service is left alone.
     */
    public function test_authorise_token_holders_without_service_does_nothing(): void {
        global $DB;
        $this->resetAfterTest();

        $DB->delete_records('external_services', ['shortname' => 'intelliboard_pro']);
        $before = $DB->count_records('external_services_users');

        \local_intellistream\local\upgrade_helper::authorise_token_holders();

        $this->assertSame($before, $DB->count_records('external_services_users'));
    }
}
