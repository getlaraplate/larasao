# SAO module glossary

Canonical English names for SAO concepts. Use these terms in code, APIs, tests and cross-module
documentation. The vocabulary is fixed here before the domain exists, so that later phases inherit
it rather than invent parallel names.

## Module scope

| Term | Meaning |
|------|---------|
| **SAO** | Simply Another Orchestrator: a correlation engine between code, errors and work. Not a log aggregator, not an APM, not a CI runner. |
| **`sao_` prefix** | All SAO tables use this prefix. Names like `connections`, `projects` and `tickets` are too generic to sit unprefixed beside Core/CMS/ERP. |
| **Core dependency** | SAO depends on Core for fingerprint normalization, ACL, CRUD and Filament foundations. The dependency is unidirectional: SAO → Core. |
| **Standalone use** | An installation with zero connections configured. SAO is then a complete ticketing system. This is the default, not a degraded mode. |

## Integration

| Term | Meaning |
|------|---------|
| **Driver** | Registered code that knows how to talk to one external system. Registered in an open registry: a third-party package can add one without modifying SAO. |
| **Connection** | A configured instance of a driver: base URL, encrypted credentials, health state, declared capabilities. |
| **Capability** | What a connection can do: `issues`, `vcs`, `logs`, `releases`, `deploy`, `code`. One connection may expose several — a GitHub connection exposes all but `logs`. |
| **Ingest mode** | How events reach SAO from a driver: `push` (webhook), `pull` (polling), `in_process` (no transport). Each driver declares which it supports. |
| **Conformance suite** | The shared test battery every driver of a given capability must pass. A driver is done when it passes conformance, not when it works. |

## Projects and deployment

| Term | Meaning |
|------|---------|
| **Project** | The correlation anchor: a tracked software project. Holds no URLs or credentials of its own — only bindings. |
| **ProjectBinding** | A link from a project to a connection for one capability, plus binding-scoped configuration: sync direction, status map, priority map. |
| **Sync direction** | Who owns a ticket. `mirror`: SAO owns it, the external system receives writes. `shadow`: the external system owns it, SAO reads and correlates. A project with no `issues` binding is local. |
| **Status map** | The translation between canonical statuses and one specific remote installation's statuses. Lives on the binding, never on the driver: Redmine statuses are per-installation and Jira workflows per-project. |
| **Environment** | A deployed instance of a project (`production`, `staging`, customer X) with the version currently running on it. |
| **Environment liveness** | The last time an environment was observed sending anything. Absence of errors is evidence only when the source was demonstrably alive. |
| **Release** | A version of a project (tag, commit, date) and the map of where it is deployed. |
| **Deployment** | A recorded deploy of a version to an environment (`sao_deployments`): status (`started`/`succeeded`/`failed`/`rolled_back`/`superseded`), timing, source. The idempotent history behind the deploy census and the time anchor for release health; deduped by `(connection, external_id)`. Fed by `sao:deploy:record` and the `deploy` webhook. |
| **Release health** | `ReleaseHealthService`'s post-hoc verdict (healthy/degraded/regressed/unknown) on whether a release introduced new or worsening signals versus the previous one over an equal window. Correlation only, never a rollout gate. |

## Ingest

| Term | Meaning |
|------|---------|
| **IngestEvent** | One raw received event: connection, delivery id, payload, status, outcome. The record of what came in. |
| **Delivery id** | The identifier a source assigns to one delivery. Unique per connection; the basis of retry idempotency. |
| **SourceProfile** | A normalization profile stored in the database: matchers plus JSONPath field bindings. Lets a new source be supported by configuration, without code. |
| **Matcher** | The rule that selects which profile applies to an incoming payload. |
| **Field binding** | A JSONPath expression mapping one payload location to one canonical field. |
| **Canonical field** | A normalized field name shared by all sources, independent of any source's payload shape. |
| **Dry-run replay** | Replaying a retained sample payload against a modified profile to see what would have happened, without acting. |
| **Correlation ruleset** | The ordered, inspectable rules that attach an event to a project. Every event records which rule won. |
| **Pipeline origin marker** | The runtime marker set while an ingest, normalization or synchronization job runs. The internal log source discards records carrying it, which is what makes a self-feeding loop impossible. |

## Error grouping

