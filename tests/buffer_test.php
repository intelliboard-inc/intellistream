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
 * Tests for the buffer's filename parser.
 *
 * parse_name() decides which process and which host owns a buffer file, so a
 * misparse either strands a file (its records never ship) or lets one node collect
 * a file another node is still appending to. It is pure, so it is testable without
 * a database or a filesystem — which is exactly why it is tested here.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\buffer::parse_name
 * @covers     \local_intellistream\buffer::entry_kind
 * @covers     \local_intellistream\buffer::keep_oldest
 * @covers     \local_intellistream\buffer::oldest_first
 */
final class buffer_test extends \basic_testcase {
    /**
     * The current four-segment form, with a host stamp.
     */
    public function test_parses_current_form(): void {
        $parsed = buffer::parse_name('events-1234-1785801600000000-6ba907de-h9f8e7d6.jsonl');

        $this->assertNotNull($parsed);
        $this->assertSame(1234, $parsed['pid']);
        $this->assertSame(1785801600000000, $parsed['created_us']);
        $this->assertSame('6ba907de', $parsed['token']);
        $this->assertSame('9f8e7d6', $parsed['hostid']);
    }

    /**
     * A full path parses the same as a bare basename.
     */
    public function test_accepts_a_full_path(): void {
        $bare = buffer::parse_name('events-1-2-abc-hff.jsonl');
        $full = buffer::parse_name('/var/moodledata/intellistream/buffer/events-1-2-abc-hff.jsonl');

        $this->assertNotNull($full);
        $this->assertSame($bare, $full);
    }

    /**
     * Both legacy forms must keep parsing. A file already on disk when the plugin
     * upgrades is mid-flight data, and failing to parse its name strands it.
     */
    public function test_parses_legacy_forms(): void {
        $nohost = buffer::parse_name('events-42-1785801600000000-6ba907de.jsonl');
        $this->assertNotNull($nohost);
        $this->assertSame('6ba907de', $nohost['token']);
        $this->assertNull($nohost['hostid'], 'a legacy name has no host component');

        $tokenless = buffer::parse_name('events-42-1785801600000000.jsonl');
        $this->assertNotNull($tokenless);
        $this->assertNull($tokenless['token']);
        $this->assertNull($tokenless['hostid']);
    }

    /**
     * The regression the `h` prefix exists to prevent.
     *
     * The process token is dechex() of the process start time, which at current
     * uptimes is ITSELF eight hex characters — the same width as a host id. A
     * width-based rule would therefore read this legacy three-segment name as a
     * host-stamped one and conclude the file belongs to another node, which on a
     * single-node site means it is never collected. `h` cannot occur in a hex
     * token, so the forms stay distinguishable whatever the token's length.
     */
    public function test_eight_char_token_is_not_mistaken_for_a_host_id(): void {
        $parsed = buffer::parse_name('events-1234-1785801600000000-303402d3.jsonl');

        $this->assertNotNull($parsed);
        $this->assertSame('303402d3', $parsed['token']);
        $this->assertNull($parsed['hostid']);
    }

    /**
     * Every lifecycle suffix parses, so a caller can identify a file at any stage.
     */
    public function test_parses_lifecycle_suffixes(): void {
        foreach (['', '.closed', '.pulled', '.closed' . buffer::PARKED_SUFFIX] as $suffix) {
            $parsed = buffer::parse_name('events-7-1785801600000000-abc-hdef.jsonl' . $suffix);
            $this->assertNotNull($parsed, 'suffix "' . $suffix . '" should parse');
            $this->assertSame(7, $parsed['pid']);
        }
    }

    /**
     * Anything that is not one of ours returns null rather than a partial parse.
     *
     * This is what keeps the plugin from deleting or rewriting a file it does not
     * own — buffer::purge_all() and the privacy delete paths both gate on it.
     */
    public function test_rejects_foreign_names(): void {
        $foreign = [
            'events-1-2-abc-hdef.jsonl.gz', // Wrong suffix.
            'events-1-2-abc-hdef.txt', // Wrong extension.
            'other-1-2.jsonl', // Wrong stem.
            'events-abc-2.jsonl', // Non-numeric pid.
            'events-1.jsonl', // Missing timestamp.
            'events-1-2-abc-hdef.jsonl.rewrite-99', // Temp file mid-rewrite.
            'prefix-events-1-2.jsonl', // Anchored at the start.
            '',
        ];
        foreach ($foreign as $name) {
            $this->assertNull(buffer::parse_name($name), '"' . $name . '" must not parse');
        }
    }

    /**
     * The one classifier every directory scan uses must agree with the globs it
     * replaced: a lifecycle name by its suffix, a rewrite or bookkeeping temporary
     * as residue, the bookkeeping dot-files by name, any other `.jsonl` name as
     * 'other' (what unsafe_files() reports when it is not a plain file), and every
     * other dot-file as nothing at all, as glob()'s `*` never matches one.
     */
    public function test_entry_kind_classifies_every_buffer_name(): void {
        $host = 'h0011aabb';
        $cases = [
            'events-1-2-abc-' . $host . '.jsonl' => 'active',
            'events-1-2-abc-' . $host . '.jsonl.closed' => 'closed',
            'events-1-2-abc-' . $host . '.jsonl.pulled' => 'pulled',
            'events-1-2-abc-' . $host . '.jsonl.closed' . buffer::PARKED_SUFFIX => 'parked',
            'foreign.jsonl.closed' => 'closed',
            'events-1-2-abc-' . $host . '.jsonl.closed.rewrite-9-aabbccddeeff' => 'residue',
            'events-1-2-abc-' . $host . '.jsonl.privacy-9-aabbccddeeff.tmp' => 'residue',
            buffer::CAPACITY_FILE => 'state',
            buffer::CAPACITY_LOCK => 'lock',
            buffer::POINTER_PREFIX . '12-abc-' . $host => 'pointer',
            buffer::CAPACITY_FILE . '.tmp-0011aabb-9-aabbccddeeff' => 'residue',
            buffer::CAPACITY_LOCK . '.tmp-0011aabb-9-aabbccddeeff' => 'residue',
            buffer::POINTER_PREFIX . '12-abc-' . $host . '.tmp-0011aabb-9-aabbccddeeff' => 'residue',
            'notes.jsonl.bak' => 'other',
            '.hidden.jsonl' => null,
            '.w-notapointer' => null,
            'readme.txt' => null,
            '' => null,
        ];
        foreach ($cases as $name => $kind) {
            $this->assertSame($kind, buffer::entry_kind((string)$name), '"' . $name . '"');
        }
    }

    /**
     * The bounded selection keeps exactly the oldest entries, by mtime then path,
     * without ever holding more than twice what it keeps.
     */
    public function test_keep_oldest_keeps_the_oldest_in_bounded_memory(): void {
        $window = [];
        $max = 0;
        for ($i = 500; $i >= 1; $i--) {
            buffer::keep_oldest($window, [$i % 50, 'p' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), $i], 10);
            $max = max($max, count($window));
        }
        $this->assertLessThanOrEqual(20, $max);
        $oldest = buffer::oldest_first($window, 10);
        $this->assertCount(10, $oldest);
        // Ten entries have mtime 0 (i = 50, 100, ... 500): all of them, by path.
        $this->assertSame(
            ['p050', 'p100', 'p150', 'p200', 'p250', 'p300', 'p350', 'p400', 'p450', 'p500'],
            array_column($oldest, 1)
        );
    }
}
