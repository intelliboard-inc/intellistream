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

use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\local_intellistream\services\lti_service::class)]
#[CoversClass(\local_intellistream\services\plugin_report::class)]
/**
 * Tests that LTI debug mode is local-only and shown only to site administrators.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\services\lti_service
 * @covers     \local_intellistream\services\plugin_report
 */
final class lti_debug_test extends \advanced_testcase {
    /**
     * The debug flag of the launch data for the current user, as launch.php reads it.
     *
     * @return bool
     */
    private function service_debug(): bool {
        set_config('ltitoolurl', 'https://tool.example.invalid/lti', 'local_intellistream');
        set_config('lticonsumerkey', 'phpunitkey', 'local_intellistream');
        set_config('ltisharedsecret', 'phpunitsecret', 'local_intellistream');
        [, , $debug] = (new \local_intellistream\services\lti_service())->lti_get_launch_data([]);
        return (bool) $debug;
    }

    /**
     * With debug mode on, a site administrator gets debug output and another user does not.
     */
    public function test_debug_is_for_site_administrators_only(): void {
        $this->resetAfterTest();
        set_config('ltidebug', 1, 'local_intellistream');

        $this->setAdminUser();
        $this->assertTrue($this->service_debug());

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse($this->service_debug());
    }

    /**
     * With debug mode off, nobody gets debug output.
     */
    public function test_debug_off_is_off_for_everyone(): void {
        $this->resetAfterTest();
        set_config('ltidebug', 0, 'local_intellistream');

        $this->setAdminUser();
        $this->assertFalse($this->service_debug());
    }

    /**
     * The control webhook cannot change ltidebug.
     */
    public function test_ltidebug_is_not_writable_over_the_webhook(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse(\local_intellistream\services\plugin_report::is_writable('ltidebug'));
        $this->assertTrue(\local_intellistream\services\plugin_report::is_writable('ltititle'));
    }
}
