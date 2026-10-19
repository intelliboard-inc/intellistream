@local @local_intellistream
Feature: IntelliStream status page
  As a site administrator
  I want one page that tells me if capture and shipping work and why they do not
  So that I can act before data waits too long or capture is refused

  Background:
    Given the following config values are set as admin:
      | enabled | 0 | local_intellistream |

  Scenario: A new site shows that the send task has not run yet
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then I should see "The shipper task has not run yet. Status will appear after its first run."
    And the IntelliStream status row "Object storage configured" should show "No"
    And the IntelliStream status row "Buffer measured" should show "Not yet"
    And I should see "Daily check and history load"
    And the IntelliStream status row "Historical backfill complete" should show "No"

  Scenario: A successful last run shows a healthy status
    Given the following config values are set as admin:
      | ship_state | ok | local_intellistream |
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then I should see "Capture and shipping are healthy"
    And I should not see "Attention needed"

  Scenario: A send run without object storage settings says what is missing
    Given the following config values are set as admin:
      | enabled | 1 | local_intellistream |
    And the IntelliStream buffer directory exists
    And I run the scheduled task "\local_intellistream\task\ship_events"
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then I should see "Attention needed — see the shipper state and the buffer details below."
    And the IntelliStream status row "Last shipper state" should show "unconfigured"
    And the IntelliStream status row "Last shipper detail" should show "Object storage settings are incomplete."
    And I should not see "Not yet: the shipper task publishes"

  Scenario: A send run without a site ID holds the data and asks for the site ID
    Given the following config values are set as admin:
      | enabled   | 1                          | local_intellistream |
      | endpoint  | https://s3.example.invalid | local_intellistream |
      | bucket    | behat-bucket               | local_intellistream |
      | accesskey | behatkey                   | local_intellistream |
      | secretkey | behatsecret                | local_intellistream |
    And the IntelliStream buffer directory exists
    And I run the scheduled task "\local_intellistream\task\ship_events"
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then the IntelliStream status row "Object storage configured" should show "Yes"
    And the IntelliStream status row "Last shipper state" should show "unpaired"
    And the IntelliStream status row "Last shipper detail" should show "Site ID not set"

  Scenario: Files that an earlier release deleted are history and do not alarm
    Given the following config values are set as admin:
      | ship_state      | ok       | local_intellistream |
      | last_drop_count | 3        | local_intellistream |
      | last_drop_bytes | 3145728  | local_intellistream |
    And the IntelliStream setting "last_drop_time" is set to 86400 seconds ago
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then the IntelliStream status row "Files deleted at the cap by an earlier release" should show "3 file(s)"
    And I should see "Capture and shipping are healthy"

  Scenario: A recent capture refusal at the buffer limit turns the status red
    Given the following config values are set as admin:
      | ship_state         | ok         | local_intellistream |
      | last_capfail_bytes | 6000000000 | local_intellistream |
    And the IntelliStream setting "last_capfail_time" is set to 60 seconds ago
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then I should see "Attention needed"
    And the IntelliStream status row "Buffer full — capture refused" should show "New records are refused until the shipper drains it."

  Scenario: An old capture refusal stays visible but does not turn the status red
    Given the following config values are set as admin:
      | ship_state         | ok         | local_intellistream |
      | last_capfail_bytes | 6000000000 | local_intellistream |
    And the IntelliStream setting "last_capfail_time" is set to 86400 seconds ago
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then I should see "Capture and shipping are healthy"
    And the IntelliStream status row "Buffer full — capture refused" should show "Last refused"

  Scenario: Delivered files that cannot be removed stop shipping and are named
    Given the following config values are set as admin:
      | ship_state             | ok                                 | local_intellistream |
      | last_undeletable_count | 2                                  | local_intellistream |
      | last_undeletable_names | events-a.jsonl.closed              | local_intellistream |
    And the IntelliStream setting "last_undeletable_time" is set to 120 seconds ago
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then I should see "Attention needed"
    And the IntelliStream status row "Delivered files that cannot be removed" should show "events-a.jsonl.closed"

  Scenario: Entries that are not plain files raise an alert
    Given the following config values are set as admin:
      | ship_state   | ok | local_intellistream |
      | unsafe_files | 1  | local_intellistream |
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then the IntelliStream status row "Entries in the buffer directory that are not plain files" should show "1"
    And I should see "The buffer directory contains entries that are not plain files. They are being ignored"

  Scenario: Other buffer anomalies each get their own row
    Given the following config values are set as admin:
      | ship_state            | ok | local_intellistream |
      | unowned_files         | 2  | local_intellistream |
      | foreign_hosts         | 1  | local_intellistream |
      | last_unreadable_count | 1  | local_intellistream |
    And the IntelliStream setting "last_unreadable_time" is set to 60 seconds ago
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then the IntelliStream status row "Files not written by this plugin" should show "2"
    And the IntelliStream status row "Other web nodes detected" should show "1"
    And the IntelliStream status row "Unreadable buffer file names" should show "1 reclaimed"

  Scenario: The buffer file counts come from the last published measurement
    Given the IntelliStream buffer measurement is:
      | closed | 3 |
      | active | 1 |
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then the IntelliStream status row "Buffer files" should show "3 closed, 1 active"
    And I should not see "Not yet: the shipper task publishes"

  Scenario: Files waiting for cleanup after a pull are counted separately
    Given the IntelliStream buffer measurement is:
      | closed | 3 |
      | active | 1 |
      | pulled | 2 |
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then the IntelliStream status row "Buffer files" should show "3 closed, 1 active, 2 pulled (pending cleanup)"

  Scenario: Parked files turn the status red and show when the oldest was written
    Given the following config values are set as admin:
      | ship_state | ok | local_intellistream |
    And the IntelliStream buffer measurement is:
      | parked             | 2  |
      | parked_minutes_ago | 90 |
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then I should see "Attention needed"
    And the IntelliStream status row "Parked buffer files" should show "2 file(s) that object storage refused on their own"
    And the IntelliStream status row "Parked buffer files" should show "The oldest was last written"

  Scenario: A backlog larger than one run is information while shipping works
    Given the following config values are set as admin:
      | ship_state | ok | local_intellistream |
    And the IntelliStream buffer measurement is:
      | truncated | 1 |
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then I should see "Capture and shipping are healthy"
    And the IntelliStream status row "Backlog larger than one run" should show "reached its 600-second limit"

  Scenario: A backlog flag from an earlier run is hidden when shipping stopped
    Given the following config values are set as admin:
      | ship_state | unconfigured | local_intellistream |
    And the IntelliStream buffer measurement is:
      | truncated | 1 |
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then the IntelliStream status row "Backlog larger than one run" should not exist

  Scenario: Files left in a previous buffer directory are shown until collected
    Given the IntelliStream buffer measurement is:
      | previous_dir   | intellistream/oldbuffer |
      | previous_count | 4                       |
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then the IntelliStream status row "Previous buffer directory" should show "4 file(s) still to collect from"

  Scenario: A disabled 15-minute export task is reported
    Given the IntelliStream scheduled task "local_intellistream\task\refresh_entities" is disabled
    When I am on the "local_intellistream > Status" page logged in as "admin"
    Then the IntelliStream status row "The 15-minute export task is disabled" should show "Yes"

  Scenario: The status page needs the view status capability
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | monitor  | Status    | Monitor  | monitor@example.com  |
    And the following "roles" exist:
      | shortname     | name          | archetype |
      | streammonitor | Stream status |           |
    And the following "role capability" exists:
      | role                            | streammonitor |
      | local/intellistream:viewstatus  | allow         |
    And the following "system role assigns" exist:
      | user    | role          | contextlevel |
      | monitor | streammonitor | System       |
    When I am on the "local_intellistream > Status" page logged in as "monitor"
    Then I should see "IntelliStream status"
    And I should see "Daily check and history load"
