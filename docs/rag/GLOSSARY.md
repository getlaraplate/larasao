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
| **Sync direction** | Who owns a ticket. `mirror`: SAO owns it, the external system receives writes. `shadow`: the external system owns it, SAO reads and correlates. A project with no `issues` binding is local. (The persisted `sync_direction` enum is `inbound`/`outbound`/`bidirectional`/`disabled`.) |
| **Tracker import** | `TrackerImportService` + `sao:tracker:import`: the "switch to Laraplate, import your history" migration — walks an `issues` binding's whole list and upserts each issue via `IssueSyncService::import()` (idempotent by `TicketLink`, independent of ongoing sync direction). `ImportScope::Open` skips issues whose remote status maps to a terminal category; an unmapped status is kept as open. When the driver also implements `IssueHistoryCapability`, each ticket's comments (idempotent by remote comment id, stored as `system` `TicketComment`s) and attachments (idempotent by remote attachment id, bytes downloaded by the driver, stored in the `attachments` media collection) are imported too. Runs inline or as `ImportTrackerHistoryJob`. |
| **ImportRun** | `sao_import_runs`: the persisted, resumable state of one tracker import per (binding, scope) — the driver's next-page cursor and running counts, saved after every page. An import killed by a crash, redeploy or requeue resumes the still-`running` run from its cursor instead of restarting; it flips to `completed` only when the walk is exhausted, so re-importing a finished migration opens a fresh run. |
| **Cutover** | `BindingCutoverService`: after a migration, makes SAO authoritative by flipping the binding's `sync_direction` — `disabled` (external tracker abandoned) by default, or `outbound` during a transition. `TicketLink`s are kept as provenance. |
| **Retention** | `RetentionService` + `sao:prune`: hard-deletes aged, high-volume data so the store does not grow without bound — signal occurrences, ingest events, deployments (keeping the latest per environment) past their windows, plus the heavy data (signals/occurrences/ingest/deployments/tickets) of projects deactivated beyond a grace period. The project, environments and releases are kept as anagraphic. Windows are `sao.retention.*`; `--dry-run` reports without deleting; scheduling is gated by `SAO_RETENTION_SCHEDULE`. |
| **Status map** | The translation between canonical statuses and one specific remote installation's statuses. Lives on the binding, never on the driver: Redmine statuses are per-installation and Jira workflows per-project. |
| **Environment** | A deployment target of a project (`production`, `staging`, …), unique by name per project, recording `current_version` last seen running and `last_seen_at`. |
| **Environment liveness** | The last time an environment was observed sending anything. Absence of errors is evidence only when the source was demonstrably alive. |
| **Deploy census** | `DeployCensusService`'s answer to "what runs where": one row per environment (version + freshness). Written by two feeds — `observe()` for a passive signal (a succeeded deployment, or a live error occurrence whose version resolves to a release of the project, stored as that release's version, with a validated commit and throttled to one write per minute while unchanged) and `recordProbe()` for an active check, all stamping `last_seen_at` — and read via `census(project, ttl)`. |
| **Staleness** | Whether what we know about an environment is older than a caller-chosen TTL. An environment never seen is stale by definition, so the census never claims certainty it lacks. |
| **Release** | A product version of a project, named as its stable label. Status is the curation lifecycle: `observed` (seen in the wild, not curated, hidden from lists by default), `announced` (being assembled) or `shipped` (a stable tag realizing it exists). One row per `(project, version)`. |
| **ReleaseTag** | A concrete VCS tag realizing a release. `kind` is the maturity: `alpha` < `beta` < `candidate` (an RC keeping a testable reference for staging) < `stable` (the only shippable one), ordered by `precedence()`. |
| **Release maturity** | A release's effective maturity is `Release::effectiveMaturity()`, the highest `kind` among its tags, or `null` when it has no tag. A tagless release (typically `observed`) has unknown maturity and can never be a resolution target. The `curated` scope hides `observed` releases from version lists. |
| **Version census** | `VersionCensusService`: records a reported version into `Release`, deduped to one row per product version, created as `observed` when new and never downgrading a curated release. Fed automatically from log ingest (see Affected version) and, for a human opening a ticket, from an "add new" that also lands as `observed`. |
| **TicketRelease** | The attribution of a ticket to a release: `affected` (where the problem was detected — any maturity, including an uncurated `observed` version), `promised` or `shipped` (the fix). Resolution states attach only to stable-eligible releases — `TicketReleaseAttributor` enforces that `shipped` requires a `stable` tag (`UnstableResolutionReleaseException`), while `affected` accepts any. The pair is unique and the state is independent of the ticket's own workflow status. |
| **Deployment** | A recorded deploy/rollout of a version to an environment (`sao_deployments`): status, `started_at`/`finished_at`, source connection + `external_id`. The durable, idempotent history behind the deploy census (which becomes a projection of it, kept fresh between deploys by live error occurrences and the probe) and the precise time anchor release health reads from. The pair `(connection, external_id)` is unique so a re-delivery is recorded once. |
| **DeploymentStatus** | The lifecycle of a deployment: `started` (the only non-terminal), `succeeded`, `failed`, `rolled_back`, `superseded`. Among deployments, only a terminal `succeeded` advances the environment's version census; a failed or rolled-back deploy is history, never asserted as running. Live error occurrences and the probe also advance it (see Deploy census). |
| **Deploy ingest** | `DeploymentIngestService`: turns one normalized `DeployEvent` into a `Deployment`, deduped by `(connection, external_id)`, advancing the census on `succeeded` and announcing `DeploymentRecorded`. Fed by the `sao:deploy:record` command and the `deploy` push webhook (`WebhookDeployDriver` token / `GitHubDeploymentDriver` HMAC). |
| **Release health** | `ReleaseHealthService`: a post-hoc, correlation-only verdict (`ReleaseHealthVerdict`: healthy / degraded / regressed / unknown) on whether a release introduced new or worsening signals versus the previous release over an equal window. Never a rollout gate. The window anchors on a succeeded deployment's `finished_at` when present, else `Release.released_at`. |

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
| **SignalOccurrence** | One individual occurrence of a signal, with configurable retention. Carries the detected **affected version**: `affected_version` (the normalized reporting version, kept even when not censused) and `affected_release_id` (the censused release, null when the version was unnormalizable and kept raw). |
| **Affected version** | The software version that reported the anomaly, detected at ingest. `VersionNormalizer` canonicalizes it (`v1.4.0`, `1.4.0 (build 123)` → `1.4.0`; `1.4.0-rc.1` kept, rolled up to release `1.4.0`; `nightly` → unnormalizable, kept raw, not censused). Stamped per occurrence; a ticket opened from a signal is attributed `affected` to the most recent censused occurrence's release (`SignalTicketOpener`, `Ticket::affectedRelease()`). Distinct from the promised/shipped resolution version. |
| **SignalAlias** | A superseded group key pointing at its signal. What lets the fingerprint algorithm evolve without splitting history. |

