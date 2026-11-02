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

/**
 * Behat steps for local_intellistream.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;
use local_intellistream\buffer;
use local_intellistream\config;

/**
 * Behat steps for local_intellistream.
 *
 * @package    local_intellistream
 * @category   test
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_intellistream extends behat_base {
    /**
     * Resolve a page name for the core "I am on the "local_intellistream > X" page" step.
     *
     * Recognised names: Status, Datatypes, Logs, Targeted re-fetch, Dashboard, Launch.
     *
     * @param string $page Page name.
     * @return moodle_url
     * @throws Exception When the name is not recognised.
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch (strtolower(trim($page))) {
            case 'status':
                return new moodle_url('/local/intellistream/status.php');
            case 'datatypes':
                return new moodle_url('/local/intellistream/config/index.php');
            case 'logs':
                return new moodle_url('/local/intellistream/logs.php');
            case 'targeted re-fetch':
                return new moodle_url('/local/intellistream/refetch.php');
            case 'dashboard':
                return new moodle_url('/local/intellistream/lti.php');
            case 'launch':
                return new moodle_url('/local/intellistream/launch.php');
        }
        throw new Exception('Unrecognised local_intellistream page "' . $page . '"');
    }

    /**
     * Resolve a page instance for the core "I am on the "X" "local_intellistream > Y" page" step.
     *
     * Recognised types: Datatype (identifier = datatype key).
     *
     * @param string $type Page type.
     * @param string $identifier Instance identifier.
     * @return moodle_url
     * @throws Exception When the type is not recognised.
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        if (strtolower(trim($type)) === 'datatype') {
            return new moodle_url('/local/intellistream/config/edit.php', ['datatype' => $identifier]);
        }
        throw new Exception('Unrecognised local_intellistream page type "' . $type . '"');
    }

    /**
     * Publish a buffer measurement, as the send task does after each run.
     *
     * The table has two columns, field and value. Fields: closed, active, pulled,
     * parked, truncated, bytes, parked_minutes_ago, previous_dir, previous_count.
     * previous_dir is a path inside the Moodle data directory; it is also recorded as
     * a buffer folder that the site used before.
     *
     * @Given /^the IntelliStream buffer measurement is:$/
     * @param TableNode $table Field and value pairs.
     */
    public function the_intellistream_buffer_measurement_is(TableNode $table): void {
        global $CFG;
        $values = $table->getRowsHash();
        $dir = config::buffer_dir();
        make_writable_directory($dir);
        $fields = [];
        foreach (['closed', 'active', 'pulled', 'parked', 'truncated', 'bytes'] as $key) {
            if (isset($values[$key])) {
                $fields[$key] = (int)$values[$key];
            }
        }
        if (isset($values['parked_minutes_ago'])) {
            $fields['parkedat'] = time() - 60 * (int)$values['parked_minutes_ago'];
        }
        if (isset($values['previous_dir'])) {
            $previous = rtrim($CFG->dataroot, '/') . '/' . ltrim($values['previous_dir'], '/');
            make_writable_directory($previous);
            $previous = realpath($previous);
            set_config('bufferdirsused', json_encode([realpath($dir), $previous]), config::COMPONENT);
            $fields['prev'] = [$previous => (int)($values['previous_count'] ?? 0)];
        }
        buffer::publish_capacity($dir, $fields, null, time());
    }

    /**
     * Create the buffer directory, as the first captured record does on a live site.
     *
     * @Given /^the IntelliStream buffer directory exists$/
     */
    public function the_intellistream_buffer_directory_exists(): void {
        make_writable_directory(config::buffer_dir());
    }

    /**
     * Check the result word in a JSON answer of a plugin endpoint.
     *
     * @Then /^the IntelliStream endpoint should answer "(?P<result_string>(?:[^"]|\\")*)"$/
     * @param string $result Expected value of the "result" key.
     */
    public function the_intellistream_endpoint_should_answer(string $result): void {
        $answer = json_decode($this->getSession()->getPage()->getContent(), true);
        if (!is_array($answer) || ($answer['result'] ?? null) !== $result) {
            throw new ExpectationException('The endpoint answered "' . $this->getSession()->getPage()->getContent()
                . '", not a result of "' . $result . '".', $this->getSession());
        }
    }

    /**
     * Set a plugin setting to a time some seconds in the past.
     *
     * @Given /^the IntelliStream setting "(?P<name_string>(?:[^"]|\\")*)" is set to (?P<seconds_number>\d+) seconds ago$/
     * @param string $name Setting name.
     * @param int $seconds Seconds before now.
     */
    public function the_intellistream_setting_is_set_seconds_ago(string $name, int $seconds): void {
        set_config($name, time() - $seconds, config::COMPONENT);
    }

    /**
     * Check the stored value of one of the plugin's settings.
     *
     * Read from the database: this process's config cache can predate a save that the
     * browser made in another request.
     *
     * @Then /^the IntelliStream setting "(?P<name_string>(?:[^"]|\\")*)" should be "(?P<value_string>(?:[^"]|\\")*)"$/
     * @param string $name Setting name.
     * @param string $value Expected value.
     */
    public function the_intellistream_setting_should_be(string $name, string $value): void {
        global $DB;
        $actual = (string)$DB->get_field('config_plugins', 'value', ['plugin' => config::COMPONENT, 'name' => $name]);
        if ($actual !== $value) {
            throw new ExpectationException(
                'The setting "' . $name . '" is "' . $actual . '", not "' . $value . '".',
                $this->getSession()
            );
        }
    }

    /**
     * Fill a field with a path relative to the Moodle data directory of the test site.
     *
     * @When /^I set the field "(?P<field_string>(?:[^"]|\\")*)" to the Moodle data directory path "(?P<suffix_string>[^"]*)"$/
     * @param string $field Field locator.
     * @param string $suffix Path below the data directory; empty for the data directory itself.
     */
    public function i_set_the_field_to_the_moodle_data_directory_path(string $field, string $suffix): void {
        global $CFG;
        $path = rtrim($CFG->dataroot, '/') . ($suffix === '' ? '' : '/' . ltrim($suffix, '/'));
        $this->execute('behat_forms::i_set_the_field_to', [$field, $path]);
    }

    /**
     * Disable one of the plugin's scheduled tasks.
     *
     * @Given /^the IntelliStream scheduled task "(?P<class_string>(?:[^"]|\\")*)" is disabled$/
     * @param string $classname Task class without the leading backslash.
     */
    public function the_intellistream_scheduled_task_is_disabled(string $classname): void {
        $task = \core\task\manager::get_scheduled_task('\\' . ltrim($classname, '\\'));
        $task->set_disabled(true);
        \core\task\manager::configure_scheduled_task($task);
    }

    /**
     * Check the value of a row on the IntelliStream status page.
     *
     * @Then /^the IntelliStream status row "(?P<label_string>(?:[^"]|\\")*)" should show "(?P<value_string>(?:[^"]|\\")*)"$/
     * @param string $label Text of the row's first cell.
     * @param string $value Text the row's second cell must contain.
     */
    public function the_intellistream_status_row_should_show(string $label, string $value): void {
        $cell = $this->find('xpath', $this->status_value_xpath($label), new ExpectationException(
            'The status page has no row "' . $label . '".',
            $this->getSession()
        ));
        if (strpos(trim(preg_replace('/\s+/u', ' ', $cell->getText())), $value) === false) {
            throw new ExpectationException('The status row "' . $label . '" shows "' . $cell->getText()
                . '", not "' . $value . '".', $this->getSession());
        }
    }

    /**
     * Check that a row is absent from the IntelliStream status page.
     *
     * @Then /^the IntelliStream status row "(?P<label_string>(?:[^"]|\\")*)" should not exist$/
     * @param string $label Text of the row's first cell.
     */
    public function the_intellistream_status_row_should_not_exist(string $label): void {
        $this->ensure_element_does_not_exist($this->status_value_xpath($label), 'xpath_element');
    }

    /**
     * XPath of the value cell in the status row with this label.
     *
     * @param string $label Text of the row's first cell.
     * @return string
     */
    protected function status_value_xpath(string $label): string {
        $literal = behat_context_helper::escape($label);
        return "//table[contains(concat(' ', normalize-space(@class), ' '), ' generaltable ')]"
            . "//tr[td[1][normalize-space(.) = $literal]]/td[2]";
    }

    /**
     * Check how many menu bar links open the IntelliStream dashboard.
     *
     * Counts the links outside the navigation tree (the Navigation block in the
     * Classic theme, the navigation drawer in older Boost) and outside the copy of the
     * menu that a theme shows only on small screens, so it checks the menu bar entry
     * the same way in every theme and on every supported Moodle version. The
     * navigation tree node is a separate feature and is not counted.
     *
     * @Then /^the menu bar should have (?P<count_number>\d+) links? to the IntelliStream dashboard$/
     * @param int $count Expected number of links.
     */
    public function the_menu_bar_should_have_links_to_the_intellistream_dashboard(int $count): void {
        $links = $this->getSession()->getPage()->findAll(
            'xpath',
            "//a[contains(@href, '/local/intellistream/lti.php')]"
            . "[not(ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' block_navigation ')"
            . " or @id = 'nav-drawer' or contains(concat(' ', normalize-space(@class), ' '), ' drawer ')"
            . " or contains(concat(' ', normalize-space(@class), ' '), ' d-md-none ')])]"
        );
        if (count($links) !== $count) {
            throw new ExpectationException('The menu bar has ' . count($links) . ' links to the dashboard, not '
                . $count . '.', $this->getSession());
        }
    }

    /**
     * Check that the page asks for the page-time module.
     *
     * @Then /^the page should load the IntelliStream page-time module$/
     */
    public function the_page_should_load_the_intellistream_page_time_module(): void {
        if (strpos($this->getSession()->getPage()->getContent(), 'local_intellistream/dwell') === false) {
            throw new ExpectationException(
                'The page does not load the local_intellistream/dwell module.',
                $this->getSession()
            );
        }
    }

    /**
     * Check that the page does not ask for the page-time module.
     *
     * @Then /^the page should not load the IntelliStream page-time module$/
     */
    public function the_page_should_not_load_the_intellistream_page_time_module(): void {
        if (strpos($this->getSession()->getPage()->getContent(), 'local_intellistream/dwell') !== false) {
            throw new ExpectationException('The page loads the local_intellistream/dwell module.', $this->getSession());
        }
    }

    /**
     * Check that the buffer holds a page-time record (page_dwell or media_segment) for a user.
     *
     * Waits for the record, because the browser sends page-time records when it leaves a page.
     *
     * @Then /^a "(?P<type_string>[^"]*)" record for "(?P<username_string>(?:[^"]|\\")*)" should be in the IntelliStream buffer$/
     * @param string $type Record type, for example page_dwell.
     * @param string $username User name.
     */
    public function a_record_for_user_should_be_in_the_intellistream_buffer(string $type, string $username): void {
        global $DB;
        $userid = (int)$DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $this->spin(function () use ($type, $userid) {
            foreach (config::buffer_dirs() as $dir) {
                foreach (glob($dir . '/*.jsonl*') ?: [] as $file) {
                    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                        $record = json_decode($line, true);
                        if (
                            is_array($record) && ($record['record_type'] ?? '') === $type
                                && (int)($record['dwell']['userid'] ?? 0) === $userid
                        ) {
                            return true;
                        }
                    }
                }
            }
            throw new ExpectationException('No "' . $type . '" record for the user is in the buffer.', $this->getSession());
        }, [], self::get_extended_timeout());
    }
}
