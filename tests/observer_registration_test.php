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
use local_intellistream\local\buffer_privacy;

#[CoversClass(\local_intellistream\observer::class)]
#[CoversClass(\local_intellistream\observers\exceptions_observer::class)]
#[CoversClass(\local_intellistream\observers\entity_observer::class)]
/**
 * Tests that every event callback is on \local_intellistream\observer and that the
 * forwarding observer classes, which Moodle can call until the upgrade runs, resolve.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\observer
 * @covers     \local_intellistream\observers\exceptions_observer
 * @covers     \local_intellistream\observers\entity_observer
 */
final class observer_registration_test extends \advanced_testcase {
    /**
     * Every entry in db/events.php calls a public static method of observer, not internal.
     */
    public function test_every_callback_is_on_observer(): void {
        global $CFG;
        $observers = [];
        require($CFG->dirroot . '/local/intellistream/db/events.php');

        $this->assertCount(17, $observers);
        foreach ($observers as $entry) {
            [$class, $method] = explode('::', $entry['callback']);
            $this->assertSame('\\local_intellistream\\observer', $class, $entry['eventname']);
            $reflection = new \ReflectionMethod(observer::class, $method);
            $this->assertTrue($reflection->isPublic() && $reflection->isStatic(), $entry['callback']);
            $this->assertFalse($entry['internal'], $entry['eventname']);
        }
    }

    /**
     * Each public method of a forwarding class has a method of the same name on observer.
     */
    public function test_forwarders_match_observer(): void {
        foreach ([observers\exceptions_observer::class, observers\entity_observer::class] as $class) {
            $methods = (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC);
            $this->assertNotEmpty($methods);
            foreach ($methods as $method) {
                $this->assertTrue(method_exists(observer::class, $method->getName()), $class . '::' . $method->getName());
            }
        }
    }

    /**
     * A failed-login event reaches the buffer through the forwarder as through observer.
     */
    public function test_forwarder_records_like_observer(): void {
        $this->resetAfterTest();
        buffer::flush();
        set_config('enabled', 1, 'local_intellistream');
        set_config('siteid', 'phpunit-observer-site', 'local_intellistream');
        observer::reset_datatype_cache();

        $user = $this->getDataGenerator()->create_user();
        $count = function () use ($user): int {
            $n = 0;
            foreach (buffer_privacy::user_records((int)$user->id) as $record) {
                if (($record['record_type'] ?? '') === datatypes\exceptions_datatype::RECORD_TYPE) {
                    $n++;
                }
            }
            return $n;
        };
        $event = \core\event\user_login_failed::create([
            'userid' => $user->id,
            'other'  => ['username' => $user->username, 'reason' => 3], // 3 = AUTH_LOGIN_FAILED.
        ]);

        observer::user_login_failed($event);
        $this->assertSame(1, $count());
        observers\exceptions_observer::user_login_failed($event);
        $this->assertSame(2, $count());
    }
}
