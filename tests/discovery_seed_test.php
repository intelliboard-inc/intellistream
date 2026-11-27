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
use local_intellistream\services\config_service;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\local_intellistream\services\config_service::class)]
/**
 * Tests for the upgrade-time seeding of the discovered-table export setting.
 *
 * Whole-row export of discovered tables is opt-in. The upgrade decides the
 * setting once, from the version a site upgrades from, so that nothing a site
 * ships stops without a notice and export is never switched on for a site that
 * did not have it. A wrong answer here either silently stops a site's data or
 * silently starts exporting whole tables nobody chose to export.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\services\config_service::seed_discovery_export
 */
final class discovery_seed_test extends \advanced_testcase {
    /** Newest version that seeds the setting off. */
    private const V0927 = 2026080313;

    /** A version above V0927 and below V0933: seeded from the registry. */
    private const V0930 = 2026092600;

    /** First version with the opt-in setting: the upgrade decides nothing. */
    private const V0933 = 2026100100;

    /** The first build with the `discovered` column. */
    private const VDISCOVERED = 2026062200;

    /**
     * Start every test with no stored value.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        unset_config('dynamicdiscoveryexport', 'local_intellistream');
    }

    /**
     * Register one datatype row.
     *
     * @param string $datatype
     * @param int $discovered
     * @param int $enabled
     * @return void
     */
    private function add_datatype(string $datatype, int $discovered, int $enabled): void {
        global $DB;
        $DB->insert_record(config_repository::TABLE, (object)[
            'datatype' => $datatype,
            'enabled' => $enabled,
            'discovered' => $discovered,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * The stored setting, false when there is none.
     *
     * @return mixed
     */
    private function stored() {
        return get_config('local_intellistream', 'dynamicdiscoveryexport');
    }

    /**
     * A value already stored is kept, whatever the version and the registry say.
     */
    public function test_a_stored_value_is_kept(): void {
        $this->add_datatype('mod_foo_bar', 1, 1);

        foreach ([self::V0927, self::V0930] as $old) {
            set_config('dynamicdiscoveryexport', 0, 'local_intellistream');
            $this->assertSame(0, config_service::seed_discovery_export($old));
            $this->assertSame('0', $this->stored());

            set_config('dynamicdiscoveryexport', 1, 'local_intellistream');
            $this->assertSame(1, config_service::seed_discovery_export($old));
            $this->assertSame('1', $this->stored());
        }
    }

    /**
     * A site upgrading from V0927 or older with no stored value starts off.
     */
    public function test_0927_and_earlier_start_off(): void {
        $this->assertSame(0, config_service::seed_discovery_export(self::V0927));
        $this->assertSame('0', $this->stored());

        unset_config('dynamicdiscoveryexport', 'local_intellistream');
        $this->assertSame(0, config_service::seed_discovery_export(self::VDISCOVERED - 1));
        $this->assertSame('0', $this->stored());
    }

    /**
     * Off even when a discovered table was enabled: export is never switched on
     * without an administrator's choice. The upgrade says how many stop.
     */
    public function test_0927_with_an_enabled_discovered_table_still_starts_off(): void {
        $this->add_datatype('mod_foo_bar', 1, 1);
        $this->add_datatype('mod_foo_baz', 1, 1);

        $this->expectOutputRegex('/2 discovered table\(s\) will no longer be exported/');
        $this->assertSame(0, config_service::seed_discovery_export(self::V0927));
        $this->assertSame('0', $this->stored());
    }

    /**
     * The notice counts only tables that stop: a built-in entity keeps exporting its
     * curated columns, so a discovered row for one is not counted.
     */
    public function test_0927_notice_leaves_out_built_ins(): void {
        $this->assertArrayHasKey('course', exporter::registry(), 'fixture must be a built-in');
        $this->add_datatype('course', 1, 1);
        $this->add_datatype('mod_foo_bar', 1, 1);

        $this->expectOutputRegex('/^1 discovered table\(s\) will no longer be exported/');
        $this->assertSame(0, config_service::seed_discovery_export(self::V0927));
    }

    /**
     * Only built-ins discovered: nothing stops, so nothing is said.
     */
    public function test_0927_with_only_built_ins_says_nothing(): void {
        $this->add_datatype('course', 1, 1);

        $this->expectOutputString('');
        $this->assertSame(0, config_service::seed_discovery_export(self::V0927));
    }

    /**
     * Versions between V0927 and V0933 exported discovered tables without the
     * setting: it starts on only if a discovered table is enabled for export.
     */
    public function test_0928_to_0932_follow_the_registry(): void {
        $this->assertSame(0, config_service::seed_discovery_export(self::V0930), 'nothing discovered');
        $this->assertSame('0', $this->stored());

        unset_config('dynamicdiscoveryexport', 'local_intellistream');
        $this->add_datatype('mod_foo_off', 1, 0);
        $this->add_datatype('handmade', 0, 1);
        $this->assertSame(
            0,
            config_service::seed_discovery_export(self::V0930),
            'a disabled discovered row and an enabled hand-added one do not count'
        );

        unset_config('dynamicdiscoveryexport', 'local_intellistream');
        $this->add_datatype('mod_foo_on', 1, 1);
        $this->assertSame(1, config_service::seed_discovery_export(self::V0930), 'an enabled discovered row');
        $this->assertSame('1', $this->stored());

        unset_config('dynamicdiscoveryexport', 'local_intellistream');
        $this->assertSame(1, config_service::seed_discovery_export(self::V0933 - 1), 'the last build before');
    }

    /**
     * A site already on V0933 or later decides nothing and writes nothing.
     */
    public function test_0933_and_later_write_nothing(): void {
        $this->add_datatype('mod_foo_bar', 1, 1);

        $this->assertSame(0, config_service::seed_discovery_export(self::V0933));
        $this->assertFalse($this->stored(), 'no value was written');

        set_config('dynamicdiscoveryexport', 1, 'local_intellistream');
        $this->assertSame(1, config_service::seed_discovery_export(self::V0933 + 1));
        $this->assertSame('1', $this->stored());
    }

    /**
     * Between V0927 and V0933, an enabled discovered built-in alone turns the switch
     * on: those versions exported it whole-row, so that behaviour is kept (which also
     * opens tables discovered later; the README says so).
     */
    public function test_0928_to_0932_count_an_enabled_discovered_built_in(): void {
        $this->add_datatype('course', 1, 1);
        unset_config('dynamicdiscoveryexport', 'local_intellistream');
        $this->assertSame(1, config_service::seed_discovery_export(self::V0930));
        $this->assertSame('1', $this->stored());
    }
}
