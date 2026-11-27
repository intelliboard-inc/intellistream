# IntelliStream for Moodle (local_intellistream)

IntelliStream captures activity and course data from your Moodle site and sends it
to IntelliBoard for reporting. All reports, charts and dashboards are built by the
IntelliBoard service, not in Moodle.

The plugin records Moodle events, page time and selected database tables to a local
buffer on disk, and sends the buffer to S3-compatible object storage that IntelliBoard
provides. Capture writes no Moodle data rows. On rare events it writes to the plugin's
own settings: a note when capture is refused at the buffer size limit, a change of the
buffer directory, and the state of a recovery window run.

**IntelliStream requires an active IntelliBoard subscription.** Your IntelliBoard
account provides the Site ID and the storage settings that the plugin needs. Without
a Site ID, the plugin captures nothing and sends nothing. When the Site ID and the
storage settings are saved, a new installation sends the earlier history (historical
backfill).

## Features

- Capture of Moodle events, written to an append-only buffer on disk.
- Page time (time on page), on by default, and media play time, off by default.
- Export of Moodle tables: a first full load, then changed rows every 15 minutes and a
  daily check that also reports deleted records.
- Optional attendance import from Blackboard Collaborate.
- Optional IntelliBoard dashboard in Moodle, through LTI.
- A status page and an operational log for site administrators.
- Privacy API support: the plugin exports and deletes the data on the site, and
  declares the data sent off-site.

## Documentation

