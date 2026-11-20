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
 * Tests that a row over the record cap is exported shortened, not lost.
 *
 * The buffer refuses a record over buffer::MAX_EVENT_BYTES for good. A row with one
 * huge text field used to vanish from every report; now its largest text fields are
 * shortened to the width the warehouse keeps, and the row is exported.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\exporter::shrink_to_fit
 */
final class oversize_record_test extends \advanced_testcase {
    /**
     * Pair the site so records are buffered.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        set_config('siteid', 'phpunit-oversize-site', 'local_intellistream');
        set_config('endpoint', 'https://s3.example.com', 'local_intellistream');
        set_config('bucket', 'phpunit-bucket', 'local_intellistream');
        set_config('accesskey', 'phpunit-access', 'local_intellistream');
        set_config('secretkey', 'phpunit-secret', 'local_intellistream');
    }

    /**
     * Release this process's buffer handle.
     */
    protected function tearDown(): void {
        buffer::flush();
        parent::tearDown();
    }

    /**
     * Run the private shrink on a record.
     *
     * @param array $data entity_data of the record.
     * @return array [changed, record after]
     */
    private function shrink(array $data): array {
        $payload = ['id' => 'x', 'record_type' => 'entity_snapshot', 'entity' => 'page', 'entity_data' => $data];
        $method = new \ReflectionMethod(exporter::class, 'shrink_to_fit');
        $method->setAccessible(true);
        $changed = $method->invokeArgs(null, [&$payload]);
        return [$changed, $payload];
    }