## Work

| Term | Meaning |
|------|---------|
| **Ticket** | The canonical unit of work: title, body, canonical status, priority, assignee, comments. Exists with or without an external counterpart. |
| **TicketLink** | The link between a ticket and its counterpart in an external tracker. No link means an internal ticket. |
| **Internal ticket** | A ticket with no `TicketLink`. The default, and the reason standalone use needs no special code path. |
| **ChangeRef** | The link between a code artefact (commit, pull request, tag) and a ticket, with the source that produced it and its `relation`. |
| **ChangeRef relation** | Whether a change ref `fixes` a ticket (resolution evidence) or only `mentions` it (timeline context). A closing verb before a ticket key (`fixes SAO-1`) is a fix, a bare reference a mention; only fixes count toward `FixStatusResolver`/`TimeToTruthService`/closure. An upsert may upgrade a mention to a fix, never the reverse. |
| **Idempotency key** | The persisted key carried by every outbound write, so a retry can never produce a second comment or a second ticket. Trackers rarely offer idempotent write APIs; the guarantee lives on our side. |
| **Due date** | A ticket's `due_at`. The `overdue` scope selects past-due tickets not in a terminal status; `dueWithin` selects tickets due in the next N days. |
| **Label** | A project-scoped tag on a ticket (unique name per project). Attached many-to-many through `sao_ticket_label`. |
| **Watcher** | A user following a ticket. Record-only in 1b: `watch()`/`unwatch()` are idempotent; notification delivery is out of scope. |
| **Attachment** | A file on a ticket's `attachments` media collection, stored in the Core-owned media library (`vend_media`). SAO uses Core's `HasMedia`, depending only on Core. |
| **TicketRelation** | A typed link between two tickets (`TicketRelationType`: blocks/duplicates/relates). Directional types read differently per end (`blocks` inverts to "blocked by"); `relates` is symmetric. Self-relations are rejected. |
| **TicketSearchCriteria** | An immutable, JSON-serialisable description of a ticket search (text, status, type, priority, assignee, label, due window, overdue). |
| **TicketSearchService** | Turns a `TicketSearchCriteria` into a query built strictly on `TicketQueryService::visible()`, so a search never surfaces a hidden ticket. |
| **SavedFilter** | A user's persisted `TicketSearchCriteria`, optionally scoped to one project. Round-trips back into criteria for reapplication. |
| **Board** | A per-project view of tickets in status-ordered columns (1c). A read model (`TicketBoardService` → `BoardColumn`) over the ACL-scoped visible query; cards move only through workflow-allowed transitions via `WorkflowService`. No board/column/card is persisted. |
| **BoardColumn** | One column of the board: a `TicketStatus` and the visible tickets in it. An empty status is still a column. |

