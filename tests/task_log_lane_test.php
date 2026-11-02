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
 * Tests for the one-minute task-log lane.
 *
 * The first pass sends this plugin's whole task history. Later passes send only runs
 * that finished after the mark, and read them through the plugin's task classes, which
 * `task_log` indexes.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\exporter::export_task_state
 */
final class task_log_lane_test extends \advanced_testcase {
    /**
     * Pair the site and start from an empty task log.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        set_config('siteid', 'phpunit-tasklog-site', 'local_intellistream');
        $DB->delete_records('task_log');
    }

    /**
     * Release this process's buffer handle, whatever a test left open.
     */
    protected function tearDown(): void {
        buffer::flush();
        parent::tearDown();
    }

    /**
     * Add one finished task run.
     *
     * @param string $component
     * @param string $classname As Moodle stores it, without a leading backslash.
     * @param float $timeend
     * @return int The row id.
     */
    private function add_run(string $component, string $classname, float $timeend): int {
        global $DB;
        return (int)$DB->insert_record('task_log', (object)[
            'type' => 0, 'component' => $component, 'classname' => $classname, 'userid' => 0,
            'timestart' => $timeend - 1, 'timeend' => $timeend, 'dbreads' => 1, 'dbwrites' => 1,
            'result' => 0, 'output' => 'ok', 'hostname' => 'phpunit', 'pid' => 1,
        ]);
    }

    /**
     * Run one pass of the lane and return the ids of the `task_log` rows it sent.
     *
     * @return int[] Ascending.
     */
    private function pass(): array {
        ob_start();
        try {
            exporter::export_task_state('phpunit-batch');
        } finally {
            buffer::flush();
            ob_end_clean();
        }
        $ids = [];
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (is_array($rec) && ($rec['entity'] ?? '') === 'task_log') {
                    $ids[] = (int)$rec['entity_data']['id'];
                }
            }
            unlink($path);
        }
        sort($ids);
        return $ids;
    }

    /**
     * The first pass sends the plugin's whole history, a removed task class included,
     * and nothing of another component. Later passes send the plugin's task classes
     * that finished after the mark, removed ones included, whatever their component.
     */
    public function test_first_pass_sends_history_then_only_plugin_classes(): void {
        $now = (float)time();
        $ship = $this->add_run('local_intellistream', 'local_intellistream\task\ship_events', $now - 300.5);
        $removed = $this->add_run('local_intellistream', 'local_intellistream\task\daily_snapshot', $now - 200.25);
        $this->add_run('core', 'core\task\session_cleanup_task', $now - 100);

        $this->assertSame([$ship, $removed], $this->pass(), 'the whole history of the plugin, nothing else');
        $this->assertSame([], $this->pass(), 'nothing new');

        $refresh = $this->add_run('local_intellistream', 'local_intellistream\task\refresh_entities', $now - 50);
        $late = $this->add_run('local_intellistream', 'local_intellistream\task\daily_snapshot', $now - 40);
        $this->add_run('core', 'core\task\session_cleanup_task', $now - 30);
        $blank = $this->add_run('', 'local_intellistream\task\ship_events', $now - 20);

        $this->assertSame(
            [$refresh, $late, $blank],
            $this->pass(),
            'the plugin task classes after the mark: a removed one, and one logged with no component'
        );
        $this->assertSame([], $this->pass());
    }
}
