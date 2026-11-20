@local @local_intellistream
Feature: IntelliStream datatype configuration
  As a site administrator
  I want to turn the export of a datatype on or off and record why
  So that I control which Moodle data goes to IntelliStream

  Background:
    Given the following config values are set as admin:
      | enabled | 0 | local_intellistream |

  Scenario: The datatype list shows the built-in datatypes with an edit link
    When I am on the "local_intellistream > Datatypes" page logged in as "admin"
    Then I should see "Datatypes"
    And I should see "user"
    And "Edit" "link" should exist

  @javascript
  Scenario: An administrator turns a datatype off and the change is logged
    Given I am on the "behat_custom" "local_intellistream > Datatype" page logged in as "admin"
    When I set the following fields to these values:
      | Enabled        | 0                       |
      | Custom columns | id, name                |
      | Notes          | Paused for the pilot    |
    And I press "Save changes"
    Then I should see "Datatype configuration saved."
    And "disabled" "text" should exist in the "behat_custom (custom)" "table_row"
    And I should see "Paused for the pilot"
    When I am on the "local_intellistream > Logs" page
    Then "enabled=0 custom_columns_len=8" "text" should exist in the "behat_custom" "table_row"

  Scenario: Cancel leaves the datatype unchanged
    Given I am on the "behat_custom" "local_intellistream > Datatype" page logged in as "admin"
    When I set the field "Notes" to "Not saved"
    And I press "Cancel"
    Then I should see "Datatypes"
    And I should not see "Not saved"
    And I should not see "behat_custom (custom)"
