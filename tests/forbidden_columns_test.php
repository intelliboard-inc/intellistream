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
 * Tests that meeting, attendance and certificate secrets never reach an export.
 *
 * The curated column lists already leave these columns out, so a site exporting
 * the registry defaults ships none of them. They must also stay out when a table
 * is exported whole (`*`) or an administrator lists columns by hand, which is why
 * the filter, not the curated list, has to know them.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\exporter::strip_forbidden_columns
 */
final class forbidden_columns_test extends \advanced_testcase {
    /** Secret columns that must never be exported, by table. */
    private const SECRETS = [
        'bigbluebuttonbn' => ['moderatorpass', 'viewerpass', 'guestpassword', 'guestlinkuid'],
        'attendance_sessions' => ['studentpassword', 'rotateqrcodesecret'],
        'customcert_issues' => ['code'],
        'collaborate' => ['guesturl'],
        'lti' => ['instructorcustomparameters'],
    ];

    /**
     * Run the protected registry filter.
     *
     * @param array $registry Registry entries to filter.
     * @return array The filtered registry.
     */
    private function strip(array $registry): array {
        $method = new \ReflectionMethod(exporter::class, 'strip_forbidden_columns');
        $method->setAccessible(true);
        return $method->invoke(null, $registry);
    }

    /**
     * Split a filtered column list into lower-case names.
     *
     * @param string $columns Comma-separated column list.
     * @return string[]
     */
    private function names(string $columns): array {
        return array_map('strtolower', preg_split('/\s*,\s*/', trim($columns)));
    }

    /**
     * A hand-written column list naming the secrets keeps every other column.
     */
    public function test_explicit_list_drops_the_secrets(): void {
        $this->resetAfterTest();
        foreach (self::SECRETS as $table => $secrets) {
            $out = $this->strip([
                'fixture' => ['table' => $table, 'columns' => 'id, name, ' . implode(', ', $secrets)],
            ]);
            $this->assertSame(['id', 'name'], $this->names($out['fixture']['columns']), $table);
        }
    }

    /**
     * `code` is a secret on certificate issues only; on any other table it is data.
     */
    public function test_certificate_code_is_scoped_to_its_table(): void {
        $this->resetAfterTest();
        $out = $this->strip([
            'fixture' => ['table' => 'local_intellistream_fixture', 'columns' => 'id, code'],
        ]);
        $this->assertSame(['id', 'code'], $this->names($out['fixture']['columns']));
    }

    /**
     * A whole-row export of the meeting table ships no secret column.
     */
    public function test_whole_row_meeting_export_drops_the_secrets(): void {
        global $DB;
        $this->resetAfterTest();
        if (!$DB->get_manager()->table_exists('bigbluebuttonbn')) {
            $this->markTestSkipped('mod_bigbluebuttonbn is not installed');
        }
        $out = $this->strip(['fixture' => ['table' => 'bigbluebuttonbn', 'columns' => '*']]);
        $names = $this->names($out['fixture']['columns']);
        $this->assertContains('id', $names);
        foreach (self::SECRETS['bigbluebuttonbn'] as $secret) {
            $this->assertNotContains($secret, $names);
        }
    }

    /**
     * The curated lists of these tables are unchanged, so existing exports are too.
     */
    public function test_curated_lists_are_unchanged(): void {
        $this->resetAfterTest();
        $registry = exporter::registry();
        $curated = array_intersect_key($registry, self::SECRETS);
        $this->assertCount(count(self::SECRETS), $curated);
        $this->assertSame($curated, array_intersect_key($this->strip($curated), self::SECRETS));
    }
}
