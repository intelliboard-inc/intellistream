@local @local_intellistream
Feature: IntelliStream LTI dashboard
  As a user with access to the IntelliBoard dashboard
  I want a Moodle page that opens the dashboard with a signed launch
  So that I see my reports without a second login

  Background:
    Given the following config values are set as admin:
      | enabled         | 0                               | local_intellistream |
      | ltitoolurl      | https://tool.example.invalid/lti | local_intellistream |
      | lticonsumerkey  | behatkey                        | local_intellistream |
      | ltisharedsecret | behatsecret                     | local_intellistream |

  Scenario: The dashboard page uses the default title and embeds the launch
    When I am on the "local_intellistream > Dashboard" page logged in as "admin"
    Then I should see "Analytics"
    And "#contentframe" "css_element" should exist

  Scenario: The dashboard page uses the configured title
    Given the following config values are set as admin:
      | ltititle | Reports hub | local_intellistream |
    When I am on the "local_intellistream > Dashboard" page logged in as "admin"
    Then I should see "Reports hub"

  Scenario: The launch page sends a signed launch form to the tool
    When I am on the "local_intellistream > Launch" page logged in as "admin"
    Then "#ltiLaunchForm" "css_element" should exist
    And "input[name='oauth_signature']" "css_element" should exist
    And "input[name='oauth_consumer_key'][value='behatkey']" "css_element" should exist

  @javascript
  Scenario: Debug mode shows the launch data and does not send the form
    Given the following config values are set as admin:
      | ltidebug | 1 | local_intellistream |
    When I am on the "local_intellistream > Launch" page logged in as "admin"
    And I click on "Toggle Debug Data" "link"
    Then I should see "LTI Endpoint"
    And I should see "https://tool.example.invalid/lti"
    And I should see "LTI Parameters"

  Scenario: Debug mode is for administrators only; other users get the normal launch
    Given the following config values are set as admin:
      | ltidebug | 1 | local_intellistream |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | viewer   | View      | Er       | viewer@example.com   |
    And the following "roles" exist:
      | shortname  | name             | archetype |
      | dashviewer | Dashboard viewer |           |
    And the following "role capability" exists:
      | role                        | dashviewer |
      | local/intellistream:viewlti | allow      |
    And the following "system role assigns" exist:
      | user   | role       | contextlevel |
      | viewer | dashviewer | System       |
    When I am on the "local_intellistream > Launch" page logged in as "viewer"
    Then "#ltiLaunchForm" "css_element" should exist
    And I should not see "Toggle Debug Data"
