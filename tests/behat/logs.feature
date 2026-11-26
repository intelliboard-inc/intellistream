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

  Scenario: The log can be filtered by type
    Given the following "local_intellistream > logs" exist:
      | type    | datatype | action | details            |
      | ship    |          | run    | shipped 3 files    |
      | webhook |          | ping   | control plane ping |
    When I am on the "local_intellistream > Logs" page logged in as "admin"
    Then I should see "shipped 3 files"
    And I should see "control plane ping"
    When I set the field "type" to "webhook"
    And I press "Filter"
    Then I should see "control plane ping"
    And I should not see "shipped 3 files"
