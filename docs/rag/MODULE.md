<p>&nbsp;</p>
<p align="center">
	<a href="https://github.com/getlaraplate" target="_blank">
		<img src="https://raw.githubusercontent.com/swolley/images/refs/heads/master/logo_laraplate.png?raw=true" width="400" alt="Laraplate Logo" />
    </a>
</p>
<p>&nbsp;</p>

> ⚠️ **Caution**: This package is a **work in progress**. **Don't use this in production or use at your own risk**—no guarantees are provided.

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

Runtime tunables are Settings of module `SAO` (group `sao`), seeded by `SAODatabaseSeeder` and
overlaid onto `config('sao.*')`; an operator's value survives a re-seed. Windows, thresholds and
the regression factor read as at least `1` (`1.0` for the factor), so a stored `0` behaves as `1`.

| Setting | Default | Purpose |
|---------|---------|---------|
| `signals.max_occurrences_per_window` | `1000` | Per-group occurrence cap within the rolling window. |
| `signals.window_minutes` | `60` | Length of that rolling window, in minutes. |
| `signals.auto_open.enabled` | `false` | Registers `sao:signals:auto-open` on the scheduler (read at console boot). |
| `signals.auto_open.min_occurrences` | `1` | Occurrences a signal needs before it opens a ticket. |
| `release_health.window_days` | `7` | Days after a release whose signals are attributed to it. |
| `release_health.regression_rate_factor` | `1.5` | Factor over the baseline count that marks a signal as regressed. |
| `release_health.regression_min_occurrences` | `3` | In-window occurrences needed before a signal can be regressed. |
| `release_health.regressed_new_signals` | `3` | New signals in the window that tip the verdict to `regressed`. |
| `closure.auto_close.enabled` | `false` | Lets a satisfied `close` policy close the ticket instead of only proposing it. |

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
    occurrences relation manager. Filament is the configuration, monitoring and
    maintenance backoffice, not where tickets are worked: the ticket view page shows
    type and status by name, the timeline read-only, and the workflow-allowed
    transitions for fixing a stuck ticket; the list filters by status category,
    priority, label and overdue. Commenting and picking a saved filter are features
    of the SAO application in `laraplate-ui`, built on `TicketComment::postFor()` and
    `SavedFilter`

Not yet present: HTML5 drag-and-drop on the board (needs an approved kanban
package; moves are action-based today).

## How it works (developer)

Configuration is **data, not code**: statuses, workflow schemes, transitions and
ticket types are rows, so a deployment can reshape the process without a release.
A ticket's permitted moves come from the workflow scheme of its `(project, type)`
pair, with an optional per-project scheme override gated by its own permission.

All orchestration lives in domain services, never in a UI layer, so the Filament
panel, a future API and phase 2's automation share one enforcement path:

| Service | Responsibility |
|---------|----------------|
| `TicketCreationService::open()` | The single path that opens a ticket. |
| `WorkflowService` | The only path to a status change; transitions are enforced here (not merely hidden in the UI) because the API and automation move tickets too. |
| `TicketKeyAllocator` | Allocates the per-project key (`SAO-123`) under `lockForUpdate`; gaps are accepted (a rolled-back transaction loses its number) rather than serializing every creation. |
| `TicketQueryService` | The only sanctioned read path. Core's `HasACL` global scope is an unimplemented TODO, so raw `Ticket::query()` would silently bypass row-level visibility — every read goes through here or Core's CRUD layer. |
| `TicketTimelineService` | Merges comments and Core version history into one ordered stream; the single place that knows where history comes from, so a future activity table would replace only its second half. |
| `TicketSearchService` | Filters tickets by a `TicketSearchCriteria` (text, status, type, priority, assignee, label, due window, overdue). It builds strictly on `TicketQueryService::visible()`, so a search never surfaces a hidden ticket. A `SavedFilter` persists a criteria set and round-trips back into a `TicketSearchCriteria` for reapplication. |
| `TicketBoardService` | The board read model (1c): for one project, the status-ordered columns (`BoardColumn`) each carrying its visible tickets. A projection over `visible()` — no board/column/card is persisted. The Filament board page moves cards through `WorkflowService`, offering only workflow-allowed transitions. |

