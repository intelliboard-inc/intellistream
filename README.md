# local_intellistream

Moodle event-capture + export adapter for the **IntelliStream** pipeline. Captures
Moodle activity and ships it to S3-compatible object storage for downstream
processing. **Capture writes no Moodle data rows** — it appends to an on-disk
buffer. The only database writes on that path are this plugin's own settings, and
only on rare events: a throttled note when capture is refused at the buffer cap,
and the record of a buffer directory change.

Replaces the legacy `local_intelliboard` plugin's in-Moodle capture; all
analytics processing (reports, charts, dashboards) moves downstream to the
IntelliStream middleware / per-customer warehouse and is built + rendered in the
**supernova** cloud Builder — **not** in Moodle. This mirrors the data-layer
role of the ancestor `local_intellidata`.

## Status

`0.9.33-intellistream+m39` · `MATURITY_BETA` · requires Moodle **3.9** (2020061500).

Implemented: event capture, on-disk buffering + rotation, S3 ship, page-dwell
(time-on-task) capture, media time-on-task, admin/admin-action capture toggles,
bulk entity backfill + scheduled refresh, optional payload encryption
(intellidata parity), a pull-style webservice surface (`pull_export` /
`get_status`), and optional Blackboard Collaborate attendance pull.

## Record kinds

Every buffer record carries a `record_type`:

| `record_type`     | Source                          | Notes |
|-------------------|---------------------------------|-------|
| `event`           | `classes/observer.php`          | One per Moodle event (hot path). |
| `page_dwell`      | `dwell.php` (beacon endpoint)   | Time-on-page, one per page visit. |
| `entity_snapshot` | `classes/exporter.php`          | One per Moodle core table row (backfill/refresh). |
| `exception` / `syslog` | `classes/datatypes/*`, observers | Error + syslog capture (intellidata parity). |

## Pipeline

```
Moodle events ─▶ buffer (append-only JSONL) ─▶ shipper (closed-file sweep)
              ─▶ S3-compatible object storage ─▶ IntelliStream middleware ─▶ warehouse
reports/charts/dashboards: built + rendered in supernova (reads the warehouse) — not in Moodle
```

## Configuration

All runtime config is set in **Site administration → Plugins → Local plugins →
IntelliStream** and stored in Moodle's DB (read via `get_config('local_intellistream', …)`).
**No secrets are hardcoded in source** and there are no real endpoints/keys in
defaults. `settings.php` is the full key reference (each setting carries a name
and description shown in the admin UI); the secret keys (S3 `secretkey`,
`collab_secret`, `encryption_key`) are stored masked
(`admin_setting_configpasswordunmask`).

## Layout

```
local/intellistream/
├── version.php                 plugin metadata
├── settings.php                admin settings (all config; empty/secret defaults)
├── status.php                  admin-only operational status page
├── logs.php                    admin log viewer
├── refetch.php                 targeted re-fetch admin page
├── dwell.php                   page-dwell beacon endpoint (no core-DB write)
├── lti.php, launch.php         LTI consumer page and signed launch
├── webhook.php                 signed control-plane command endpoint
├── lib.php                     before_footer hook — injects the dwell AMD module
├── amd/{src,build}/            dwell.js, lti.js (source + build)
├── templates/                  LTI view and launch templates
├── pix/                        icons (i/area_chart.svg)
├── tests/                      PHPUnit tests; tests/cli/ holds CLI smoke scripts
├── cli/                        backfill + maintenance CLI scripts
├── config/                     datatypes config admin pages
├── lang/en/                    language strings
├── db/                         events, tasks, access, services, caches, hooks,
│                               install/upgrade/uninstall
└── classes/
    ├── observer.php            hot-path event capture
    ├── buffer.php              append-only JSONL buffer, capacity measurement
    ├── shipper.php             closed-file sweep + S3 ship (batching, isolation)
    ├── s3_client.php           hand-rolled SigV4 S3 client
    ├── exporter.php            entity exports: 15-minute lane, census, catalogs
    ├── sweep.php               daily verification sweep
    ├── change_ledger.php       sweep fingerprints (what already reached storage)
    ├── backfill.php            one-time historical load
    ├── targeted_refetch.php    re-send a window of chosen entities
    ├── webhook_commands.php    control-plane commands behind webhook.php
    ├── config.php              typed config accessors
    ├── health.php              host-load gate
    ├── clock.php               timestamps
    ├── dwell_quota.php         page-time capture rate limit (per user)
    ├── exception_quota.php     error-report capture rate limit (per address, site-wide)
    ├── hook_callbacks.php      Moodle hook callbacks
    ├── admin/                  admin setting types (local-only, secret, buffer dir, ...)
    ├── collab/                 Blackboard Collaborate API client
    ├── datatypes/              exception/syslog datatypes (intellidata parity)
    ├── external/               pull_export + get_status webservices
    ├── helpers/                settings, custom menu and LTI role helpers
    ├── observers/              entity and exception observers
    ├── output/                 renderers, admin forms, LTI output
    ├── repositories/           data access (datatype config)
    ├── services/               config, discovery, encryption, log, LTI, plugin report
    ├── task/                   ship_events, refresh_entities, discover_dynamic_tables,
    │                           historical_backfill, copy_intelliboard_tracking,
    │                           collab_sync, and the backfill / re-fetch / LTI role
    │                           ad-hoc tasks
    └── privacy/provider.php    declares the off-site export
```

