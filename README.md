# local_intellistream

Moodle event-capture + export adapter for the **IntelliStream** pipeline. Captures
Moodle activity and ships it to S3-compatible object storage for downstream
processing. **Writes zero rows to Moodle _core_ tables** on the hot path —
capture is append-only to an on-disk buffer.

Replaces the legacy `local_intelliboard` plugin's in-Moodle capture; all
analytics processing (reports, charts, dashboards) moves downstream to the
IntelliStream middleware / per-customer warehouse and is built + rendered in the
**supernova** cloud Builder — **not** in Moodle. This mirrors the data-layer
role of the ancestor `local_intellidata`.

## Status

`0.9.30-intellistream` · `MATURITY_BETA` · requires Moodle **4.1** (2022112800).

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
├── dwell.php                   page-dwell beacon endpoint (no core-DB write)
├── lib.php                     before_footer hook — injects the dwell AMD module
├── amd/{src,build}/dwell.js    page-dwell capture (source + build)
├── cli/                        backfill + maintenance CLI scripts
├── config/                     datatypes config admin pages
├── lang/en/                    language strings
├── db/                         events, tasks, access, services, install/upgrade
└── classes/
    ├── observer.php            hot-path event capture
    ├── buffer.php              append-only JSONL buffer
    ├── shipper.php             closed-file sweep + S3 ship
    ├── s3_client.php           hand-rolled SigV4 S3 client
    ├── exporter.php            bulk entity snapshot exporter
    ├── config.php              typed config accessors
    ├── health.php              host-load gate
    ├── collab/                 Blackboard Collaborate API client + sync
    ├── datatypes/              exception/syslog datatypes (intellidata parity)
    ├── external/               pull_export + get_status webservices
    ├── helpers/                settings + utility helpers
    ├── observers/              event observers (exceptions, etc.)
    ├── output/                 renderers + admin forms
    ├── repositories/           data access
    ├── services/              config_service, encryption_service, log_service
    ├── task/                   ship_events, refresh_entities, collab_sync,
    │                           historical_backfill, copy_intelliboard_tracking
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

A periodic `refresh_entities` task is registered **disabled** — enable it and set
a cadence (weekly suggested) under Site administration → Server → Scheduled tasks.

## Security / privacy

- No writes to Moodle core tables on the capture hot path; buffer is on-disk.
- No hardcoded secrets, endpoints, IPs, or PII in source; all sensitive config is
  admin-settings-driven with empty defaults.
- `classes/privacy/provider.php` declares the off-site data export for GDPR.
