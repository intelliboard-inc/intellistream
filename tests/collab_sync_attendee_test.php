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

use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\local_intellistream\task\collab_sync::class)]
/**
 * Tests that Collaborate attendance is stored only for real Moodle users.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\task\collab_sync
 */
final class collab_sync_attendee_test extends \advanced_testcase {
    /**
     * Run sync_session() against fixed attendees for one session instance.
     *
     * @param array $attendees
     * @return void
     */
    private function sync(array $attendees): void {
        $client = new class ($attendees) extends \local_intellistream\collab\collab_client {
            /** @var array Attendees to return. */
            private $fixed;

            /**
             * Keep the fixed attendees.
             *
             * @param array $attendees
             */
            public function __construct(array $attendees) {
                parent::__construct();
                $this->fixed = $attendees;
            }

            /**
             * One instance per session.
             *
             * @param string $sessionuid
             * @return array
             */
            public function get_session_instances(string $sessionuid): array {
                return [['id' => 'instance-1']];
            }

            /**
             * The fixed attendees.
             *
             * @param string $sessionuid
             * @param string $instanceid
             * @return array
             */
            public function get_session_attendees(string $sessionuid, string $instanceid): array {
                return $this->fixed;
            }
        };
        $task = new class extends \local_intellistream\task\collab_sync {
            /**
             * Run sync_session() for one session.
             *
             * @param \local_intellistream\collab\collab_client $client
             * @param string $sessionuid
             * @return void
             */
            public function sync(\local_intellistream\collab\collab_client $client, string $sessionuid): void {
                $this->sync_session($client, $sessionuid);
            }
        };
        $task->sync($client, 'session-1');
    }

    /**
     * A real user is stored; a guest, a mixed id, an unknown number and a deleted user are not.
     */
    public function test_only_moodle_user_ids_are_stored(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);
        $unknown = (int)$DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1000;
        $this->sync([
            ['userId' => 'a', 'externalUserId' => (string)$user->id, 'displayName' => 'Real user'],
            ['userId' => 'b', 'externalUserId' => 'guest-abc', 'displayName' => 'Guest'],
            ['userId' => 'c', 'externalUserId' => $user->id . 'abc', 'displayName' => 'Mixed'],
            ['userId' => 'd', 'externalUserId' => (string)$unknown, 'displayName' => 'Unknown'],
            ['userId' => 'e', 'externalUserId' => (string)$deleted->id, 'displayName' => 'Deleted'],
        ]);

        $rows = $DB->get_records('local_intellistream_colpart', ['sessionuid' => 'session-1']);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame((int)$user->id, (int)$row->external_user_id);
        $this->assertSame('Real user', $row->display_name);
        $this->assertSame(0, $DB->count_records('local_intellistream_colpart', ['external_user_id' => 0]));
    }

    /**
     * The site guest account is not stored.
     */
    public function test_site_guest_is_not_stored(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->sync([
            ['userId' => 'a', 'externalUserId' => (string)$user->id, 'displayName' => 'Real user'],
            ['userId' => 'g', 'externalUserId' => (string)$CFG->siteguest, 'displayName' => 'Site guest'],
        ]);

        $this->assertSame(1, $DB->count_records('local_intellistream_colpart', ['sessionuid' => 'session-1']));
        $this->assertSame(0, $DB->count_records('local_intellistream_colpart', ['external_user_id' => $CFG->siteguest]));
    }
}