## Automation

| Term | Meaning |
|------|---------|
| **ClosurePolicy** | A per-project set of closure conditions (stored as `{key, config}` json) combined with AND, plus the action (`ClosureAction`: close / propose / notify_only) taken when they all hold. `propose` is the prudent default on `shadow` external bindings. |
| **Closure condition** | One independently testable predicate over verifiable facts: `pull_request_merged`, `no_recurrence_for`, `fix_released`, `fix_deployed_there`, `resolved_for`, `internal_tickets_only`. Built by `ClosureConditionRegistry` from the policy json. |
| **ClosureContext** | The assembled, verifiable facts a policy is evaluated against, with `now` injected so a decision is deterministic and reproducible. Conditions are pure functions of it. |
| **ClosureDecision** | `ClosureEvaluator`'s output: the action, whether every condition held (AND; an empty set never holds), and the per-condition outcomes with evidence — the "closed because". |
| **ClosureAudit** | The record of an automatic or proposed closure: which conditions held with what evidence, and — on reopen — the "returned after" (duration, environment, occurrence) that flags a **premature closure**. |
| **Closure application** | `ClosureApplicationService`: evaluates a policy against a ticket and, when satisfied, acts — a `close` policy moves the ticket to a `closed` status through `WorkflowService` (never writing the status directly) and records the audit; `propose` records only; `notify_only` does neither. |
| **Premature closure** | An automatic closure invalidated by the signal reappearing. Recorded as such, and the data that says whether configured durations are tuned correctly. |
| **Ownership suggestion** | `OwnershipSuggestion` (via `OwnershipSuggestionService`): a deterministic proposal of a ticket's owner from code evidence — strongest rule first (`OwnershipRule`: codeowners > blame > recent touch > path), then score. A proposal only: SAO never applies an assignee automatically (D14). |
| **ContributorIdentity** | A VCS handle or git author email tied to a Core user, per `provider` (empty = any). The persisted directory behind the ownership resolvers; `ContributorIdentityMap::forProvider()` builds the `identity => user_id` map from it, provider-specific entries winning over provider-agnostic ones. |
| **Suggestion phraser** | `SuggestionPhraser`: turns an `OwnershipSuggestion` into human text. `TemplateSuggestionPhraser` is deterministic and factual; the bound `AiSuggestionPhraser` (phase 8) rewrites it via the `SuggestionTextGenerator` seam, falling back to the factual text and never changing the named owner (D14). |
| **Optional AI seam** | `EventTextGenerator` fulfils `SuggestionTextGenerator` by dispatching Core's `AiTextGenerationRequested` event; an AI listener fills the response when enabled, and with none the empty response makes the phraser fall back — so AI is optional and SAO depends only on Core, never on the AI module (the `ModelRequiresIndexing` pattern). |
| **Ownership evidence resolvers** | The services that turn `vcs` reads into `OwnershipEvidence`: `CodeownersOwnershipResolver` (CODEOWNERS patterns, last-match-wins), `RecentTouchOwnershipResolver` (commit count per author over a range) and `BlameConcentrationOwnershipResolver` (owned-line count per author across the touched files, via the optional `BlameCapability` — GitHub-only). All resolve identities (handle or email) to user ids through an injected identity map and skip the unmappable. |
| **Fix propagation** | `FixStatusResolver`'s deterministic read of whether a fix's PR is merged, a **shipped** release carries it, and which environments run that version — the "already fixed on dev, deploy missing" answer. |
| **Time-to-truth** | `TimeToTruthService`'s lag, from a signal's first sighting, until the fix was merged, a deploy gap was knowable, and (if it happened) a premature closure was reopened. |
| **Ticket reference extractor** | `TicketReferenceExtractor`: pulls ticket keys from commit/PR text and classifies each as a fix (a configured closing verb — `sao.attribution.closing_verbs` — precedes the key) or a mention. |
| **Code reference writer** | `CodeReferenceWriter`: resolves the extracted keys to tickets and upserts one `ChangeRef` per `(ticket, type, identifier)`, monotonic on relation (mention→fix upgrade, never downgrade), reporting keys that resolve to no ticket. The transport-agnostic middle both transports feed. |
| **Release attribution** | `ReleaseAttributionService`: maps a fixing commit to the release that carries it via `ReleasesCapability::firstTagContaining`, upserting `Release`/`ReleaseTag`/`TicketRelease`. `ReleaseTagClassifier` normalizes the tag to its stable version (semver core, `v1.4.0-rc.1` → `1.4.0`) and its kind — so a candidate (RC) records the future stable version, still `announced`, until a stable tag ships it. The `Release`/`ReleaseTag` upsert + promotion is shared through `ReleaseRegistrar`. |
| **Release sync** | `ReleaseSyncService` + `sao:releases:sync`: lists a `releases` binding's tags and **promotes** an announced release to shipped once its stable tag is actually cut — deterministically, independent of which tag `firstTagContaining` returns during a commit scan. It registers tags for releases attribution already created; it never invents releases for tags with no attributed work. |
| **Commit scan** | `VcsScanService` + `sao:vcs:scan`: the pull transport — walks a `vcs` binding's commits into the code reference writer and attributes fixes to releases. Idempotent, so it also backfills history. |
| **Code webhook** | `CodeWebhookIngestService`: the push transport — a `code` connection's merged-PR delivery, verified by its driver (`WebhookCodeDriver` token / `GitHubPullRequestDriver` HMAC, merged PRs only), unpacked into `CodeReference`s and recorded through the writer. On the shared `webhooks/{connection}` route, branched by capability. |
| **Closure coordinator** | `ClosureCoordinator` + `sao:closure:run`: runs a ticket's active closure policies, gating auto-close by the `sao.closure.auto_close.enabled` setting (off by default). When off, every `close` policy is downgraded to `propose` in-memory — evidence is recorded, the ticket never moves; when on, a satisfied `close` policy closes through `ClosureApplicationService`. |