## Install

Copy into a Moodle install at `local/intellistream/`, then:

```
php admin/cli/upgrade.php --non-interactive
```

Then configure under Site administration → Plugins → Local plugins → IntelliStream
(at minimum the S3 connection; collab is optional). After changing `version.php`,
re-run the upgrade so Moodle re-reads `db/services.php` and `db/access.php`.
Regenerate the AMD build with `grunt amd` if you edit `amd/src/*`.

## One-time backfill

```
php local/intellistream/cli/backfill.php
```

The `refresh_entities` task runs every 15 minutes and must stay enabled: it sends
changed rows, and runs the daily verification sweep that sends tables without a change
timestamp and the list of ids that lets deleted records be removed.

## Security / privacy

- No Moodle data rows written on the capture hot path; the buffer is on-disk (see
  the top of this file for the two rare settings writes).
- No hardcoded secrets, endpoints, IPs, or PII in source; all sensitive config is
  admin-settings-driven with empty defaults.
- `classes/privacy/provider.php` declares the off-site data export for GDPR.
- Privacy export and erasure of records still in the local buffer match a user on
  each record's subject: `userid`, and for some entities another column (the user
  row itself, both parties of a message, an attendance log's student, a tag's tagger,
  and the user a profile tag is about). Most columns that name who acted on a row
  (`usermodified`, a grader, a modifier) are not matched. A matched record is removed
  whole, so a record with two subjects goes with either one's erasure: a legacy
  `message` row goes when either party is erased (a 4.x `messages` row is matched on
  its sender only), and a profile tag when the tagger or the tagged user is. Known
  limits:
  - only records still in the local buffer are covered; what was already sent is
    outside this plugin's erasure;
  - a message's recipients are not in the buffered row;
  - a `userid` that names an acting user (a teacher's grading step in a question
    attempt) is matched as that user, so that teacher's erasure removes it;
  - an entity whose subject is only a foreign key (for example competency evidence)
    is not matched, and a discovered or custom whole-row table is matched on
    `userid` only;
  - a file another process is still writing at that moment is skipped, so records
    in it can still be sent.
- A storage endpoint or proxy whose request-size limit is far below one batch
  (8 MiB) still receives the data, in halves, but each send run reports the refusal
  (`s3_other_4xx`); remembering the size last accepted is planned for a later
  release.

## Upgrading from earlier releases

- `sweeprefreshpasses` now defaults to 0 (off). The setting first appeared in 0.9.32,
  whose upgrade stored its then-default of 7. A stored value is kept, so a site
  coming from 0.9.32 still re-sends unchanged records every 7 passes until an
  administrator sets it to 0; a site coming from an earlier release gets 0.
- Discovered-table export (`dynamicdiscoveryexport`): a site that already stores a
  value keeps it. A site coming from 0.9.28-0.9.32 with no stored value is set to on
  if it has an enabled discovered table (it was exporting them), off otherwise. An
  enabled discovered table that is also a built-in entity (an IntelliCart table,
  say) counts too,
  because those releases exported it whole-row; with the switch on, tables
  discovered later are exported as well. A site coming from 0.9.27 or earlier
  with no stored value starts off, and the upgrade says how many discovered tables stop being exported. A table added by
  hand as a custom table is no longer re-marked as discovered; one an earlier
  release already marked stays marked.