The [administrator guide](https://github.com/intelliboard-inc/moodle-local_intellistream/blob/main/docs/guide.md)
describes each feature, how to connect the plugin to IntelliBoard, and the plugin's
pages, with screenshots.

## Requirements

| Plugin build | Moodle |
|---|---|
| `0.9.35-intellistream` | 4.1 or later |
| `0.9.35-intellistream+m39` | 3.9 only |

Moodle 3.10, 3.11 and 4.0 are not supported.

- Database: MySQL or PostgreSQL.
- An active IntelliBoard subscription (see above).
- Outbound HTTPS from the Moodle server to the object storage endpoint.
- Cron must run. The scheduled tasks send the data.

## Installation

### Installing via uploaded ZIP file

1. Log in to your Moodle site as an administrator and go to
   _Site administration > Plugins > Install plugins_.
2. Upload the ZIP file with the plugin code.
3. Check the plugin validation report and finish the installation.

### Installing manually

1. Copy the contents of this directory to `{your/moodle/dirroot}/local/intellistream`.
2. Log in to your Moodle site as an administrator and go to
   _Site administration > Notifications_ to complete the installation.

   Alternatively, run the upgrade from the command line:

       php admin/cli/upgrade.php --non-interactive

### Upgrading

Put the site in maintenance mode, replace the `local/intellistream` directory, and
run the upgrade straight away. Until the upgrade runs, Moodle does not know the new
classes of the plugin. If the PHP opcode cache does not check files for changes
(`opcache.validate_timestamps=0`), reload PHP-FPM or the web server after the upgrade.

Read [Upgrade notes](#upgrade-notes) before you upgrade from an earlier release.

## Configuration

Go to _Site administration > Plugins > Local plugins > IntelliStream_.

1. Enter the **IntelliStream Site ID** from your IntelliBoard account.
2. Under **Object storage connection**, enter the endpoint URL, bucket name, access
   key and secret key that IntelliBoard provides. The endpoint must use `https://`,
   unless an administrator turns on **Allow insecure (http) endpoint** (off by
   default, for a trusted internal endpoint only).
3. Open the **IntelliStream status** page (the link is at the end of the settings
   page). Make sure that it shows your Site ID and that the last shipper state is `ok`.

The settings page has these sections, in this order. Each setting has a description
on the page.

| Section | Purpose |
|---|---|
| Object storage connection | Where the data is sent. |
| Control webhook | Signed commands from the IntelliBoard service. |
| Capture and buffer behaviour | The buffer location, file rotation, size limit and load limit, the limit for failed-request records, and how long log entries are kept. |
| Page-dwell capture | Page time, media time, and whether the activity of site administrators is captured. |
| Bulk entity export | Table export, batch size and the daily check. |
| Payload encryption (in transit) | Leave this off. When it is on, records are held in the local buffer and are not sent. |
| Blackboard Collaborate API (attendance) | Optional. |
| IntelliBoard Pro LTI launch | Optional dashboard in Moodle. |

No secrets or endpoints are stored in the code. The secret keys are stored as
masked settings.

## Scheduled tasks

| Task | Default schedule | Enabled by default |
|---|---|---|
| Ship captured events to object storage | Every minute | Yes |
| Refresh bulk entity snapshots | Every 15 minutes | Yes |
| Count user logins | Every 15 minutes | Yes |
| Discover dynamic (InForm) table candidates | Daily at 02:50 | Yes |
| Delete old IntelliStream log entries | Daily, random time | Yes |
| Historical entity backfill (one-time) | Every 30 minutes | No |
| Copy legacy IntelliBoard tracking (one-time migration) | Every 30 minutes | No |
| Pull Blackboard Collaborate attendance | Every 30 minutes | No |

Keep **Refresh bulk entity snapshots** enabled. It sends changed rows and runs the
daily check that sends tables without a change time and the list of deleted records.

## Capabilities

| Capability | Allows |
|---|---|
| `local/intellistream:viewstatus` | View the IntelliStream status page. |
| `local/intellistream:viewlogs` | View the IntelliStream operational log. |
| `local/intellistream:manage` | Manage the datatype configuration and run a targeted re-fetch. |
| `local/intellistream:pullexport` | Pull captured records over the web service. |
| `local/intellistream:viewlti` | View the IntelliBoard LTI dashboard. |

No role gets these capabilities by default. Site administrators have all of them.

## Web services

The plugin adds the service **IntelliBoard Pro** (`intelliboard_pro`) with the
functions `local_intellistream_pull_export` and `local_intellistream_get_status`.
Only authorised users of the service who have `local/intellistream:pullexport` can
call it.

## Command-line scripts

Run these from the Moodle root directory.

| Script | Purpose |
|---|---|
| `local/intellistream/cli/backfill.php` | Send the full history once, in one process. |
| `local/intellistream/cli/recover_window.php` | Read the standard log again for a date window and send those rows. |
| `local/intellistream/cli/userlogins_recount.php` | Ask the **Count user logins** task to count every user's logins again from the start. |
| `local/intellistream/cli/discover_dynamic_tables.php` | Register the tables of other plugins (by default `local_intellicart_*`) as candidate tables in the plugin's datatype configuration. An administrator activates each table in IntelliBoard. `--dry-run` only lists them. |

### Historical backfill

The command-line backfill runs to the end in one process. A backfill run from a task
(the **Historical entity backfill (one-time)** scheduled task, or the background runs
queued for a new installation or by the IntelliBoard service) stops after about
`backfillrunsec` seconds (10 minutes by default; a run can go over by one page of
rows) and continues from the saved position in the next run.

Two settings control the task runs. They are not on the settings page; set them
from the command line:

- `backfillrunsec`: seconds for each run (default 600, maximum 86400).
- `backfillstop`: `1` stops the task runs and the runs they queue. The command-line
  backfill still runs. To resume, set it to `0`, then enable the
  **Historical entity backfill (one-time)** task or run the command-line backfill.
  A new installation's backfill is queued again by the 15-minute task.

```
php admin/cli/cfg.php --component=local_intellistream --name=backfillstop --set=1
```

Disabling the **Historical entity backfill (one-time)** task does not stop runs that
are already queued. Use `backfillstop` for that.

## Privacy

- The plugin implements the Moodle Privacy API. It declares the data that it sends
  to IntelliBoard. It exports and deletes the personal data in its own tables
  (attendance rows, login totals) and in its local buffer (staged records).
- Credential columns (passwords, keys, tokens, meeting passwords, certificate codes
  and similar) are never sent.
- Each built-in table sends a selected set of columns. Sending all columns
  (`informfullrow`) is off by default, and it can be changed only on the site.
- A privacy export or deletion also covers records that are still in the local
  buffer. A matched record is removed whole. Known limits:
  - Data that was already sent to IntelliBoard is not removed by a Moodle deletion
    request. To remove it, contact IntelliBoard at helpdesk@intelliboard.net.
  - The recipients of a message are not in its buffered row, so a message is matched
    only to its sender.
  - A `userid` that names the user who acted (for example a teacher's grading step)
    is matched as that user.
  - A record whose only link to a user is a foreign key (for example competency
    evidence) is not matched. A discovered or custom table is matched on `userid`
    only.
  - A buffer file that may still be in use (being written, written in the last two
    minutes, or written by another server that shares the directory) is skipped.
    Its records are sent within minutes.

## Upgrade notes

### 0.9.35

- New scheduled task **Delete old IntelliStream log entries** (daily) and new setting
  **Keep log entries for** (`loglifetime`, default 90 days). Each run stops after
  about five minutes, so on a large log the first clean-up can take several daily
  runs. The setting can be changed only on the site.
- The upgrade deletes Blackboard Collaborate attendance rows that belong to no Moodle
  user: rows with a user id of zero or less, an id that matches no user, or the guest
  account. Rows of deleted users stay.
- The dashboard launch page uses the embedded page layout of the site. The page
  always shows an **Open dashboard** button, so the user can open the dashboard if
  the browser does not open it.

### 0.9.34.3

- The page URL for page time is passed through a hidden page element, so pages with
  very long URLs no longer show a "Too much data passed as arguments to
  js_call_amd()" debugging message. Purge all caches after installing (an upgrade
  does this).

### 0.9.34.2

- New recovery tool `cli/recover_window.php`. It reads the standard log again for a
  date window and sends those rows. It does nothing until an administrator or the
  IntelliBoard service runs it. It changes no Moodle data, never sends anonymous log
  rows, IP addresses or event details, and refuses a window in which capture was off
  or its settings changed.

### 0.9.34

- New scheduled task **Count user logins** (every 15 minutes). It reads the standard
  log in small blocks and keeps each user's total in its own table. The first runs
  count the whole log once. A login is counted and sent 15 to 45 minutes after it
  happens. To count again from the start, run `cli/userlogins_recount.php`.
- New setting `informfullrow` (Export full table rows for InForm), off by default.
  A change sends every row of the affected tables again over about one day, so
  change it outside peak hours.
- A table record over the 1 MiB record limit is sent with its largest text fields
  shortened or emptied, instead of being dropped. Records dropped by an earlier
  release are not sent again by the upgrade. To recover them, use the
  **Targeted re-fetch** page.

### Earlier releases

- `sweeprefreshpasses` defaults to 0 (off). A site that upgraded to 0.9.32 keeps its
  stored value of 7 until an administrator sets it to 0.
- `dynamicdiscoveryexport`: a site that already stores a value keeps it. A site that
  comes from 0.9.28 to 0.9.32 without a stored value gets it turned on if it has an
  enabled discovered table, and off if not. An enabled discovered table that is also
  a built-in table counts too, because those releases sent it with all its columns.
  With the switch on, tables discovered later are sent as well. A site that comes
  from 0.9.27 or earlier starts off. If discovered tables stop being sent, the
  upgrade reports how many.

## Support

- Report bugs and feature requests in the issue tracker:
  <https://github.com/intelliboard-inc/moodle-local_intellistream/issues>.
- For help with your IntelliBoard account or the connection settings, contact
  IntelliBoard at helpdesk@intelliboard.net.

The plugin is maintained by IntelliBoard, Inc.

## License

2026 IntelliBoard, Inc.

This program is free software: you can redistribute it and/or modify it under the
terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this
program. If not, see <https://www.gnu.org/licenses/>. The full licence text is in
[LICENSE](LICENSE).
