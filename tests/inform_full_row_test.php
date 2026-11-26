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

use local_intellistream\repositories\config_repository;

/**
 * Tests for full-row export of the built-in entities (`informfullrow`).
 *
 * InForm reports built on IntelliData can select any column of a Moodle table, and
 * the curated column lists do not carry them all. With the setting on, a built-in
 * entity exports the whole row, minus credential columns.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\services\config_service::apply_overrides_to_registry
 */
final class inform_full_row_test extends \advanced_testcase {
    /**
     * The effective registry, rebuilt.
     *
     * @return array
     */
    private function registry(): array {
        exporter::reset_registry_cache();
        return exporter::registry_with_overrides();
    }

    /**
     * Column names of an entity in the effective registry.
     *
     * @param array $registry
     * @param string $entity
     * @return string[]
     */
    private function columns(array $registry, string $entity): array {
        return array_map('strtolower', preg_split('/\s*,\s*/', trim($registry[$entity]['columns'])));
    }

    /**
     * Off by default: the registry is exactly what it was.
     */
    public function test_off_changes_nothing(): void {
        $this->resetAfterTest();
        exporter::reset_registry_cache();
        $before = exporter::registry_with_overrides();
        set_config('informfullrow', 0, 'local_intellistream');
        $this->assertSame($before, $this->registry());
        $this->assertNotContains('intro', $this->columns($before, 'forum'));
    }

    /**
     * On, with no datatype config rows at all (most sites), a built-in exports its live columns.
     */
    public function test_on_widens_a_built_in_without_config_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertSame(0, $DB->count_records(config_repository::TABLE));
        set_config('informfullrow', 1, 'local_intellistream');

        // The forum table has no credential column, so it stays `*` (the whole live row).
        $this->assertSame('*', $this->registry()['forum']['columns']);
    }

    /**
     * Credential columns stay out of a widened entity.
     */
    public function test_on_keeps_credentials_out(): void {
        $this->resetAfterTest();
        set_config('informfullrow', 1, 'local_intellistream');
        $registry = $this->registry();
        $this->assertNotContains('password', $this->columns($registry, 'user'));
        $this->assertNotContains('secret', $this->columns($registry, 'user'));
        $this->assertContains('address', $this->columns($registry, 'user'));
    }

    /**
     * An administrator's own column list, the messaging entities and derived entities are left alone.
     */
    public function test_on_leaves_explicit_and_excluded_entities_alone(): void {
        $this->resetAfterTest();
        $repo = new config_repository();
        $repo->save('forum', (object)['enabled' => 1, 'custom_columns' => 'id, course, name']);
        $curated = exporter::registry();
        set_config('informfullrow', 1, 'local_intellistream');

        $registry = $this->registry();
        $this->assertSame(['id', 'course', 'name'], $this->columns($registry, 'forum'));
        foreach (exporter::MESSAGE_ENTITIES as $entity) {
            if (isset($registry[$entity])) {
                $this->assertNotSame('*', $registry[$entity]['columns'], $entity);
                $this->assertNotContains('fullmessage', $this->columns($registry, $entity), $entity);
            }
        }
        foreach ($curated as $entity => $def) {
            if (!empty($def['derived'])) {
                $this->assertSame($def, $registry[$entity], $entity);
            }
            if (strpos((string)($def['table'] ?? ''), 'local_') === 0 && isset($registry[$entity])) {
                $this->assertSame($def['columns'] ?? null, $registry[$entity]['columns'] ?? null, $entity);
            }
        }
    }
}
