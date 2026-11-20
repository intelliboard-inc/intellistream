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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/intellistream/lib.php');

/**
 * Tests for the cleaning of dwell beacon fields.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_intellistream_dwell_clean_url
 * @covers     ::local_intellistream_dwell_clean_started_at
 * @covers     ::local_intellistream_dwell_clean_media_id
 */
final class dwell_input_test extends \basic_testcase {
    /**
     * Local URLs as the browser sends them.
     *
     * @return array
     */
    public static function kept_url_provider(): array {
        return [
            'root' => ['/'],
            'course with anchor' => ['/course/view.php?id=2#section-3'],
            'activity' => ['/mod/page/view.php?id=5'],
            'encoded file' => ['/pluginfile.php/12/mod_resource/content/1/My%20File.pdf'],
            'encoded unicode' => ['/course/view.php?name=caf%C3%A9'],
        ];
    }

    /**
     * Local URLs are kept unchanged.
     *
     * @dataProvider kept_url_provider
     * @param string $url
     */
    public function test_local_urls_are_kept(string $url): void {
        $this->assertSame($url, local_intellistream_dwell_clean_url($url));
    }

    /**
     * Values that are not local URLs.
     *
     * @return array
     */
    public static function emptied_url_provider(): array {
        return [
            'script' => ['/course/view.php?id=2<script>'],
            'raw space' => ['/course/view.php?id=2 x'],
            'external' => ['https://evil.example.com/page'],
            'javascript' => ['javascript:alert(1)'],
            'protocol-relative' => ['//evil.example.com/page'],
            'raw unicode' => ['/course/café'],
        ];
    }

    /**
     * Anything that is not a local URL is stored as ''.
     *
     * @dataProvider emptied_url_provider
     * @param string $url
     */
    public function test_other_values_are_emptied(string $url): void {
        $this->assertSame('', local_intellistream_dwell_clean_url($url));
    }

    /**
     * A long URL is cut to 2048 bytes after cleaning, so an escape at the cut does not empty it.
     */
    public function test_long_url_is_cut_after_cleaning(): void {
        $url = '/course/view.php?q=' . str_repeat('a', 2026) . '%C3%A9' . str_repeat('b', 100);
        $clean = local_intellistream_dwell_clean_url($url);
        $this->assertSame(2048, strlen($clean));
        $this->assertSame(substr($url, 0, 2048), $clean);
    }

    /**
     * Accepted and rejected started_at values.
     *
     * @return array
     */
    public static function started_at_provider(): array {
        return [
            'toISOString' => ['2026-06-06T09:38:11.787Z', true],
            'no fraction' => ['2026-06-06T09:38:11Z', true],
            'offset' => ['2026-06-06T09:38:11+05:30', true],
            'no zone' => ['2026-06-06T09:38:11', false],
            'trailing newline' => ["2026-06-06T09:38:11.787Z\n", false],
            'suffix' => ['2026-06-06T09:38:11Z<script>', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Only a whole timestamp is accepted; anything else returns null.
     *
     * @dataProvider started_at_provider
     * @param string $value
     * @param bool $accepted
     */
    public function test_started_at(string $value, bool $accepted): void {
        $this->assertSame($accepted ? $value : null, local_intellistream_dwell_clean_started_at($value));
    }

    /**
     * Markup is removed from the media id; an element id or a URL is kept.
     */
    public function test_media_id_markup_is_removed(): void {
        $this->assertSame('video-1', local_intellistream_dwell_clean_media_id('video-1'));
        $this->assertSame('blob:https://site.example/1', local_intellistream_dwell_clean_media_id('blob:https://site.example/1'));
        $this->assertSame('video', local_intellistream_dwell_clean_media_id('<b>video</b>'));
    }

    /**
     * A long media id is cut to 255 bytes without splitting a character.
     */
    public function test_media_id_is_cut_on_a_character_boundary(): void {
        $clean = local_intellistream_dwell_clean_media_id(str_repeat('a', 254) . 'é' . 'b');
        $this->assertSame(str_repeat('a', 254), $clean);
        $this->assertSame(str_repeat('c', 255), local_intellistream_dwell_clean_media_id(str_repeat('c', 300)));
    }
}
