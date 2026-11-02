@local @local_intellistream
Feature: IntelliStream page-time capture
  As a site administrator
  I want page-time capture only when it is switched on and only for logged-in users
  So that the plugin records time on page without work on pages where it must not

  Background:
    Given the following config values are set as admin:
      | enabled      | 1                                    | local_intellistream |
      | dwellenabled | 1                                    | local_intellistream |
      | siteid       | 00000000-0000-4000-8000-0000be4a7000 | local_intellistream |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |

  Scenario: A logged-in page loads the page-time module
    When I am on the "C1" "Course" page logged in as "student1"
    Then the page should load the IntelliStream page-time module

  Scenario Outline: The page-time module is not loaded when capture is off
    Given the following config values are set as admin:
      | <setting> | 0 | local_intellistream |
    When I am on the "C1" "Course" page logged in as "student1"
    Then the page should not load the IntelliStream page-time module

    Examples:
      | setting      |
      | enabled      |
      | dwellenabled |

  Scenario: The page-time endpoint accepts only posted beacons
    Given I log in as "student1"
    When I visit "/local/intellistream/dwell.php"
    Then the IntelliStream endpoint should answer "method"

  @javascript
  Scenario: Leaving a page records the time spent on it
    Given I am on the "C1" "Course" page logged in as "student1"
    When I am on site homepage
    Then a "page_dwell" record for "student1" should be in the IntelliStream buffer
