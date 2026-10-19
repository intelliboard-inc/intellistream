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

/**
 * Tests for the privacy provider over records staged in the on-disk buffer.
 *
 * The buffer holds personal data until it ships, so a subject access request must
 * find a user's staged records and an erasure must remove them. Only the data
 * subject counts: a column naming who ACTED on a row (usermodified) must neither
 * put that row in the actor's export nor delete it on the actor's erasure.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\privacy\provider
 * @covers     \local_intellistream\buffer::user_records
 * @covers     \local_intellistream\buffer::delete_user_records
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The data subject. */
    private $u1;

    /** @var \stdClass The other party. */
    private $u2;

    /** @var \stdClass A user with nothing staged. */
    private $nobody;

    /**
     * Pair the site and stage four records: U1's user row, a message between U1 and U2,
     * a grade of U2's that U1 modified, and an event by U1.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();

        // Users first, pairing after: while unpaired the buffer refuses everything, so
        // the user_created events the observer sees here stage nothing.
        $this->u1 = $this->getDataGenerator()->create_user();
        $this->u2 = $this->getDataGenerator()->create_user();
        $this->nobody = $this->getDataGenerator()->create_user();
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
        ];
        foreach ($records as $record) {
            $reason = null;
            $this->assertTrue(buffer::append_record($record, $reason), 'buffered, refusal: ' . var_export($reason, true));
        }
        // Close the file: erasure leaves a file a live writer still holds alone.
        buffer::flush();
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
            $out[] = $record['record_type'] === 'event' ? 'event' : $record['entity'];
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
    }

    /**
     * The export holds U1's own row, the message and the event, but not the grade U1 only modified.
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
        $this->assertSame(['event', 'message', 'user'], $this->labels($data->records));
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

        $this->assertSame([], buffer::user_records((int)$this->u1->id));
        $left = $this->labels(buffer::user_records((int)$this->u2->id));
        $this->assertSame(['grade_grades'], $left, 'only the grade row is left; the message went with U1');
        $this->assertSame([], provider::get_contexts_for_userid((int)$this->u1->id)->get_contextids());
    }

    /**
     * Erasing a list of users through the userlist path does the same.
     */
    public function test_delete_data_for_users(): void {
        $system = \context_system::instance();
        $userlist = new approved_userlist($system, 'local_intellistream', [(int)$this->u2->id]);

        provider::delete_data_for_users($userlist);

        $this->assertSame([], buffer::user_records((int)$this->u2->id));
        $left = $this->labels(buffer::user_records((int)$this->u1->id));
        $this->assertSame(['event', 'user'], $left, 'U1 keeps their row and event; the message named U2 and is gone');
    }

    /**
     * Erasing everyone in the system context leaves no staged record about anyone.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame([], buffer::user_records((int)$this->u1->id));
        $this->assertSame([], buffer::user_records((int)$this->u2->id));
        $this->assertSame([], buffer::user_ids());
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
            foreach (buffer::user_records($userid) as $record) {
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
        buffer::delete_user_records([$tagged]);
        $this->assertSame([702], $ids($tagger));
        $this->assertSame([], $ids($tagged));
    }
}
