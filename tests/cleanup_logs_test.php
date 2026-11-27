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

#[CoversClass(\local_intellistream\task\cleanup_logs::class)]
/**
 * Tests for the log cleanup task.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\task\cleanup_logs
 */
final class cleanup_logs_test extends \advanced_testcase {
    /**
     * Add one log row.
     *
     * @param int $timecreated
     * @return int Row id.
     */
    private function add_log(int $timecreated): int {
        global $DB;
        return (int)$DB->insert_record('local_intellistream_logs', (object)[
            'type'        => 'config',
            'datatype'    => '',
            'action'      => 'save',
            'details'     => '{}',
            'timecreated' => $timecreated,
        ]);
    }

    /**
     * Run the task and drop its output.
     *
     * @return void
     */
    private function run_task(): void {
        ob_start();
        (new \local_intellistream\task\cleanup_logs())->execute();
        ob_end_clean();
    }

    /**
     * Rows older than the lifetime go, including rows with timecreated 0; newer rows stay.
     */
    public function test_deletes_rows_older_than_lifetime(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('loglifetime', 90, 'local_intellistream');

        $now = time();
        $this->add_log(0);
        $this->add_log($now - 400 * DAYSECS);
        $this->add_log($now - 91 * DAYSECS);
        $kept1 = $this->add_log($now - 89 * DAYSECS);
        $kept2 = $this->add_log($now);

        $this->run_task();

        $ids = array_map('intval', $DB->get_fieldset_select('local_intellistream_logs', 'id', '1 = 1'));
        sort($ids);
        $this->assertSame([$kept1, $kept2], $ids);
    }

    /**
     * One batch never reaches past the cutoff: a row less than a day newer than the
     * oldest row, but still inside the lifetime, is kept.
     */
    public function test_batch_stops_at_the_cutoff(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('loglifetime', 90, 'local_intellistream');

        $now = time();
        $this->add_log($now - (int)(90.5 * DAYSECS));
        // Without the cutoff cap, the first batch would delete everything older than
        // 89.5 days, and so this row too.
        $kept = $this->add_log($now - (int)(89.6 * DAYSECS));

        $this->run_task();

        $this->assertSame([$kept], array_map('intval', $DB->get_fieldset_select('local_intellistream_logs', 'id', '1 = 1')));
    }

    /**
     * A lifetime of 0 keeps every row.
     */
    public function test_lifetime_zero_keeps_everything(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('loglifetime', 0, 'local_intellistream');

        $this->add_log(0);
        $this->add_log(time() - 5000 * DAYSECS);

        $this->run_task();

        $this->assertSame(2, $DB->count_records('local_intellistream_logs'));
    }

    /**
     * With no stored value the default of 90 days applies.
     */
    public function test_default_lifetime_is_90_days(): void {
        global $DB;
        $this->resetAfterTest();
        unset_config('loglifetime', 'local_intellistream');

        $this->add_log(time() - 91 * DAYSECS);
        $kept = $this->add_log(time() - 89 * DAYSECS);

        $this->run_task();

        $this->assertSame([$kept], array_map('intval', $DB->get_fieldset_select('local_intellistream_logs', 'id', '1 = 1')));
    }

    /**
     * The control webhook can read the lifetime but cannot change it.
     */
    public function test_lifetime_is_not_writable_over_the_webhook(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse(\local_intellistream\services\plugin_report::is_writable('loglifetime'));
        // A plain setting on the same page stays writable, so the settings page was read.
        $this->assertTrue(\local_intellistream\services\plugin_report::is_writable('exportbatchsize'));
    }
}