    /**
     * Records buffered for an entity, consuming the files.
     *
     * @param string $entity
     * @return array[] entity_data of each record.
     */
    private function buffered(string $entity): array {
        buffer::flush();
        $out = [];
        foreach (glob(config::buffer_dir() . '/events-*.jsonl*') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (is_array($rec) && ($rec['entity'] ?? '') === $entity) {
                    $out[] = $rec['entity_data'];
                }
            }
            unlink($path);
        }
        return $out;
    }

    /**
     * A record that fits is left exactly as it is.
     */
    public function test_a_record_that_fits_is_unchanged(): void {
        $data = ['id' => 1, 'content' => str_repeat('a', 60000)];
        [$changed, $payload] = $this->shrink($data);
        $this->assertFalse($changed);
        $this->assertSame($data, $payload['entity_data']);
    }

    /**
     * The largest text field is cut to SHRINK_FIELD_CHARS, counted in characters.
     */
    public function test_largest_text_is_cut_to_the_shrink_width(): void {
        $data = ['id' => 1, 'name' => 'Page', 'content' => str_repeat('😀', 300000)];
        [$changed, $payload] = $this->shrink($data);
        $this->assertTrue($changed);
        $this->assertSame(exporter::SHRINK_FIELD_CHARS, \core_text::strlen($payload['entity_data']['content']));
        $this->assertSame('Page', $payload['entity_data']['name']);
        $this->assertLessThanOrEqual(buffer::MAX_EVENT_BYTES, strlen(json_encode($payload, buffer::JSON_FLAGS)));
        $this->assertSame($payload, $this->shrink($data)[1], 'the same row always shrinks the same way');
    }

    /**
     * A JSON value is emptied, never cut into invalid JSON.
     */
    public function test_json_text_is_emptied_not_cut(): void {
        $json = "\n  " . json_encode(['blob' => str_repeat('b', 1100000)], JSON_PRETTY_PRINT) . "\n";
        [$changed, $payload] = $this->shrink(['id' => 1, 'configdata' => $json, 'name' => 'x']);
        $this->assertTrue($changed);
        $this->assertNull($payload['entity_data']['configdata']);
        $this->assertSame('x', $payload['entity_data']['name']);
    }

    /**
     * Text wrapped in BBCode brackets is plain text: it is cut, not emptied.
     */
    public function test_bbcode_is_cut_not_emptied(): void {
        foreach (['quote', 'table', 'font=Arial', 'td', 'nobbc'] as $tag) {
            $text = "[{$tag}]" . str_repeat('q', 1100000) . '[/' . strtok($tag, '=') . ']';
            [$changed, $payload] = $this->shrink(['id' => 1, 'message' => $text]);
            $this->assertTrue($changed, $tag);
            $this->assertSame(exporter::SHRINK_FIELD_CHARS, strlen($payload['entity_data']['message']), $tag);
        }
        foreach (['[true, ' . str_repeat('1,', 600000) . '1]', '[null' . str_repeat(',0', 600000) . ']'] as $json) {
            $this->assertNull($this->shrink(['id' => 1, 'data' => $json])[1]['entity_data']['data']);
        }
    }

    /**
     * With several large fields, only as many are cut as the record needs.
     */
    public function test_only_what_is_needed_is_cut(): void {
        $intro = str_repeat('a', 600000);
        [$changed, $payload] = $this->shrink(['id' => 1, 'intro' => $intro, 'content' => str_repeat('b', 700000)]);
        $this->assertTrue($changed);
        $this->assertSame(exporter::SHRINK_FIELD_CHARS, strlen($payload['entity_data']['content']));
        $this->assertSame($intro, $payload['entity_data']['intro']);
    }

    /**
     * When cutting is not enough, fields are emptied, but never a key or a number.
     */
    public function test_second_pass_empties_text_and_keeps_keys(): void {
        $data = ['id' => 7, 'course' => '12', 'timemodified' => '1790000000'];
        for ($i = 1; $i <= 25; $i++) {
            $data['f' . $i] = str_repeat('😀', 60000);
        }
        [$changed, $payload] = $this->shrink($data);
        $out = $payload['entity_data'];
        $this->assertTrue($changed);
        $this->assertLessThanOrEqual(buffer::MAX_EVENT_BYTES, strlen(json_encode($payload, buffer::JSON_FLAGS)));
        $this->assertSame([7, '12', '1790000000'], [$out['id'], $out['course'], $out['timemodified']]);
        $lengths = array_map(function ($v) {
            return $v === null ? null : \core_text::strlen($v);
        }, array_slice($out, 3));
        $this->assertContains(null, $lengths, 'the second pass emptied some fields');
        $this->assertContains(exporter::SHRINK_FIELD_CHARS, $lengths, 'the rest stay cut, not emptied');
    }

    /**
     * A record with nothing that may be shortened is left alone.
     */
    public function test_numbers_are_never_shortened(): void {
        $data = ['id' => 1, 'big' => str_repeat('9', 1100000)];
        [$changed, $payload] = $this->shrink($data);
        $this->assertFalse($changed);
        $this->assertSame($data, $payload['entity_data']);
    }

    /**
     * Through the real capture path: a page over the cap is exported, with its content shortened.
     */
    public function test_an_over_size_page_is_exported(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => str_repeat('x', 1100000),
        ]);
        $this->buffered('page');
        $this->expectOutputRegex('/page — row id ' . $page->id . ' was over the \\d+-byte record cap/');

        exporter::capture_entity_match('page', ['id' => $page->id]);

        $rows = $this->buffered('page');
        $this->assertCount(1, $rows);
        $this->assertSame((int)$page->id, (int)$rows[0]['id']);
        $this->assertSame(exporter::SHRINK_FIELD_CHARS, \core_text::strlen($rows[0]['content']));
        $this->assertTrue($DB->record_exists('local_intellistream_logs', ['datatype' => 'page', 'action' => 'shortened']));
    }

    /**
     * A row that cannot be made to fit is not exported, and that is recorded.
     */
    public function test_a_row_that_cannot_fit_is_reported(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => str_repeat('9', 1100000),
        ]);
        $this->buffered('page');
        $this->expectOutputRegex('/could not be shortened to fit, and it was not exported/');

        exporter::capture_entity_match('page', ['id' => $page->id]);

        $this->assertSame([], $this->buffered('page'));
        $this->assertTrue($DB->record_exists('local_intellistream_logs', ['datatype' => 'page', 'action' => 'oversize']));
    }
}
