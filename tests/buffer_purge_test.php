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

use local_intellistream\local\buffer_purge;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\local_intellistream\local\buffer_purge::class)]
/**
 * Tests for the uninstall purge of the buffer directory.
 *
 * The purge removes only the plugin's own files, never follows a link out of the
 * buffer directory, and removes the directory only when nothing else is in it.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\local\buffer_purge
 */
final class buffer_purge_test extends \advanced_testcase {
    /** @var string */
    private $dir;

    /** @var string[] One file for each kind of buffer file the plugin writes. */
    private const OWN_FILES = [
        'events-1-1700000100000001-2a0aa299-h00000000.jsonl',
        'events-1-1700000100000002-2a0aa299-h00000000.jsonl.closed',
        'events-1-1700000100000003-2a0aa299-h00000000.jsonl.pulled',
        'events-1-1700000100000004-2a0aa299-h00000000.jsonl.closed.parked',
    ];

    /**
     * Create the buffer directory with one file of each own kind.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        buffer::flush();
        $this->dir = config::buffer_dir();
        $this->assertNotFalse(make_writable_directory($this->dir));
        foreach (self::OWN_FILES as $name) {
            file_put_contents($this->dir . '/' . $name, '{"x":1}' . "\n");
        }
        clearstatcache();
    }

    /**
     * With only the plugin's files in it, the directory is removed.
     */
    public function test_removes_own_files_and_the_empty_directory(): void {
        $result = buffer_purge::purge_all();

        $this->assertGreaterThanOrEqual(count(self::OWN_FILES), $result['files']);
        $this->assertGreaterThanOrEqual(1, $result['dirs']);
        clearstatcache();
        $this->assertFalse(is_dir($this->dir));
    }

    /**
     * A file that is not the plugin's stays, and so does the directory.
     */
    public function test_keeps_other_files_and_the_directory(): void {
        file_put_contents($this->dir . '/notes.txt', 'not ours');

        buffer_purge::purge_all();

        clearstatcache();
        foreach (self::OWN_FILES as $name) {
            $this->assertFalse(file_exists($this->dir . '/' . $name));
        }
        $this->assertTrue(file_exists($this->dir . '/notes.txt'));
        $this->assertTrue(is_dir($this->dir));
    }

    /**
     * A link named like a buffer file is removed; the file it points to is not.
     */
    public function test_removes_a_link_but_not_its_target(): void {
        $outside = make_request_directory() . '/outside.jsonl';
        file_put_contents($outside, 'keep');
        $link = $this->dir . '/events-1-1700000100000009-2a0aa299-h00000000.jsonl.closed';
        if (!@symlink($outside, $link)) {
            $this->markTestSkipped('This file system does not allow symbolic links.');
        }

        buffer_purge::purge_all();

        clearstatcache();
        $this->assertFalse(is_link($link));
        $this->assertSame('keep', file_get_contents($outside));
    }
}
