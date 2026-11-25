@local @local_intellistream
Feature: IntelliStream LTI role warning
  As a site administrator
  I want a warning when the LTI role gives more than dashboard access
  So that the role sync does not give users site-wide rights by mistake

  Background:
    Given the following config values are set as admin:
      | enabled | 0 | local_intellistream |
    And the following "roles" exist:
      | shortname    | name             | archetype |
      | dashviewer   | Dashboard viewer |           |
      | dashmanager  | Dashboard admin  |           |
      | plainrole    | Plain role       |           |
    And the following "role capability" exists:
      | role                         | dashviewer |
      | local/intellistream:viewlti  | allow      |
    And the following "role capability" exists:
      | role                         | dashmanager |
      | local/intellistream:viewlti  | allow       |
      | moodle/role:assign           | allow       |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > IntelliStream" in site administration

  Scenario: Only roles that can view the LTI dashboard can be selected
    Then the "IntelliBoard LTI role" select box should contain "Dashboard viewer"
    And the "IntelliBoard LTI role" select box should contain "Dashboard admin"
    And the "IntelliBoard LTI role" select box should not contain "Plain role"

  Scenario: A role that only views the dashboard gives no warning
    When I set the field "IntelliBoard LTI role" to "Dashboard viewer"
    And I press "Save changes"
    Then I should see "Changes saved"
    And I should not see "Warning: this role allows"

  Scenario: A role that can assign roles gives a warning
    When I set the field "IntelliBoard LTI role" to "Dashboard admin"
    And I press "Save changes"
    Then I should see "Warning: this role allows moodle/role:assign at system level."
