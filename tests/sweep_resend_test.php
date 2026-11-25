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

namespace local_intellistream;

/**
 * Tests what the verification sweep sends again after a row was shortened or the columns widened.
 *
 * A shortened over-size row is fingerprinted from its unshortened data, so once its block
 * is confirmed the next pass sends nothing. Turning on full-row export changes every row
 * of a widened entity, so its blocks are all sent again.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\exporter::sweep_window
 */
final class sweep_resend_test extends \advanced_testcase {
    /**
     * Pair the site so records are buffered and fingerprints are kept.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        set_config('siteid', 'phpunit-resend-site', 'local_intellistream');
        set_config('endpoint', 'https://s3.example.com', 'local_intellistream');
        set_config('bucket', 'phpunit-bucket', 'local_intellistream');
        set_config('accesskey', 'phpunit-access', 'local_intellistream');
        set_config('secretkey', 'phpunit-secret', 'local_intellistream');
        exporter::reset_registry_cache();
    }

    /**
     * Release this process's buffer handle.
     */
    protected function tearDown(): void {
        buffer::flush();
        exporter::reset_registry_cache();
        parent::tearDown();
    }

    /**
     * Discard what the buffer holds, as a ship would.
     */
    private function ship(): void {
        buffer::flush();
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            unlink($path);
        }
    }

    /**
     * One sweep window over an entity's table.
     *
     * @param string $entity
     * @return array sweep_window() result.
     */
    private function sweep(string $entity): array {
        exporter::reset_registry_cache();
        $def = exporter::registry_with_overrides()[$entity];
        return exporter::sweep_window($entity, $def, 0, 1, 'phpunit-batch');
    }

    /**
     * Confirm a window's fingerprints as delivered.
     *
     * @param string $entity
     * @param array $window sweep_window() result.
     */
    private function confirm(string $entity, array $window): void {
        $start = time() - 60;
        change_ledger::stage($entity, $window['entries'], $start);
        $this->ship();
        $this->assertSame('promoted', change_ledger::settle($entity, $start, time()));
    }

    /**
     * A shortened row is not sent again once its block is confirmed.
     */
    public function test_shortened_row_is_not_resent(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => str_repeat('x', 1100000),
        ]);
        $this->ship();
        $this->expectOutputRegex('/was over the \d+-byte record cap; its largest text fields were shortened/');

        $first = $this->sweep('page');
        $this->assertSame(0, $first['dropped']);
        $this->assertGreaterThan(0, $first['sent']);
        $this->confirm('page', $first);

        $this->assertSame(0, $this->sweep('page')['sent']);
    }

    /**
     * Turning on full-row export sends a widened entity's confirmed blocks again.
     */
    public function test_full_row_export_resends_widened_blocks(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'intro' => 'Read me']);
        $this->ship();

        $first = $this->sweep('forum');
        $this->assertGreaterThan(0, $first['sent']);
        $this->confirm('forum', $first);
        $this->assertSame(0, $this->sweep('forum')['sent']);

        set_config('informfullrow', 1, 'local_intellistream');
        $this->assertGreaterThan(0, $this->sweep('forum')['sent']);
    }
}
