@local @local_intellistream
Feature: IntelliStream dashboard menu item
  As a user with access to the IntelliBoard dashboard
  I want a menu item that opens the dashboard when the administrator switches it on
  So that I find my reports without knowing the page address

  Background:
    Given the following config values are set as admin:
      | enabled        | 0                                | local_intellistream |
      | ltitoolurl     | https://tool.example.invalid/lti | local_intellistream |
      | custommenuitem | 1                                | local_intellistream |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | viewer   | Dash      | Viewer   | viewer@example.com   |
      | student1 | Student   | One      | student1@example.com |
    And the following "roles" exist:
      | shortname  | name             | archetype |
      | dashviewer | Dashboard viewer |           |
    And the following "role capability" exists:
      | role                        | dashviewer |
      | local/intellistream:viewlti | allow      |
    And the following "system role assigns" exist:
      | user   | role       | contextlevel |
      | viewer | dashviewer | System       |

  Scenario: A user who can view the dashboard sees the menu item
    When I log in as "viewer"
    And I am on site homepage
    Then the menu bar should have 1 link to the IntelliStream dashboard

  Scenario: The menu item is hidden when the administrator switches it off
    Given the following config values are set as admin:
      | custommenuitem | 0 | local_intellistream |
    When I log in as "viewer"
    And I am on site homepage
    Then the menu bar should have 0 links to the IntelliStream dashboard

  Scenario: The menu item is hidden when the LTI tool is not set up
    Given the following config values are set as admin:
      | ltitoolurl |  | local_intellistream |
    When I log in as "viewer"
    And I am on site homepage
    Then the menu bar should have 0 links to the IntelliStream dashboard

  Scenario: A user who cannot view the dashboard does not see the menu item
    When I log in as "student1"
    And I am on site homepage
    Then the menu bar should have 0 links to the IntelliStream dashboard

  Scenario: The menu item is not added on administration pages
    When I log in as "admin"
    And I navigate to "Plugins > Local plugins > IntelliStream" in site administration
    Then the menu bar should have 0 links to the IntelliStream dashboard

  Scenario: A menu line that the site already has for the dashboard is not repeated
    Given the following config values are set as admin:
      | custommenuitems | My reports\|/local/intellistream/lti.php |
    When I log in as "viewer"
    And I am on site homepage
    Then I should see "My reports"
    And the menu bar should have 1 link to the IntelliStream dashboard
