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
 * Tests that the LTI role setting loads its role list only when it needs it.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\admin\admin_setting_ltirole
 */
final class admin_setting_ltirole_test extends \advanced_testcase {
    /**
     * Include settings.php the way the admin tree does and return the ibnltirole setting.
     *
     * @return \local_intellistream\admin\admin_setting_ltirole
     */
    private function setting_from_settings_php(): \local_intellistream\admin\admin_setting_ltirole {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $hassiteconfig = true;
        $settings = null;
        $ADMIN = new class {
            /**
             * Ignore node registrations from settings.php.
             *
             * @param string $parent
             * @param mixed $node
             * @return void
             */
            public function add($parent, $node): void {
            }
        };
        require($CFG->dirroot . '/local/intellistream/settings.php');

        $this->assertInstanceOf(\admin_settingpage::class, $settings);
        foreach ($settings->settings as $setting) {
            if ($setting->name === 'ibnltirole') {
                return $setting;
            }
        }
        $this->fail('settings.php does not add ibnltirole');
    }

    /**
     * Building the admin tree leaves the role list unloaded.
     */
    public function test_settings_php_does_not_load_the_role_list(): void {
        $this->resetAfterTest();

        $setting = $this->setting_from_settings_php();

        $this->assertNull($setting->choices);
    }

    /**
     * load_choices() fills the list with the roles that carry viewlti, keyed by role id.
     */
    public function test_load_choices_lists_roles_with_viewlti(): void {
        global $DB;
        $this->resetAfterTest();

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'ltiviewer', 'name' => 'LTI viewer']);
        assign_capability('local/intellistream:viewlti', CAP_ALLOW, $roleid, \context_system::instance()->id);
        $otherid = $DB->get_field('role', 'id', ['shortname' => 'guest']);

        $setting = $this->setting_from_settings_php();

        $this->assertTrue($setting->load_choices());
        $this->assertIsArray($setting->choices);
        $this->assertArrayHasKey($roleid, $setting->choices);
        $this->assertArrayNotHasKey((int) $otherid, $setting->choices);
    }

    /**
     * A second load_choices() call keeps the list it already has.
     */
    public function test_load_choices_runs_the_query_once(): void {
        global $DB;
        $this->resetAfterTest();

        $setting = $this->setting_from_settings_php();
        $setting->load_choices();
        $reads = $DB->perf_get_reads();
        $setting->load_choices();

        $this->assertSame($reads, $DB->perf_get_reads());
    }
}