| Term | Meaning |
|------|---------|
| **Fingerprint** | The hash computed from a normalized error: kind, module, class, normalized file, function, normalized message. The line number is metadata, deliberately not an ingredient. |
| **Group key** | The identity of an error group. Either native or computed. |
| **Native group key** | A grouping key the source already provides — Core's own fingerprint, a Sentry issue id. Namespaced per source (`core:…`, `sentry:…`) so keys from different systems cannot collide. SAO computes its own only in their absence. |
| **`algo_version`** | The version of the fingerprint algorithm that produced a group key. Stored from the first migration; adding it later would mean backfilling unknown values. |
| **Signal** | An error group: group key, algorithm version, project, counters, first and last seen, state, affected versions. |
| **SignalOccurrence** | One individual occurrence of a signal, with configurable retention. |
| **SignalAlias** | A superseded group key pointing at its signal. What lets the fingerprint algorithm evolve without splitting history. |

## Work

| Term | Meaning |
|------|---------|
| **Ticket** | The canonical unit of work: title, body, canonical status, priority, assignee, comments. Exists with or without an external counterpart. |
| **TicketLink** | The link between a ticket and its counterpart in an external tracker. No link means an internal ticket. |
| **Tracker import** | `TrackerImportService` + `sao:tracker:import`: migrate an external tracker's history into SAO (idempotent by `TicketLink`; `--scope=open` skips issues whose remote status maps to a terminal category). When the driver implements `IssueHistoryCapability` it also imports each ticket's comments (idempotent by remote comment id) and attachments (idempotent by remote attachment id, bytes downloaded by the driver). `BindingCutoverService` (`--cutover`) then makes SAO authoritative by flipping the binding sync direction (disabled by default). |
| **ImportRun** | The persisted, resumable state of one tracker import for a (binding, scope): the driver's next-page cursor and running counts, saved after every page (`sao_import_runs`). An import killed by a crash, a redeploy or a requeue resumes the still-`running` run from its stored cursor instead of restarting; it flips to `completed` only when the page walk is exhausted, so a finished migration re-run opens a fresh run. |
| **Retention** | `RetentionService` + `sao:prune`: hard-deletes aged data (signal occurrences, ingest events, deployments keeping the latest per environment) and the heavy data of long-deactivated projects, so the store does not grow without bound. Windows via `sao.retention.*`; `--dry-run` reports only. |
| **Internal ticket** | A ticket with no `TicketLink`. The default, and the reason standalone use needs no special code path. |
| **ChangeRef** | The link between a code artefact (commit, pull request, tag) and a ticket, with the source that produced it and its `relation` (`fixes` = resolution evidence, `mentions` = context). Fixes are extracted from commit/PR text by a closing verb before a ticket key; only fixes count toward fix propagation and closure. |
| **Idempotency key** | The persisted key carried by every outbound write, so a retry can never produce a second comment or a second ticket. Trackers rarely offer idempotent write APIs; the guarantee lives on our side. |

## Automation

| Term | Meaning |
|------|---------|
| **ClosurePolicy** | A per-project set of closure conditions combined with AND, plus the action taken when they hold: close, propose closure, or notify only. |
| **Closure condition** | One independently testable predicate over verifiable facts: `pull_request_merged`, `no_recurrence_for`, `fix_released`, `fix_deployed_there`, `resolved_for`, `internal_tickets_only`. |
| **Premature closure** | An automatic closure invalidated by the signal reappearing. Recorded as such, and the data that says whether configured durations are tuned correctly. |
| **Fix propagation** | The deterministic check for whether a fix already exists upstream and only a deployment is missing. |
| **Attribution** | Writing the code↔work links from evidence: `TicketReferenceExtractor` + `CodeReferenceWriter` record fix/mention `ChangeRef`s from commit/PR text, and `ReleaseAttributionService` maps a fixing commit to its release (normalizing the tag; a candidate records the future stable version, still announced). Fed by the pull scan (`sao:vcs:scan`) and the `code` PR-merge webhook. |
| **Release sync** | `ReleaseSyncService` + `sao:releases:sync`: lists a `releases` binding's tags and promotes an announced release to shipped once its stable tag is really cut, deterministically (not dependent on `firstTagContaining` ordering). |
| **Closure coordinator** | `ClosureCoordinator` + `sao:closure:run`: runs active closure policies with a settings-gated auto-close (`closure.auto_close.enabled`, off by default → propose only; on → a satisfied `close` policy closes through `ClosureApplicationService`). |
