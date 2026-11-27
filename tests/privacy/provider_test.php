<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_intellistream\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_intellistream\buffer;
use local_intellistream\datatypes\exceptions_datatype;
use local_intellistream\userlogins;
use local_intellistream\local\buffer_privacy;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\local_intellistream\privacy\provider::class)]
#[CoversClass(\local_intellistream\local\buffer_privacy::class)]
/**
 * Tests for the privacy provider over buffered records and local_intellistream_ulogin.
 *
 * The buffer holds personal data until it ships, so a subject access request must
 * find a user's staged records and an erasure must remove them. Only the data
 * subject counts: a column naming who acted on a row (usermodified) must neither
 * put that row in the actor's export nor delete it on the actor's erasure.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\privacy\provider
 * @covers     \local_intellistream\local\buffer_privacy
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The data subject. */
    private $u1;

    /** @var \stdClass The other party. */
    private $u2;

    /** @var \stdClass A user with nothing staged. */
    private $nobody;

    /** @var \stdClass A user with only a login total: created before pairing, so nothing staged. */
    private $loginonly;

    /**
     * Pair the site and stage six records: U1's user row, a message between U1 and U2,
     * a grade of U2's that U1 modified, an event, a page dwell and an exception of U1;
     * then the Collaborate attendance rows and the login totals.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();

        // Users first, pairing after: while unpaired the buffer refuses everything, so
        // the user_created events the observer sees here stage nothing.
        $this->u1 = $this->getDataGenerator()->create_user();
        $this->u2 = $this->getDataGenerator()->create_user();
        $this->nobody = $this->getDataGenerator()->create_user();
        $this->loginonly = $this->getDataGenerator()->create_user();
        set_config('siteid', 'phpunit-privacy-site', 'local_intellistream');
        $u1 = (int)$this->u1->id;
        $u2 = (int)$this->u2->id;

        $records = [
            ['record_type' => 'entity_snapshot', 'entity' => 'user',
                'entity_data' => ['id' => $u1, 'username' => $this->u1->username]],
            ['record_type' => 'entity_snapshot', 'entity' => 'message',
                'entity_data' => ['id' => 501, 'useridfrom' => $u1, 'useridto' => $u2]],
            ['record_type' => 'entity_snapshot', 'entity' => 'grade_grades',
                'entity_data' => ['id' => 601, 'userid' => $u2, 'usermodified' => $u1]],
            ['record_type' => 'event', 'event_data' => ['eventname' => '\\core\\event\\user_loggedin', 'userid' => $u1]],
            ['record_type' => 'page_dwell', 'dwell' => ['userid' => $u1, 'url' => '/course/view.php?id=2', 'ms' => 5000]],
            ['record_type' => exceptions_datatype::RECORD_TYPE, 'entity' => exceptions_datatype::ENTITY,
                'error_class' => '\\core\\event\\user_login_failed', 'userid' => $u1],
        ];
        foreach ($records as $record) {
            $reason = null;
            $this->assertTrue(buffer::append_record($record, $reason), 'buffered, refusal: ' . var_export($reason, true));
        }
        // Close the file: erasure leaves a file a live writer still holds alone.
        buffer::flush();

        // One Collaborate attendance row each for U1 and U2.
        foreach ([$u1, $u2] as $userid) {
            $DB->insert_record('local_intellistream_colpart', (object)[
                'sessionuid'       => 'session-1',
                'useruid'          => 'collab-' . $userid,
                'external_user_id' => $userid,
                'display_name'     => 'Attendee ' . $userid,
                'first_join_time'  => 1700000000,
                'last_left_time'   => 1700003600,
                'duration'         => 3600,
                'rejoins'          => 0,
                'timecreated'      => time(),
            ]);
        }

        // Login totals: U1 7, U2 3, and a user id known only by its total.
        $DB->insert_record(userlogins::STATE, (object)['generation' => 5, 'timemodified' => time()]);
        foreach ([$u1 => 7, $u2 => 3, (int)$this->loginonly->id => 2] as $userid => $logins) {
            $DB->insert_record(userlogins::TABLE, (object)['userid' => $userid, 'logins' => $logins, 'mark' => 5]);
        }
    }

    /**
     * The stored login totals.
     *
     * @return array User id => logins.
     */
    private function logins(): array {
        global $DB;
        return array_map('intval', $DB->get_records_menu(userlogins::TABLE, null, 'userid', 'userid, logins'));
    }

    /**
     * The state row of the login count.
     *
     * @return \stdClass
     */
    private function ulstate(): \stdClass {
        global $DB;
        return $DB->get_record(userlogins::STATE, [], '*', MUST_EXIST);
    }

    /**
     * A short label for each staged record, to compare sets independent of order.
     *
     * @param array $records Decoded buffer records.
     * @return string[]
     */
    private function labels(array $records): array {
        $out = [];
        foreach ($records as $record) {
            $out[] = $record['record_type'] === 'entity_snapshot' ? $record['entity'] : $record['record_type'];
        }
        sort($out);
        return $out;
    }

    /**
     * A user with staged records is found in the system context; one without is not.
     */
    public function test_get_contexts_for_userid(): void {
        $system = \context_system::instance();

        $contexts = provider::get_contexts_for_userid((int)$this->u1->id)->get_contextids();
        $this->assertContains((int)$system->id, array_map('intval', $contexts));

        $this->assertSame([], provider::get_contexts_for_userid((int)$this->nobody->id)->get_contextids());

        $contexts = provider::get_contexts_for_userid((int)$this->loginonly->id)->get_contextids();
        $this->assertSame([(int)$system->id], array_map('intval', $contexts), 'found by the login total alone');
    }

    /**
     * Both parties of the message, and the grade's subject, are users in the system context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist(\context_system::instance(), 'local_intellistream');
        provider::get_users_in_context($userlist);
        $ids = array_map('intval', $userlist->get_userids());

        $this->assertContains((int)$this->u1->id, $ids);
        $this->assertContains((int)$this->u2->id, $ids);
        $this->assertContains((int)$this->loginonly->id, $ids, 'found by the login total alone');
    }

    /**
     * The colpart rows of a user.
     *
     * @param int $userid
     * @return int Number of rows.
     */
    private function attendance(int $userid): int {
        global $DB;
        return $DB->count_records('local_intellistream_colpart', ['external_user_id' => $userid]);
    }

    /**
     * A user whose only data is an attendance row is found in the system context.
     */
    public function test_get_contexts_for_userid_attendance_only(): void {
        global $DB;
        $attendee = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_intellistream_colpart', (object)[
            'sessionuid' => 'session-2', 'useruid' => 'collab-x', 'external_user_id' => (int)$attendee->id,
            'first_join_time' => 0, 'last_left_time' => 0, 'duration' => 0, 'rejoins' => 0, 'timecreated' => time(),
        ]);

        $contexts = provider::get_contexts_for_userid((int)$attendee->id)->get_contextids();
        $this->assertSame([(int)\context_system::instance()->id], array_map('intval', $contexts));

        $userlist = new userlist(\context_system::instance(), 'local_intellistream');
        provider::get_users_in_context($userlist);
        $this->assertContains((int)$attendee->id, array_map('intval', $userlist->get_userids()));
    }

    /**
     * The export holds U1's own row, the message, the event, the page dwell, the exception
     * and the attendance row, but not the grade U1 only modified.
     */
    public function test_export_user_data(): void {
        $system = \context_system::instance();
        $this->export_context_data_for_user((int)$this->u1->id, $system, 'local_intellistream');

        $writer = writer::with_context($system);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([
            get_string('pluginname', 'local_intellistream'),
            get_string('privacy:subcontext:buffer', 'local_intellistream'),
        ]);
        $this->assertNotEmpty($data);
        $this->assertSame(['event', 'exception', 'message', 'page_dwell', 'user'], $this->labels($data->records));

        $attendance = $writer->get_data([get_string('pluginname', 'local_intellistream')]);
        $this->assertCount(1, $attendance->attendance);
        $this->assertSame((int)$this->u1->id, (int)$attendance->attendance[0]->external_user_id);

        $logins = $writer->get_data([
            get_string('pluginname', 'local_intellistream'),
            get_string('privacy:subcontext:userlogins', 'local_intellistream'),
        ]);
        $this->assertSame(7, $logins->logins, "U1's own total only");
    }

    /**
     * Erasing U1 removes U1's row, the message and the event, and keeps the grade row.
     *
     * The message names both parties, so the whole line goes, and with it U2's copy:
     * a record is removed when it is about the subject, and it cannot be split.
     */
    public function test_delete_data_for_user(): void {
        $system = \context_system::instance();
        $contextlist = new approved_contextlist($this->u1, 'local_intellistream', [(int)$system->id]);

        provider::delete_data_for_user($contextlist);

        $this->assertSame([], buffer_privacy::user_records((int)$this->u1->id));
        $left = $this->labels(buffer_privacy::user_records((int)$this->u2->id));
        $this->assertSame(['grade_grades'], $left, 'only the grade row is left; the message went with U1');
        $this->assertSame(0, $this->attendance((int)$this->u1->id), "U1's attendance is gone");
        $this->assertSame(1, $this->attendance((int)$this->u2->id), "U2's attendance stays");
        $this->assertSame([], provider::get_contexts_for_userid((int)$this->u1->id)->get_contextids());
        $this->assertSame([(int)$this->u2->id => 3, (int)$this->loginonly->id => 2], $this->logins(), "U1's total is gone");
        $this->assertSame(6, (int)$this->ulstate()->generation, 'a flush in progress rolls back');
    }

    /**
     * Erasing a list of users through the userlist path does the same.
     */
    public function test_delete_data_for_users(): void {
        $system = \context_system::instance();
        $userlist = new approved_userlist($system, 'local_intellistream', [(int)$this->u2->id]);

        provider::delete_data_for_users($userlist);

        $this->assertSame([], buffer_privacy::user_records((int)$this->u2->id));
        $left = $this->labels(buffer_privacy::user_records((int)$this->u1->id));
        $this->assertSame(
            ['event', 'exception', 'page_dwell', 'user'],
            $left,
            'U1 keeps their own records; the message named U2 and is gone'
        );
        $this->assertSame(0, $this->attendance((int)$this->u2->id), "U2's attendance is gone");
        $this->assertSame(1, $this->attendance((int)$this->u1->id), "U1's attendance stays");
        $this->assertSame([(int)$this->u1->id => 7, (int)$this->loginonly->id => 2], $this->logins(), "U2's total is gone");
        $this->assertSame(6, (int)$this->ulstate()->generation, 'a flush in progress rolls back');
    }

    /**
     * Erasing everyone in the system context leaves no staged record about anyone.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame([], buffer_privacy::user_records((int)$this->u1->id));
        $this->assertSame([], buffer_privacy::user_records((int)$this->u2->id));
        $this->assertSame([], buffer_privacy::user_ids());
        $this->assertSame(0, $this->attendance((int)$this->u1->id) + $this->attendance((int)$this->u2->id));
        $this->assertSame([], $this->logins(), 'every total is gone');
        $st = $this->ulstate();
        $this->assertSame(6, (int)$st->generation, 'a flush in progress rolls back');
        $this->assertSame(1, (int)$st->resetreq, 'the task counts again from what the log holds');
    }

    /**
     * A tag instance belongs to its tagger, and a tag on a user's profile also to that
     * user; a tag on anything else does not belong to the user whose id equals the
     * item's id. Synthetic user ids, so no event the observer captured for a real user
     * can make an assertion pass.
     */
    public function test_tag_instance_matches_the_tagger_and_a_tagged_user(): void {
        $tagger = 990001;
        $tagged = 990002;
        $other = 990003;
        $records = [
            ['record_type' => 'entity_snapshot', 'entity' => 'tag_instance', 'entity_data' => ['id' => 701,
                'itemtype' => 'user', 'itemid' => $tagged, 'tiuserid' => $tagger]],
            ['record_type' => 'entity_snapshot', 'entity' => 'tag_instance', 'entity_data' => ['id' => 702,
                'itemtype' => 'course', 'itemid' => $other, 'tiuserid' => $tagger]],
        ];
        foreach ($records as $record) {
            $this->assertTrue(buffer::append_record($record));
        }
        buffer::flush();

        $ids = function (int $userid): array {
            $out = [];
            foreach (buffer_privacy::user_records($userid) as $record) {
                if (($record['entity'] ?? '') === 'tag_instance') {
                    $out[] = (int)$record['entity_data']['id'];
                }
            }
            sort($out);
            return $out;
        };
        $this->assertSame([701, 702], $ids($tagger), 'the tagger owns both tags');
        $this->assertSame([701], $ids($tagged), 'the tagged user owns the profile tag only');
        $this->assertSame([], $ids($other), 'a course tag is not about the user whose id equals the course id');

        // Erasing the tagged user removes the profile tag, and only that.
        buffer_privacy::delete_user_records([$tagged]);
        $this->assertSame([702], $ids($tagger));
        $this->assertSame([], $ids($tagged));
    }

    /**
     * A file this process is still writing is skipped by erasure but still exported;
     * once the file is closed, a second erasure removes the record.
     */
    public function test_erasure_skips_a_file_still_being_written(): void {
        $system = \context_system::instance();
        $userid = (int)$this->u1->id;
        $contextlist = new approved_contextlist($this->u1, 'local_intellistream', [(int)$system->id]);
        provider::delete_data_for_user($contextlist);
        $this->assertSame([], buffer_privacy::user_records($userid));

        // Appended and not flushed: the active file carries this process's PID.
        $this->assertTrue(buffer::append_record(
            ['record_type' => 'event', 'event_data' => ['eventname' => '\\core\\event\\course_viewed', 'userid' => $userid]]
        ));

        $this->export_context_data_for_user($userid, $system, 'local_intellistream');
        $data = writer::with_context($system)->get_data([
            get_string('pluginname', 'local_intellistream'),
            get_string('privacy:subcontext:buffer', 'local_intellistream'),
        ]);
        $this->assertSame(['event'], $this->labels($data->records), 'the active file is exported');

        provider::delete_data_for_user($contextlist);
        $this->assertSame(['event'], $this->labels(buffer_privacy::user_records($userid)), 'the active file is left alone');

        buffer::flush();
        provider::delete_data_for_user($contextlist);
        $this->assertSame([], buffer_privacy::user_records($userid), 'the closed file is erased');
    }
}