Ticket enrichment (1b) is model-level: `due_at` with `overdue`/`dueWithin`
scopes; a project-scoped `Label` (unique name per project) via a
`sao_ticket_label` pivot; `watchers()` with idempotent `watch()`/`unwatch()`
(notification delivery is out of scope); `attachments` on the Core-owned media
library (`HasMedia`), so SAO depends only on Core; and typed `TicketRelation`
records (`TicketRelationType`: blocks/duplicates/relates) resolved through
`relatedVia()` and `inverselyRelatedVia()`, rejecting self-relations.

History is therefore a **read model** over Core's versioning plus comments — there
is no activity table. Authorization is entirely Laraplate's: permissions through
`PermissionName`, row-level visibility through Core's ACL filters (an ACL that
restricts the view permission to one project hides the others, with no mechanism
of SAO's own).

### SPA domain actions

The same services are reachable from a SPA without a bespoke route each:
`SaoDomainActionRegistrar` (invoked from `SAOServiceProvider::boot()`) maps
`{model, action}` onto them in Core's `DomainActionRegistry`, so a client invokes
one through the generic endpoint `POST /app/crud/{action}/{module}/{entity}`
(route `core.crud.domain-action`), passing the target record's `id` in the JSON
body. `SaoModelPolicy` gates each action on its seeded
`{connection}.{table}.{action}` permission (mirroring `MesModelPolicy`); the
deeper state rules stay in the services, and the actions never bypass them (a
status change still goes through `WorkflowService`). The registered actions are:

