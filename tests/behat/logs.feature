@local @local_intellistream
Feature: IntelliStream operational logs
  As a site administrator
  I want to read and filter the plugin's operational log
  So that I can see what the plugin did without access to the server

  Background:
    Given the following config values are set as admin:
      | enabled | 0 | local_intellistream |

  Scenario: An empty log says that there is nothing to display
    When I am on the "local_intellistream > Logs" page logged in as "admin"
    Then I should see "Operational logs"
    And I should see "Nothing to display"

  Scenario: The log can be filtered by type and action
    Given the following "local_intellistream > logs" exist:
      | type   | datatype | action    | details           |
      | config | forum    | save      | settings saved    |
      | export | page     | shortened | record shortened  |
      | export | page     | oversize  | record left out   |
    When I am on the "local_intellistream > Logs" page logged in as "admin"
    Then I should see "settings saved"
    And I should see "record shortened"
    And I should see "record left out"
    When I set the field "Type" to "Export"
    And I press "Filter"
    Then I should see "record shortened"
    And I should see "record left out"
    And I should not see "settings saved"
    When I set the field "Action" to "Too large, left out"
    And I press "Filter"
    Then I should see "record left out"
    And I should not see "record shortened"
    And I should not see "settings saved"

  Scenario: The log can be filtered by datatype
    Given the following "local_intellistream > logs" exist:
      | type   | datatype | action | details        |
      | config | forum    | save   | forum saved    |
      | config | quiz     | save   | quiz saved     |
    When I am on the "local_intellistream > Logs" page logged in as "admin"
    And I set the field "Datatype" to "quiz"
    And I press "Filter"
    Then I should see "quiz saved"
    And I should not see "forum saved"

  Scenario: An unknown type in the address is ignored and every entry is shown
    Given the following "local_intellistream > logs" exist:
      | type   | datatype | action   | details       |
      | config | forum    | save     | forum saved   |
      | export | page     | oversize | page left out |
    And I log in as "admin"
    When I visit "/local/intellistream/logs.php?type=bogus"
    Then I should see "forum saved"
    And I should see "page left out"

  Scenario: The clean-up task deletes entries older than the log lifetime
    Given the following "local_intellistream > logs" exist:
      | type   | datatype | action | details   | timecreated      |
      | config | forum    | save   | old entry | ##100 days ago## |
      | config | quiz     | save   | new entry | ##1 day ago##    |
    And I log in as "admin"
    And I run the scheduled task "\local_intellistream\task\cleanup_logs"
    When I am on the "local_intellistream > Logs" page
    Then I should see "new entry"
    And I should not see "old entry"
