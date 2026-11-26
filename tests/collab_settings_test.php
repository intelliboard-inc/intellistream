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
 * Tests that the Collaborate endpoint is local-only and must use https.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellistream\collab\collab_client
 * @covers     \local_intellistream\services\plugin_report
 */
final class collab_settings_test extends \advanced_testcase {
    /**
     * Set the three Collaborate settings.
     *
     * @param string $endpoint
     * @return void
     */
    private function configure(string $endpoint): void {
        set_config('collab_api_endpoint', $endpoint, 'local_intellistream');
        set_config('collab_consumer_key', 'key', 'local_intellistream');
        set_config('collab_secret', 'secret', 'local_intellistream');
    }

    /**
     * The control webhook cannot change the endpoint or the consumer key.
     */
    public function test_endpoint_and_key_are_not_writable_over_the_webhook(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse(\local_intellistream\services\plugin_report::is_writable('collab_api_endpoint'));
        $this->assertFalse(\local_intellistream\services\plugin_report::is_writable('collab_consumer_key'));
    }

    /**
     * An http endpoint gets no token request; an https endpoint is accepted.
     */
    public function test_token_request_needs_an_https_endpoint(): void {
        $this->resetAfterTest();

        $this->configure('http://collab.example.com');
        $client = new \local_intellistream\collab\collab_client();
        $this->assertTrue($client->has_credentials());
        $this->assertFalse($client->endpoint_is_https());
        $this->assertSame('', $client->get_access_token());

        $this->configure('https://collab.example.com');
        $this->assertTrue((new \local_intellistream\collab\collab_client())->endpoint_is_https());
    }
}