| Action | Entity | Service | Payload (besides `id`) | Returns |
|--------|--------|---------|------------------------|---------|
| `transition` | `tickets` | `WorkflowService::transition` | `to_status_id` | the moved `Ticket` |
| `transitions` | `tickets` | `WorkflowService::availableTransitions` | — | the moves the ticket may make from its current status: `[{to_status_id, label, allowed}]` (`allowed` reflects the transition's `required_permission`). A read for a kanban's guided drop; gated by the same `transition` permission. |
| `close` | `tickets` | `ClosureApplicationService::apply` | `policy_id`, optional `reporting_environment` | the `ClosureAudit` (null when the policy does not hold) |
| `accept` | `ownership_suggestions` | `OwnershipSuggestionApplier::apply` | — | the assigned `Ticket` |
| `health` | `connections` | `ConnectionHealthService::check` | — | `{healthy, detail, health_state, last_checked_at}` |
| `replay` | `ingest_events` | `IngestReplayService::dryRun` | optional `profile_id` (defaults to the event's recorded profile) | the dry-run projection (writes nothing) |

**Assigning is not one of them.** `sao_tickets.assign` was seeded alongside `transition` and
`transition_override` but never given a handler, a policy method or a gate, so no code could
reach it: the dispatcher resolves the handler before authorizing, and an unregistered action
is a 404. The assignee is a column, written through `update` like any other, and the one
sanctioned assignment path is accepting an ownership suggestion, which carries its own
`accept` permission. The dead permission was dropped rather than left on the role screen
looking like a rule on who may assign.

### Application-content provider (assistant retrieval)

SAO tickets are exposed to the in-app assistant as the `sao.tickets`
application-content source (`SaoApplicationContentRetrievalProvider`, registered
into Core's `ApplicationContentRetrievalProviderRegistry` from
`SAOServiceProvider::boot()`). The AI module discovers the source through that
registry and the assistant's module scope, with no SAO-specific hook: adding the
provider is the whole integration.

A `Ticket` is **Core-searchable** (`Modules\Core\Search\Traits\Searchable`) over a
denormalized document — `title` and `description` for full text, plus the
`{id, name}` of `project`, `type`, `status`, `assignee`, `reporter`, `watchers`
and `labels` — so a natural-language query matches on the people, project, type
and status names, not only the title. Denormalized names go stale in the index
until reindex; that affects **ranking only**, because the projection reads fresh
DB records.

Authorization is the same rule as every other read: the engine is a **relevance
oracle**, never trusted for access. `retrieve()` ranks candidate ids with
`AdvancedSearchService`, then re-authorizes them by rehydrating through
`TicketQueryService::visible()->whereKey($ids)` (Core's ACL filters) before
projecting — a ticket the requester may not see is dropped at rehydration, and
the search index carries no ACL data. The lexical fallback (a bounded `LIKE` on
`title`/`description` when the engine yields nothing) runs over the same
`visible()` query, so no path can surface a hidden ticket. Every query is
bounded; a failed engine degrades to the fallback or an authorized-empty result,
never an unbounded scan.

The **safe projection** (`SaoTicketEvidenceProjector`) exposes only title,
excerpt, `recordKey` (the ticket key) and the authorized `/app/sao/tickets/{key}`
reference; free-text fields are reduced to plain text and bounded. Comments,
attachments, internal ids and ACL data never reach a hit. Ticket comments and
attachments, a SAO assistant evaluation dataset, structured filter arguments, and
cross-module **global tags** are deliberately out of scope (labels already give
tag-like matching); see
`docs/superpowers/specs/2026-08-29-sao-application-content-provider-design.md`.

## Driver framework (phase 3a)

The integration layer's foundation is in place, and nine concrete external `issues` drivers ship in `Modules\SAO\Drivers\External` (all registered by default in `config('sao.drivers.registered')`): **Redmine** (`X-Redmine-API-Key`, `remoteIdentifier` = project id), **Jira** Cloud (Basic email+token, JQL by project key), **GitHub** (bearer token, `owner/repo`, `Link`-header pagination), **GitLab** (`PRIVATE-TOKEN`, project id, `X-Next-Page` pagination), **Bitbucket** Cloud (Basic username+app-password, `workspace/repo`, body `next` pagination), **Gitea** (`Authorization: token`, `owner/repo`, `Link`-header pagination over its GitHub-shaped API), **YouTrack** (bearer permanent token, `remoteIdentifier` = project short name, `$skip`/`$top` pagination, status/priority/assignee read from named custom fields), **Azure DevOps** Boards (Basic PAT, `remoteIdentifier` = project name, the documented WIQL-ids-then-batch-read listing with the cursor an offset into the id list, JSON-Patch writes), and **Linear** (GraphQL, personal API key in `Authorization`, `remoteIdentifier` = team key, `pageInfo.endCursor` pagination; the canonical `remote_id` is the issue UUID and the human `identifier` is `key`). Each maps its remote representation to `NormalizedIssue`, translates statuses only through the binding-provided map (never hardcoded), and passes the `issues` conformance suite against a network-free `Http::fake()`. The three Git hosts (GitHub, GitLab, Bitbucket) additionally serve the **`vcs`** (commits, compare, file-at-ref, open pull/merge request) and **`releases`** (tags, bounded first-tag-containing) capabilities, each passing the `VcsConformance` and `ReleasesConformance` batteries over an `Http::fake()`. Normalized commits carry the author shape — `author` (the account handle, null on GitLab which exposes no username and on unlinked GitHub commits), `author_name`, `author_email` — which `VcsConformance` asserts on every commit; this is the raw material for blame/recent-touch ownership evidence. Ten **`logs`** drivers ship too, all push/webhook and all sharing the `LogsDriverBoilerplate` trait (capabilities, ingest modes, secret-based `healthCheck()`, HMAC/token verification, safe decode). They split into two families. The **error trackers** carry a **native** grouping key (`carriesNativeGroupKey()` true; `GroupKeyResolver` namespaces it `<source>:<id>`): **Sentry** (HMAC-SHA256 signature, `sentry:<id>`), **GlitchTip** (`X-GlitchTip-Token`, unpacks `data.issue.id`), **Rollbar** (`X-Rollbar-Token`, `data.item.counter`), **Bugsnag** (`X-Bugsnag-Token`, `error.errorId`), and **Honeybadger** (`X-Honeybadger-Token`, `fault.id`). The **log aggregators** carry **no** native key (`carriesNativeGroupKey()` false) and let SAO fingerprint each event through its own chain: **Graylog** (shared token header, one event per backlog message), **Grafana** (`X-Grafana-Token`, one event per alert), **Datadog** (`X-Datadog-Token`), **Elastic** (`X-Elastic-Token`, message/reason or alerts), and **BetterStack** (`X-BetterStack-Token`, `data.attributes.name/cause`). A connection's live reachability is checked by `ConnectionHealthService` (resolve credentials → driver `healthCheck()` → record `health_state` + `last_checked_at`), surfaced by the `sao:connection:health {name?}` command and a "Test connection" action on the Filament `ConnectionResource`. Push deliveries for a `logs` connection arrive over a single unauthenticated endpoint, `POST api/v1/webhooks/{connection}` (`WebhookIngestController` → `DriverWebhookIngestService`): the delivery authenticates itself through the driver's own signature/token scheme (never framework auth), the driver `unpack()`s the raw body into canonical events, and each event is ingested into every project bound to the connection with the `logs` capability via `SignalIngestService`. Every ingested event becomes an `IngestEvent`, deduped per (connection, delivery, binding, index) so a re-delivery is recorded once and never re-ingested; a forged body is a 401 and never stored, while an authentic delivery that lands nowhere (no binding) is a 202 with an audited `Discarded` event so the sender stops retrying. The whole run is wrapped in the `PipelineContext` loop guard. Live-instance verification stays a manual/opt-in step (offline the drivers are proven by conformance over `Http::fake()`). A **driver** is registered code (`Modules\SAO\Drivers`); a **connection** (`Connection` model) is a configured instance of it. The registry is **open**: `DriverRegistry` is a singleton populated from `config('sao.drivers.registered')` and by any provider's `boot()` — adding a provider never requires editing SAO. Duplicate keys throw so a collision surfaces at boot.

A driver implements `DriverInterface` (key, capabilities, ingest modes, configuration schema, health check) plus one or more per-capability contracts — `IssuesCapability`, `VcsCapability`, `LogsCapability`, `ReleasesCapability`. Domain services depend on those capabilities, never on a concrete driver. Drivers operate on a resolved `ConnectionContext` (base URL + credentials), never on the Eloquent model, so `app/Drivers` stays free of persistence. Every capability list returns a `Page` and callers follow `nextCursor` to completion; the conformance suite includes a multi-page fixture so a first-page-only read fails. Status translation takes the binding-provided map, never a hardcoded one.

**Inbound polling (pull).** Trackers with no push transport are kept current by `IssueSyncPoller`: for a binding that syncs inbound over an `issues` driver, it pages the driver's issue list to completion (following `nextCursor`, with a page cap it reports rather than silently truncating) and reconciles every issue into SAO through `IssueSyncService::reconcile()` — the lookup-free upsert primitive `pull()` now also delegates to, so one already-listed issue never triggers a second fetch. The `sao:sync:issues {connection?}` command drives it over all inbound issues bindings (or one connection by name) and is scheduled by the module provider (`registerCommandSchedules()`), gated by `config('sao.sync.enabled')` with the cadence in `config('sao.sync.cron')`. It is safe to run with nothing configured — a binding must opt into an inbound `sync_direction` to be polled. Each reconcile returns an explicit `SyncOutcome`, tallied into a `SyncReport`.

**Credentials (F4).** A `Connection` holds only non-secret coordinates plus its secret via one of two paths: an encrypted, write-only `credential` column, or a `credential_ref` env/config key that overrides it. `ConnectionCredentialResolver` is the single path from a connection to its secret (ref wins, else the decrypted column, else it throws); the raw secret is never rendered back to a UI and never stored in Core settings. A connection may expose only a subset of its driver's capabilities, enforced on save.

**Conformance (spec §12).** `tests/Support/Conformance/*` are the reusable batteries a driver of a capability must pass; `InMemoryDriver` (test support) is a network-free reference driver that passes `issues` and `releases` and proves the registry → connection → resolver → capability stack runs offline. A driver is done when it passes conformance, not when it works.

## Roadmap

Design: `docs/superpowers/specs/2026-07-31-sao-module-design.md` in the application repository.

-   Phase 1a — internal ticketing core and base Filament surfaces (**done**)
-   Phase 1b — labels, watchers, attachments, due dates, ticket relations, search and saved filters (**done**, including the Filament surfaces)
-   Phase 1c — board: status-ordered columns per project, moves through the workflow (**done**; HTML5 drag-and-drop deferred, needs an approved kanban package)
-   Phase 2 — shared fingerprinting in Core, error signals, internal log source, loop protection (**done** at the ingest/grouping level: Core's `Logging\Fingerprint` rule chain + `Fingerprinter` (line excluded, value-position numerics), SAO's `PayloadFrameResolver`, the `Signal`/`SignalOccurrence`/`SignalAlias` models, `GroupKeyResolver` + `SignalIngestService`, and loop protection via `PipelineContext` + `InternalLogSource` + a per-group rate limiter. Real webhook transport/source profiles are phase 4. Ticket auto-open from a signal is now live: `SignalTicketOpener` opens a ticket on the project's default type (through `TicketCreationService`, automation origin) for an open, unlinked signal, idempotent by the persisted `Signal.ticket_id`; the `sao:signals:auto-open {project?}` command scans signals past the `sao.signals.auto_open.min_occurrences` threshold and is scheduled when `sao.signals.auto_open.enabled`)
-   Phase 3 — driver framework, connections, capabilities and external issue trackers (3a foundation **done**: registry, contracts, `Connection`, credential resolver, conformance suite; 3b **done**: `ProjectBinding`, `TicketLink`, the internal issues driver, `IssueSyncService`, and five concrete external `issues` drivers — **Redmine, Jira, GitHub, GitLab, Bitbucket** — each passing the issues conformance suite over an `Http::fake()`; 3b-ui **done**: a Filament Connection resource (write-only encrypted credential) and a project Integrations relation manager for bindings. Both transports are now live: inbound push over `POST api/v1/webhooks/{connection}` for `logs` connections, and scheduled inbound pull (`sao:sync:issues` → `IssueSyncPoller`) for `issues` bindings. Live-instance verification against a real remote stays a manual/opt-in step)
-   Phase 5 — `vcs`/`releases` capabilities on the Git-host drivers (GitHub, GitLab, Bitbucket): commits/compare/file-at-ref/open-PR and tags/first-tag-containing, each passing `VcsConformance` + `ReleasesConformance` over an `Http::fake()` (**done**). Phase 5b adds the correlation data (**done**): `ChangeRef` (a commit/PR/tag linked to a ticket), `Release`/`ReleaseTag`/`TicketRelease` (a product version, the stable/candidate tags realizing it, and tickets attributed as promised/shipped independent of workflow status), and `Environment` + `DeployCensusService` (passive `observe`, active `recordProbe`, TTL-based `isStale`, and a `census(project, ttl)` read model that answers "what runs where" with an honest freshness). Filament CRUD surfaces for releases (with a tags relation manager) and environments ship under the SAO group; live probe transport remains a follow-up
-   Phase 5b follow-on — affected version & version-maturity census (**done**): a ticket now carries the version **detected** at opening as well as the resolution version. Maturity is a property of the tag — `ReleaseTagKind` spans `alpha`/`beta`/`candidate`/`stable` by `precedence()`, and `Release::effectiveMaturity()` derives the highest, `null` when tagless. `Release` is the census row, permissively created as `ReleaseStatus::Observed` (hidden by the `curated` scope) from normalized log ingest (`VersionNormalizer` + `VersionCensusService`, deduped per `(project, version)`, unnormalizable strings kept raw and not promoted) or a human entry. `SignalOccurrence` records `affected_version`/`affected_release_id`; a ticket opened from a signal is attributed `affected` (`TicketReleaseState::Affected`, `Ticket::affectedRelease()`). Resolution stays stable-only: `TicketReleaseAttributor` requires a `stable` tag for `shipped` (`UnstableResolutionReleaseException`), while `affected` accepts any maturity. Spec/plan: `docs/superpowers/specs/2026-09-18-sao-affected-version-and-maturity-census-design.md`
-   Phase 4 — source profiles, generic webhook ingest and replay (**done** at the ingest level: `SourceProfile` (matchers + dot-path field bindings), `IngestEvent` (every delivery recorded with an explicit outcome — auditable silence), `WebhookIngestService` (dedupe by delivery id, profile selection, normalization, ordered `CorrelationRuleset` recording the winning rule, hand-off to `SignalIngestService`), and a pure dry-run `IngestReplayService`, reachable operationally via `sao:ingest:replay {event} {--profile=}` (replays a stored event against its recorded profile, or a chosen one, showing the would-be match/canonical/correlation/status without writing). Concrete Graylog/Sentry `logs` drivers were follow-ups. The inbound push transport is now live: `POST api/v1/webhooks/{connection}` (`DriverWebhookIngestService`) verifies the driver signature, unpacks the delivery, and ingests each event into the connection's `logs`-bound projects, deduped per delivery and audited as `IngestEvent`s. A **read-only** `IngestEventResource` (list + view, no create/edit — the outcome trail stays machine-written) surfaces every recorded delivery with its status, outcome, correlated connection/project/signal and the raw payload, and a writable `SourceProfileResource` (matchers repeater + field-bindings key-value) lets an operator author the generic-ingest profiles — so a new source is a form entry, not a code change)
-   Phase 5 — version control and release capabilities, code-to-work references, version census
-   Phase 6 — fix propagation and evidence-based closure policies (**done** at the deterministic-decision level): the persisted facts (`Signal.ticket_id`, `ChangeRef.merged_at`), `FixStatusResolver` (merged PR / shipped-release / where-deployed → the "already fixed on dev, deploy missing" read model), six independently-testable closure conditions over a pure `ClosureContext` (a candidate never satisfies `fix_released`), project-scoped `ClosurePolicy` (conditions as `{key, config}` json + action, `propose` the prudent default), `ClosureEvaluator` (AND semantics, empty set never satisfies), `ClosureAudit` + `ClosureAuditService` (records the "closed because"; a recurrence reopens and marks premature with "returned after"), and `TimeToTruthService`. `ClosureApplicationService` turns a satisfied policy into action: it resolves the context, evaluates the policy, and for a `close` policy moves the ticket to a `closed` status **through `WorkflowService`** (the one path to a status change — automation never writes `ticket_status_id` directly, and only a permission-free transition to a `closed`, never `rejected`, status is used), recording the `ClosureAudit`. A `propose` policy records the audit without moving the ticket; `notify_only` does neither. A Filament CRUD surface for `ClosurePolicy` (conditions composed through a repeater) and a **read-only** surface for `ClosureAudit` (list + view, no create/edit — the "closed because" and premature-closure "returned after" trail stays machine-written) ship under the SAO group. `OwnershipSuggestionService` proposes a ticket owner deterministically from injected code evidence (strongest rule first — CODEOWNERS over blame over recent touch over path — then score, then lowest user id), persisting an `OwnershipSuggestion` that is **never applied automatically** (D14); a read-only Filament surface lists and views the proposals and their evidence, with a single manual **Accept** action (`OwnershipSuggestionApplier`) that assigns the suggested owner to the ticket — the sanctioned human accept, hidden when there is no user to assign. `CodeownersOwnershipResolver` gathers that evidence from the repository's CODEOWNERS file through the `vcs` capability (reading it at a ref, matching its patterns against the touched files with git's last-match-wins semantics, and resolving owner handles to user ids via an injected identity map — unknown handles are skipped). `RecentTouchOwnershipResolver` gathers evidence from who recently touched the code — it pages a range's commits, counts them per author (by account handle, falling back to the git author email on hosts with no handle), and resolves each identity through the same injected identity map, emitting `RecentTouch` evidence weaker than an explicit CODEOWNERS entry. `BlameConcentrationOwnershipResolver` sums the lines each author still owns across the touched files, emitting `BlameConcentration` evidence (stronger than recent-touch, weaker than CODEOWNERS); it depends on the optional **`BlameCapability`** contract — line blame is not uniformly exposed over REST, so only the GitHub driver implements it (via GraphQL, with its own `BlameConformance` battery), and a connection whose driver does not is simply not passed to the resolver. The identity map itself comes from the `ContributorIdentity` directory (a VCS handle or git author email tied to a Core user, per provider or provider-agnostic): `ContributorIdentityMap::forProvider()` builds the `identity => user_id` map the resolvers consume, with provider-specific entries overriding provider-agnostic ones. `OwnershipSuggestionCoordinator` drives the whole chain: given a ticket, a `vcs` connection, the provider, the touched files and a ref, it builds the identity map, runs every applicable resolver (blame only when the driver implements `BlameCapability`), and persists the winning suggestion. A pull-request `ChangeRef` now carries its `base_ref`/`head_ref`, and `PullRequestChangedPathsResolver` compares them through the `vcs` capability to discover the changed files (reading GitHub's `files[].filename` and GitLab's `diffs[].new_path`); `OwnershipSuggestionCoordinator::suggestForPullRequest()` uses it to suggest an owner straight from a merged PR, no path named by hand. Suggestions are phrased through the `SuggestionPhraser` contract: the default `TemplateSuggestionPhraser` states the proposal deterministically from its persisted fields (and the read-only Filament view renders it), while the bound `AiSuggestionPhraser` (phase 8) rewrites that factual text through the narrow `SuggestionTextGenerator` seam, guarding against invention (it falls back to the factual text if the generator fails or drops the suggested owner's name, and never sends a userless suggestion). The default generator, `EventTextGenerator`, asks for the rewrite by dispatching Core's `AiTextGenerationRequested` event and returning whatever a listener filled in — so the AI is entirely optional and SAO keeps **no dependency on the AI module**: with no listener the response is empty and the phraser falls back to the deterministic text, exactly like the `ModelRequiresIndexing` embeddings seam. An AI listener (behind its own feature flag) and live PR-merge/webhook transport remain the follow-ups
-   Phase 7 — driver waves (**done**): the **Gitea** `issues` driver and the first **`logs`** driver **Sentry** landed first, then the set was widened to nine `issues` drivers (adding **YouTrack**, **Azure DevOps**, **Linear**) and ten `logs` drivers (adding **GlitchTip**, **Rollbar**, **Bugsnag**, **Honeybadger** as native-keyed error trackers, and **Grafana**, **Datadog**, **Elastic**, **BetterStack** as fingerprinted aggregators). All registered by default; every issues driver passes the issues conformance suite and every logs driver its ingest tests over an `Http::fake()`
-   Phase 8 — AI, as a hard module requirement
-   Phase 9 — Vue surfaces

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

## Releases

This module is released from the application, not from its own repository: it carries no release scripts and no `cliff.toml`. From the `laraplate` root, `scripts/version.sh` bumps the `version` field of `Modules/SAO/composer.json`, regenerates `Modules/SAO/CHANGELOG.md` with the application's `cliff.toml`, commits `chore(release): vX.Y.Z` in the module repository, tags it and pushes both.

```bash
composer run version:dry SAO      # print the plan, write nothing
composer run version:minor SAO    # release with a forced level (also version:major, version:patch)
composer run version:all             # every module with pending commits, then the application
```

Without a forced level, git-cliff infers it from the conventional commits since the module's last tag. `CHANGELOG.md` lists released versions only. Releasing the module alone does not touch the application; `version:all` records the module in the application with a commit typed after the module's release level. Full reference: `docs/releasing.md` in the application.

## Contributing

If you want to contribute to this project, follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or correction.
3. Send a pull request.

## License

SAO Module is open-sourced software licensed under the [GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html).
