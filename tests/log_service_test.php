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
use local_intellistream\services\log_service;

#[CoversClass(\local_intellistream\services\log_service::class)]
/**
 * Tests for the display names of log types and actions.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\services\log_service
 */
final class log_service_test extends \advanced_testcase {
    /**
     * Every type and action has its own display name.
     */
    public function test_every_type_and_action_has_a_label(): void {
        $sm = get_string_manager();
        foreach (log_service::types() as $type) {
            $this->assertTrue($sm->string_exists('logtype_' . $type, 'local_intellistream'), $type);
            $this->assertNotSame($type, log_service::type_label($type));
        }
        foreach (log_service::actions() as $action) {
            $this->assertTrue($sm->string_exists('logaction_' . $action, 'local_intellistream'), $action);
            $this->assertNotSame($action, log_service::action_label($action));
        }
    }

    /**
     * A value that the plugin does not write, for example from an older release, is shown as stored.
     */
    public function test_unknown_value_is_shown_as_stored(): void {
        $this->assertSame('ship', log_service::type_label('ship'));
        $this->assertSame('webhook', log_service::action_label('webhook'));
    }
}
