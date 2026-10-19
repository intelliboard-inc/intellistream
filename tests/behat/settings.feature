@local @local_intellistream
Feature: IntelliStream admin settings
  As a site administrator
  I want the IntelliStream settings page to show every section and to reject unsafe values
  So that I can connect the site to IntelliStream without breaking capture

  Background:
    Given the following config values are set as admin:
      | enabled | 0 | local_intellistream |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > IntelliStream" in site administration

  Scenario: The settings page shows every section and links to the status page
    Then I should see "Object storage connection"
    And I should see "Control webhook"
    And I should see "Capture and buffer behaviour"
    And I should see "Page-dwell capture"
    And I should see "Bulk entity export"
    And I should see "Payload encryption (in transit)"
    And I should see "Blackboard Collaborate API (attendance)"
    And I should see "IntelliBoard Pro LTI launch"
    And "IntelliStream status" "link" should exist

  Scenario: A new site has safe defaults for the new 0.9.32 and 0.9.33 settings
    Then the field "Verification sweep: re-send every record once in this many passes" matches value "0"
    And the field "Export discovered dynamic-table data" matches value "0"
    And the field "Display in custom menu" matches value "0"

  Scenario: A short webhook secret is refused
    When I set the field "Webhook secret" to "tooshort"
    And I press "Save changes"
    Then I should see "The webhook secret must be at least 32 characters."

  Scenario: A long webhook secret and an empty webhook secret are accepted
    When I set the field "Webhook secret" to "0123456789abcdef0123456789abcdef0123"
    And I press "Save changes"
    Then I should see "Changes saved"
    And I should not see "The webhook secret must be at least"
    When I set the field "Webhook secret" to ""
    And I press "Save changes"
    Then I should see "Changes saved"

  Scenario Outline: An unsafe buffer directory is refused
    When I set the field "Buffer directory" to "<path>"
    And I press "Save changes"
    Then I should see "<error>"

    Examples:
      | path                          | error                                                          |
      | intellistream/buffer          | The buffer directory must be an absolute path.                 |
      | /var/x/../buffer              | The buffer directory must not contain                          |
      | /nonexistent-intellistream/b  | The buffer directory must be inside Moodle's data directory    |

  Scenario: The Moodle data directory and its core folders are refused as the buffer directory
    When I set the field "Buffer directory" to the Moodle data directory path ""
    And I press "Save changes"
    Then I should see "The buffer directory must not be Moodle's data directory itself."
    When I set the field "Buffer directory" to the Moodle data directory path "filedir/intellistream"
    And I press "Save changes"
    Then I should see "The buffer directory must not be one of Moodle's own data folders"

  Scenario: A dedicated folder inside the Moodle data directory is accepted as the buffer directory
    When I set the field "Buffer directory" to the Moodle data directory path "intellistream/other"
    And I press "Save changes"
    Then I should see "Changes saved"
    And I should not see "The buffer directory must"

  Scenario: A discovery prefix outside local plugins is refused
    When I set the field "Dynamic-table discovery prefixes" to "core_"
    And I press "Save changes"
    Then I should see "Some settings were not changed due to an error."
    And I should see "Discovery is for local-plugin table families"
    When I set the field "Dynamic-table discovery prefixes" to "local_intellicart_ local_other_"
    And I press "Save changes"
    Then I should see "Changes saved"

  Scenario: The verification sweep re-send setting keeps the saved value
    When I set the field "Verification sweep: re-send every record once in this many passes" to "7"
    And I set the field "Export discovered dynamic-table data" to "1"
    And I press "Save changes"
    Then I should see "Changes saved"
    And the field "Verification sweep: re-send every record once in this many passes" matches value "7"
    And the field "Export discovered dynamic-table data" matches value "1"
    And the IntelliStream setting "sweeprefreshpasses" should be "7"
    And the IntelliStream setting "dynamicdiscoveryexport" should be "1"
