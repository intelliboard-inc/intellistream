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

namespace local_intellistream\external;

use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\local_intellistream\external\get_status::class)]
/**
 * Tests for the local_intellistream_get_status web service function.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\external\get_status
 */
final class get_status_test extends \advanced_testcase {
    /**
     * Call the function the way the web service layer does, and validate the result.
     *
     * @return array The status.
     */
    private function status(): array {
        return get_status::clean_returnvalue(get_status::execute_returns(), get_status::execute());
    }

    /**
     * A user without the pull capability is refused.
     */
    public function test_requires_the_pull_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_status::execute();
    }

    /**
     * Object storage counts as configured only when all four connection settings are set.
     */
    public function test_storage_is_configured_only_with_all_four_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (['endpoint', 'bucket', 'accesskey', 'secretkey'] as $name) {
            set_config($name, '', 'local_intellistream');
        }
        $this->assertSame(0, $this->status()['s3_configured']);

        set_config('endpoint', 'https://storage.example.com', 'local_intellistream');
        set_config('bucket', 'bucket', 'local_intellistream');
        set_config('accesskey', 'key', 'local_intellistream');
        $this->assertSame(0, $this->status()['s3_configured'], 'three of four is not configured');

        set_config('secretkey', 'secret', 'local_intellistream');
        $this->assertSame(1, $this->status()['s3_configured']);
    }

    /**
     * The capture and encryption switches are reported as set.
     */
    public function test_reports_the_capture_and_encryption_switches(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('enabled', 1, 'local_intellistream');
        set_config('encryption_enabled', 0, 'local_intellistream');
        $status = $this->status();
        $this->assertSame(1, $status['plugin_enabled']);
        $this->assertSame(0, $status['encryption_enabled']);

        set_config('enabled', 0, 'local_intellistream');
        set_config('encryption_enabled', 1, 'local_intellistream');
        $status = $this->status();
        $this->assertSame(0, $status['plugin_enabled']);
        $this->assertSame(1, $status['encryption_enabled']);
    }

    /**
     * Before the first send run, the ship times are empty and the buffer count is zero.
     */
    public function test_new_site_has_no_ship_history(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $status = $this->status();
        $this->assertSame('', $status['last_ship_ok']);
        $this->assertSame(0, $status['buffer_files']);
        $this->assertSame(0, $status['undeletable_files']);
    }
}
