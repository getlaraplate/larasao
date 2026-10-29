<p>&nbsp;</p>
<p align="center">
	<a href="https://github.com/getlaraplate" target="_blank">
		<img src="https://raw.githubusercontent.com/getlaraplate/brand/main/logos/blueprint/sao.svg" width="160" alt="LaraSAO" />
    </a>
</p>
<p>&nbsp;</p>
<p align="center">
    <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
    <img src="https://img.shields.io/badge/PHP-8.5+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+">
    <img src="https://img.shields.io/badge/License-GNU_AGPL_v3-green?style=for-the-badge" alt="GNU AGPL v3 License">
</p>
<p>&nbsp;</p>

# Laraplate Simply Another Orchestrator Module

> ⚠️ **Caution**: This package is a **work in progress**. **Don't use this in production or use at your own risk**—no guarantees are provided... or better yet, collaborate with me to create the definitive Laravel boilerplate; that's the right place to introduce your ideas. Let me know your ideas...

## Table of Contents

-   [Description](#description)
-   [Installation](#installation)
-   [Configuration](#configuration)
-   [Current Status](#current-status)
-   [Roadmap](#roadmap)
-   [Scripts](#scripts)
-   [Contributing](#contributing)
-   [License](#license)

## Description

SAO — **Simply Another Orchestrator** — is a correlation engine between code, errors and work.

It ingests already-selected events from third-party systems, correlates them to a project and a deployed version, and turns them into tracked work. It is not a log aggregator, not an APM and not a CI runner.

With no connection configured, SAO is a complete standalone ticketing system. Version control systems, log sources and external issue trackers are optional, independently switchable integrations provided by drivers.

The roadmap is delivered in slices: the ticketing core, the driver framework with its bundled connectors, ingest and the later slices marked done in the Roadmap are in place.

## Installation

If you want to add this module to your project, you can use the `joshbrw/laravel-module-installer` package.

Add repository to your `composer.json` file:

```json
"repositories": [
    {
        "type": "composer",
        "url": "https://github.com/getlaraplate/laracore.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/getlaraplate/larasao.git"
    }
]
```

```bash
composer require joshbrw/laravel-module-installer getlaraplate/laracore getlaraplate/larasao
```

Then, you can install the module by running the following command:

```bash
php artisan module:install Core
php artisan module:install SAO
```

## Configuration

The module configuration is automatically mapped as `sao.*` when the module is active.
Configuration file: `Modules/SAO/config/config.php`.

Runtime tunables are Settings (module `SAO`, group `sao`), edited like any other Setting and applied
without a restart. The seeder (`SAODatabaseSeeder`) creates them with the defaults below and a
re-seed keeps any value an operator changed. Windows, thresholds and the regression factor are
floored at 1 where they are read, so a stored `0` behaves as `1`; the one exception is the occurrence cap, where `0` or a negative value disables the cap.

| Setting | Default | Purpose |
|---------|---------|---------|
| `signals.max_occurrences_per_window` | `1000` | Per-group occurrence cap within the rolling window (loop protection, layer 2); `0` or a negative value disables the cap. |
| `signals.window_minutes` | `60` | Length of that rolling window, in minutes. |
| `signals.auto_open.enabled` | `false` | Whether `sao:signals:auto-open` is registered on the scheduler (read when a console process boots). The command is always runnable manually. |
| `signals.auto_open.min_occurrences` | `1` | Minimum occurrences before a signal auto-opens a ticket. |
| `release_health.window_days` | `7` | How many days after a release its signals are attributed to it when judging release health. |
| `release_health.regression_rate_factor` | `1.5` | A pre-existing signal counts as regressed only when its in-window count exceeds its baseline count by this factor (minimum `1.0`). |
| `release_health.regression_min_occurrences` | `3` | Minimum in-window occurrences before a pre-existing signal can be flagged as regressed (noise floor). |
| `release_health.regressed_new_signals` | `3` | Number of brand-new signals in the window that on their own tip the verdict to `regressed`. |
| `closure.auto_close.enabled` | `false` | When on, `sao:closure:run` lets a satisfied `close` policy actually close the ticket; when off it only proposes (records a `ClosureAudit`, no state change). |

Environment variables (all optional; the defaults are production-safe):

| Variable | Default | Purpose |
|----------|---------|---------|
| `SAO_SYNC_ENABLED` | `true` | Whether `sao:sync:issues` is registered on the scheduler. Set `false` to keep inbound polling manual-only. |
| `SAO_SYNC_CRON` | `0 * * * *` | Cron expression for the scheduled inbound issue poll. |
| `SAO_HEALTH_SCHEDULE` | `false` | Whether `sao:connection:health` runs on the scheduler. The command is always runnable manually. |
| `SAO_HEALTH_CRON` | `*/15 * * * *` | Cron expression for the scheduled connection health probe. |
| `SAO_RETENTION_SCHEDULE` | `false` | Whether `sao:prune` runs on the scheduler. The command is always runnable manually (with `--dry-run`). |
| `SAO_RETENTION_CRON` | `0 3 * * *` | Cron expression for the scheduled prune. |
| `SAO_RETENTION_OCCURRENCES_DAYS` | `90` | Delete signal occurrences older than this many days (the signal and its counters are kept). |
| `SAO_RETENTION_INGEST_DAYS` | `30` | Delete ingest-event audit rows older than this many days. |
| `SAO_RETENTION_DEPLOYMENTS_DAYS` | `180` | Delete deployments older than this many days, keeping the latest per environment so the census stays valid. |
| `SAO_RETENTION_CLOSED_PROJECT_DAYS` | `30` | Grace period after a project is deactivated before its heavy data (signals, occurrences, ingest, deployments, tickets) is purged; the project, environments and releases are kept. |

## Code-to-work attribution & closure

SAO derives "is this ticket actually fixed, and everywhere?" from evidence, not a
human flag. It reads ticket keys out of commit messages and merged pull-request
text and records a `ChangeRef` per (ticket, artefact): a closing verb
(`fixes|closes|resolves…`, configurable via `sao.attribution.closing_verbs`)
marks a **fix**, any other reference a **mention**. Only fixes count as resolution
evidence. A fixing commit is attributed to the release that carries it via the
`releases` capability (`firstTagContaining`), classifying the tag as stable →
`shipped` or a candidate (RC) → `promised` by a semver heuristic
(`sao.attribution.prerelease_markers`). The release **version is always the
normalized stable label** (`v1.4.0-rc.1` → `1.4.0`), so a candidate records the
future stable version — the release stays `announced` (the stable tag does not
exist yet), realized only by candidate tags. `php artisan sao:releases:sync
{connection?}` lists a `releases` binding's tags and **promotes** such a release
to `shipped` once its stable tag is actually cut — deterministically, rather than
depending on which tag `firstTagContaining` returns.

Two transports feed the same writer:

- **Pull** — `php artisan sao:vcs:scan {connection?} {--range=main}` walks a `vcs`
  binding's commits; idempotent, so it also backfills history.
- **Push** — a `code` webhook at `POST api/v1/webhooks/{connection}` (the shared
  route, branched by driver capability). Drivers: a generic `webhook-code` (token
  in `X-Code-Token`) and `github-pull-request` (GitHub's `pull_request` webhook,
  HMAC-verified, only *merged* PRs count).

`php artisan sao:closure:run {project?} {--env=}` evaluates active closure policies
over non-terminal tickets. By default it only **proposes** a close; turn the
`closure.auto_close.enabled` Setting on to let a satisfied `close` policy actually close the
ticket (through `WorkflowService`, audited and auto-reversible on recurrence).

## Reprocessing ingest events

An ingest event that did not become a signal (`discarded`, `uncorrelated` or
`failed`, from a `profile` or `logs` delivery) can be replayed on its own row once
a correlation rule, source profile or `logs` binding has been fixed:

```bash
php artisan sao:ingest:reprocess {event?} {--status=} {--connection=} {--since=} {--dry-run}
```

`--dry-run` reports what each event would become and writes nothing. The ingest
event list in the backoffice offers the same as a **Reprocess** row and bulk
action, gated by the `reprocess` permission. The occurrence keeps the event's
original time, and a `Resolved` signal is never reopened by a replay. Events older
than `SAO_RETENTION_INGEST_DAYS` have been pruned and can no longer be reprocessed.

## Migrating from an external tracker

To switch a project from an external issue tracker to Laraplate, import its
history through the existing `issues` binding:

```bash
php artisan sao:tracker:import "Acme Jira" --project="Web" --scope=open --cutover
```

- `--scope=open` imports only issues still active — an issue whose remote status
  maps (through the binding's `status_map`) to a terminal category
  (closed/rejected) is skipped; `--scope=all` (default) imports everything. An
  unmapped remote status is treated as open, so nothing active is dropped.
- The import is **idempotent** (matched by `TicketLink`) and **resumable**: it
  persists an `ImportRun` per (binding, scope) that stores the driver's next-page
  cursor and running counts, saved after every page. An import cut short by a
  crash, a redeploy or a requeue resumes from the exact page it stopped on instead
  of re-walking from the start; the run flips to `completed` only when the page
  walk is exhausted, and re-importing a finished migration opens a fresh run.
  `--queue` dispatches one background job per binding.
- When the tracker's driver also implements `IssueHistoryCapability`, each imported
  ticket gets its **comment thread and attachments** too — comments as `system`
  `TicketComment`s keyed by the remote comment id, attachments stored in the
  ticket's `attachments` media collection keyed by the remote attachment id — both
  idempotent, so a resumed or re-run import never duplicates them. The driver
  downloads attachment bytes itself (only it holds the connection credentials), so
  the importer never makes an unauthenticated request.
- `--cutover` makes SAO authoritative afterwards by flipping the binding's sync
  direction — `disabled` by default (external tracker abandoned) or
  `--cutover-direction=outbound` to keep pushing changes back during a transition.
  The `TicketLink`s are kept as provenance.

## Current Status

Slice 1a — the internal ticketing core — is implemented, and so are the later slices
marked done in the Roadmap. SAO is usable as a standalone tracker with **no connection
to any external system**: connections are optional and provided by drivers.

-   Projects with an immutable key prefix and per-project ticket keys (`SAO-123`),
    allocated under a row lock
-   Global ticket statuses carrying a canonical category — open, in progress,
    resolved, closed, rejected — which later phases map against instead of names
-   Workflow schemes shared across ticket types, with transitions enforced by the
    domain service rather than merely hidden in the interface, and an override
    gated by its own permission
-   Ticket types enabled per project, optionally overriding the workflow scheme
    for one project alone
-   Tickets with optimistic locking, comments distinguishing people from
    automation, and a timeline merging comments with Core's version history
-   Ticket enrichment (1b): due dates (with `overdue`/`dueWithin` scopes),
    project-scoped labels, watchers (record-only), attachments on the Core-owned
    media library, typed ticket-to-ticket relations (blocks/duplicates/relates),
    and advanced search with saved filters
-   Authorization entirely Laraplate's: permissions through `PermissionName`, and
    row-level visibility through Core's ACL filters — an ACL restricting the view
    permission to one project hides the others, with no mechanism of SAO's own
-   A per-project board (1c): tickets in status-ordered columns, moved through
    the workflow-allowed transitions — a read model over `visible()`, no new table
-   Filament surfaces for projects, statuses, types, workflow schemes, tickets
    (with the 1b enrichment: due date, labels, watchers, attachments, relations
    manager, table filters), the board page, a Connection resource (write-only
    credential), a project Integrations relation manager for bindings, and a
    Signal resource (state-editable, machine fields read-only) with a read-only
    occurrences relation manager

Not yet present: HTML5 drag-and-drop on the board (needs an approved kanban
package; moves are action-based today).

## Roadmap

Design: `docs/superpowers/specs/2026-07-31-sao-module-design.md` in the application repository.

-   Phase 1a — internal ticketing core and base Filament surfaces (**done**)
-   Phase 1b — labels, watchers, attachments, due dates, ticket relations, search
-   Phase 1c — kanban board
-   Phase 2 — shared fingerprinting in Core, error signals, internal log source, loop protection
-   Phase 3 — driver framework, connections, capabilities and the first external issue tracker
-   Phase 4 — source profiles, generic webhook ingest and replay
-   Phase 5 — version control and release capabilities, code-to-work references, version census
-   Phase 6 — fix propagation and evidence-based closure policies
-   Phase 7 — second driver wave
-   Phase 8 — AI, as a hard module requirement
-   Phase 9 — Vue surfaces
-   Release health & deploy ingest — post-hoc, correlation only (never a rollout
    gate). `ReleaseHealthService` judges whether a release introduced new or
    worsening signals versus the previous release over an equal window;
    `DeploymentIngestService` records a durable, idempotent deploy history
    (`sao_deployments`) that makes the environment version census a projection and
    gives release health a precise per-deploy anchor. Plan:
    `docs/plans/release-health-and-deploy-ingest.md`.

### Deployments

A deployment is recorded by the ingest — never by hand — deduped by
`(connection, external_id)` so a re-delivery advances the same record. A terminal
`succeeded` deployment advances the environment's `current_version`; its
`finished_at` is the window anchor release health reads from. Record one without
any external integration (CLI-only CD steps, replay) with:

```bash
php artisan sao:deploy:record {project} {version} --env=production --status=succeeded --external-id=ci-run-42
```

Deploys can also arrive as a **push webhook** through the `deploy` capability at
`POST api/v1/webhooks/{connection}` (the same endpoint the `logs` push uses; the
route branches by the connection's driver capability). Two drivers ship: a generic
`webhook-deploy` (shared token in the `X-Deploy-Token` header) and
`github-deployment` (GitHub's `deployment_status` webhook, HMAC-SHA256 signature).
Each delivery is verified by the driver, unpacked into deploy events, recorded via
the same `DeploymentIngestService` (deduped, census-projecting), and audited as an
`IngestEvent`. The secret lives on the connection.

**API switch for webhooks.** `POST api/v1/webhooks/{connection}` is an `/api` route, so it
answers `403` while the Core `expose_api` setting is off (the default): an installation that
receives push webhooks (`logs`, `code`, `deploy`) must turn the API on. The route runs as the
anonymous user, under the per-address `api` rate limit (`core.api.rate_limit_per_minute`, 600 a
minute), and the driver's own signature or token check authenticates the delivery; a delivery
without a valid one is refused by the route. The route is not marked self-authenticated, so a sender
that also sends an `Authorization: Bearer` header is treated as presenting a Laraplate API token
and gets `401`: the shipped drivers verify custom headers (`X-Code-Token`, `X-Deploy-Token`,
`X-Hub-Signature-256`, ...), not `Authorization`. See `Modules/Core/docs/rag/API_ACCESS_CONTROL.md`.

## Scripts

Run commands from the **SAO module root** after `composer install`.

```bash
# Run all tests and quality checks
composer test

# Run specific checks
composer test:unit
composer test:type-coverage
composer test:lint
composer test:types
composer test:refactor
```

```bash
# Local formatting (dirty files only from project root)
vendor/bin/pint --dirty
```

## Contributing

If you want to contribute to this project, follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or correction.
3. Send a pull request.

## License

SAO Module is open-sourced software licensed under the [GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html).

Copyright (c) 2025 Marco P. The full licence text is in [LICENSE](LICENSE).
