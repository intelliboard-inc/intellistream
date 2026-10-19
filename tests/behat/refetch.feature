@local @local_intellistream
Feature: IntelliStream targeted re-fetch
  As a site administrator
  I want to queue a re-send of a time window for chosen datatypes
  So that I can repair data that did not arrive, without a pile of duplicate re-sends

  Background:
    Given the following config values are set as admin:
      | enabled | 0 | local_intellistream |
    And I am on the "local_intellistream > Targeted re-fetch" page logged in as "admin"

  Scenario: A re-fetch of all datatypes is queued
    When I press "Run re-fetch now"
    Then I should see "Targeted re-fetch queued: all entities, window"

  @javascript
  Scenario: A re-fetch of one datatype is queued
    When I set the field "Entities to re-fetch" to "user"
    And I press "Run re-fetch now"
    Then I should see "Targeted re-fetch queued: 1 entities, window"

  Scenario: A second re-fetch is refused while the first one waits to run
    Given I press "Run re-fetch now"
    When I press "Run re-fetch now"
    Then I should see "A re-fetch is already queued and has not started yet."

  Scenario: The end of the window must be after its start
    When I set the following fields to these values:
      | Re-fetch from           | ##yesterday##  |
      | Re-fetch to (inclusive) | ##2 days ago## |
    And I press "Run re-fetch now"
    Then I should see "The \"to\" date must be after the \"from\" date."
    And I should not see "Targeted re-fetch queued"
