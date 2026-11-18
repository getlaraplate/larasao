# Changelog

All notable changes to this project will be documented in this file.

## [1.6.0] - 2026-11-18

### 🚀 Features

- *(sao)* Runtime tunables are settings
- *(sao)* Operator correlation rules
- *(sao)* Logs deliveries correlate per event
- *(sao)* Correlation rules resource
- *(sao)* Ingest events record their pipeline
- *(sao)* Reprocess unmatched ingest events
- *(sao)* Reprocess actions on ingest events
- *(sao)* Recurrence comment on the open ticket
- *(sao)* Environments record commit and branch
- *(sao)* Http-version probe driver
- *(sao)* Scheduled census probe
- *(sao)* Vcs drivers tell whether a ref contains a commit
- *(sao)* Change refs keep the commit an environment can contain
- *(sao)* Record whether each environment contains each fix
- *(sao)* Fix status shows containment per environment

### 🐛 Bug Fixes

- *(sao)* The signal occurrence cap keeps 0 = no cap
- *(sao)* Replayed occurrences keep first seen and do not reopen
- *(sao)* Lock the signal row while recording an occurrence
- *(sao)* Lock only found rows and recover concurrent release creation
- *(sao)* Retry the ingest on a deadlock and share-lock the census re-read
- *(sao)* Probe malformed urls and connection config
- *(sao)* Occurrences feed the census only release versions and valid shas
- *(sao)* Ignore deleted projects and keep known fix presences
- *(sao)* Derive optional credentials from the driver schema
- *(sao)* Translate the reprocess counts status and correct the job docblock

### 🚜 Refactor

- *(sao)* Read the vector profile instead of core.search.vector
- *(sao)* Follow the offersVectorSearch rename in the ticket embeddable test

### 📚 Documentation

- *(sao)* Correlation rules
- *(sao)* Ingest reprocessing
- *(sao)* Recurrence comment
- *(sao)* Fix presence read side and unchanged closure
- *(sao)* Document the occurrence census feed and fix containment
- Require Laravel 13
- *(sao)* Front matter for the user documentation index

### 🧪 Testing

- *(sao)* Assert the Laravel 13 framework floor

### ⚙️ Miscellaneous Tasks

- *(seeders)* Report each seeding step with one summary line
- Drop the license-compliance-checker repository
- *(deps)* Require Laravel 13

## [1.5.1] - 2026-10-21

### 🚀 Features

- *(filament)* Split the SAO sidebar into four navigation groups
- *(sao)* Mark the parts of a parent for related-record authorization
- *(sao)* The ticket edit page refuses a stale save
- *(sao)* Translation keys named like the database
- *(sao)* Translated resource names and navigation groups
- *(sao)* Backoffice strings from the sao lang file

### 🐛 Bug Fixes

- *(sao)* The ticket edit page opens again
- *(sao)* Read the default locale through LocaleContext
- *(sao)* Standardize singular labels across multiple languages

### 🚜 Refactor

- Import Core VersionStrategy (D14)

### 📚 Documentation

- API access control
- Verbatim AGPL-3.0 licence text, copyright in the README
- Point the links to the getlaraplate organization
- Fix a typo in the work in progress notice
- Use the Laraplate brand logo in the README

### 🧪 Testing

- Follow the renamed API switch
- An authenticated denial answers 403
- Build AdvancedSearchService from a strategy resolver

### ⚙️ Miscellaneous Tasks

- *(sao)* Remove the unused nwidart module scaffold
- Point repository links to the getlaraplate organization
- Rename the Composer package to the getlaraplate vendor
- Align the package description with the repository description
- Align the module description with the repository description
- Call the organization CLA workflow
- Rename the repository and package from laraplate-<module> to lara<module>

## [1.5.0] - 2026-10-07

### 🚀 Features

- *(sao)* The ticket backoffice page reads the ticket, working it stays in the app
- *(sao)* Domain interface labels in lang/{locale}/sao.php

### 📚 Documentation

- *(sao)* Describe the bundled external drivers as available
- *(sao)* Status statements match what is delivered

### 🧪 Testing

- *(sao)* End-to-end redmine issue sync in a configurable direction
- *(sao)* Tuning profile regression gate on the application content baseline
- *(sao)* Keep the ticket embedding test free of the AI module
- *(sao)* Test the ticket provider over its dataset without the AI module

### ⚙️ Miscellaneous Tasks

- *(sao)* Module priority 10, first tier after Core

## [1.4.0] - 2026-09-29

### 🚀 Features

- *(filament)* Attach media curation relation manager to Ticket (media analysis Task 13b, M22)

## [1.3.0] - 2026-09-29

### 🚀 Features

- *(sao)* Version maturity census — tag kinds and observed status
- *(sao)* Affected version — provenance, occurrence record and stable-only resolution
- *(sao)* Create and edit pages say Close until something is unsaved
- *(search)* Media attached to a ticket follow the ticket's visibility (M16)

### 🐛 Bug Fixes

- *(sao)* Mock embedDocumentsBatch in TicketEmbeddableTest
- *(sao)* Releases are created before the signal occurrences that reference them
- *(readme)* Update logo size and add PHP version badge

### 🚜 Refactor

- *(migrations)* Fold column/index/enum alters into their create migrations
- *(models)* Drop the IdeHelper mixins, declare the activation contract
- *(models)* Declare the search contract on Ticket
- Narrow findOrFail to a single record with whereKey()->firstOrFail()
- Give the domain action handlers and Filament pages their real types
- *(sao)* Read external driver payloads through one typed helper
- *(sao)* Read vector settings from core.search.vector.*

### 📚 Documentation

- *(rag)* Describe how the module is released from the application
- *(sao)* Affected version & version-maturity census

### ⚡ Performance

- *(migrations)* Index all foreign-key and row-scoping columns

### 🧪 Testing

- *(migrations)* Assert the permission vocabulary, not the patch that fixed it

### ⚙️ Miscellaneous Tasks

- Rimuove docblock ide-helper generati dai model
- Add @mixin annotations for IDE helper in model classes
- Drop the import left unused by the handler retyping
- *(sao)* Ticket declares IOptimisticLockableModel

## [1.2.0] - 2026-09-15

### 🚀 Features

- *(filament)* Expose the SAO surfaces in the admin panel
- *(sao)* Add color attribute to module and integrate it into the admin panel
- *(search)* Make Ticket embeddable (mono-lingual vector)

### 🐛 Bug Fixes

- *(eval)* Open SAO baseline candidates to measure real ranking
- *(sao)* Pick a transition's required permission instead of typing it

### 🚜 Refactor

- *(sao)* Remove IconColumn from ClosurePoliciesTable and SourceProfilesTable, and implement HasActivation trait in ClosurePolicy and SourceProfile models
- Remove unnecessary docblock annotations in ClosurePolicy and SourceProfile models

### 📚 Documentation

- *(changelog)* Regenerate with the corrected git-cliff configuration

### 🎨 Styling

- Format with the application's Pint configuration
- Apply the module's own mb_str_functions rule

### 🧪 Testing

- *(eval)* Add SAO application-content ranking baseline with @k metrics

### ⚙️ Miscellaneous Tasks

- Update command descriptions to include SAO module indicator
- The module carries functionality, not the toolchain

## [1.1.0] - 2026-09-09

### 🚀 Features

- *(swagger)* Add initial Swagger documentation for SAO module API
- *(sao)* Make Ticket Core-searchable with denormalized relations
- *(sao)* Ticket evidence projector for application content
- *(sao)* Sao.tickets application-content retrieval provider
- *(sao)* Register sao.tickets application-content provider

### 🐛 Bug Fixes

- *(tests)* Share SAO sync and closure fixtures via Pest helpers
- *(sao)* Drop needless nullsafe on non-nullable Ticket priority in search doc
- *(sao)* Guard projector against empty description and non-plain-text fields
- *(sao)* Declare Ticket as the application-content permission model
- *(sao)* Suppress search indexing during dev seeding to avoid hammering the engine

### 🚜 Refactor

- Remove unnecessary docblock comments in model classes for cleaner code
- *(sao)* Declare domain permissions instead of seeding a private list
- *(sao)* [**breaking**] Read tickets with Core's `select` verb
- *(sao)* [**breaking**] Drop the ticket assign permission nothing read
- *(sao)* Config-derived projector caps and label-aware truncation flag

### 📚 Documentation

- *(sao)* Document the sao.tickets application-content provider

### ⚡ Performance

- *(sao)* Drop unused eager-load in application-content rehydration

### 🧪 Testing

- *(sao)* Grant the ticket update permission the writes actually need
- *(sao)* Prove the optimistic conflict end to end on tickets
- *(sao)* End-to-end application-content retrieval through the service gate for a non-superadmin

## [1.0.1] - 2026-08-26

### 🐛 Bug Fixes

- *(seeder)* Enhance DevSAODatabaseSeeder to handle corrupt permission cache

### ⚙️ Miscellaneous Tasks

- Add IdeHelper mixins to Connection, ProjectBinding, and TicketLink models for improved IDE support

## [1.0.0] - 2026-08-25

### 🚀 Features

- *(sao)* Add the ticket surface and close slice 1a
- *(sao)* Capability and ingest-mode enums (phase 3a task 1)
- *(sao)* Driver base and per-capability contracts (phase 3a task 2)
- *(sao)* Open driver registry (phase 3a task 3)
- *(sao)* Connection model with encrypted credentials and env override (phase 3a task 4)
- *(sao)* Connection credential resolver (phase 3a task 5)
- *(sao)* Register driver framework and document it (phase 3a task 7)
- *(sao)* Sync-direction enum and normalized issue value object (phase 3b task 1)
- *(sao)* Non-secret config column on connections (phase 3b task 3)
- *(sao)* Project binding model with binding context (phase 3b task 4)
- *(sao)* Ticket link model (phase 3b task 5)
- *(sao)* Internal issues driver over the ticket domain (phase 3b task 6)
- *(sao)* Idempotent direction-aware issue sync (phase 3b task 7)
- *(sao)* Ticket due dates with overdue/due-within scopes
- *(sao)* Project-scoped ticket labels
- *(sao)* Ticket watchers
- *(sao)* Ticket attachments via media library
- *(sao)* Typed ticket-to-ticket relations
- *(sao)* Ticket search and saved filters
- *(sao)* Filament surfaces for ticket enrichment
- *(sao)* Ticket board read model
- *(sao)* Filament ticket board page
- *(sao)* Redmine external issues driver
- *(sao)* Jira external issues driver
- *(sao)* Github external issues driver
- *(sao)* Gitlab external issues driver
- *(sao)* Bitbucket external issues driver
- *(sao)* Filament connection resource (3b-ui)
- *(sao)* Project bindings filament surface (3b-ui)
- *(sao)* Github vcs and releases capabilities
- *(sao)* Gitlab vcs and releases capabilities
- *(sao)* Bitbucket vcs and releases capabilities
- *(sao)* Payload frame resolver for received errors
- *(sao)* Signal, occurrence and alias models
- *(sao)* Group-key resolution and signal ingest
- *(sao)* Loop protection via pipeline marker and rate limit
- *(sao)* Filament signal resource and occurrences
- *(sao)* Ingest event and source profile models
- *(sao)* Webhook ingest, correlation and dry-run replay
- *(sao)* Code-to-work change references
- *(sao)* Releases, release tags and ticket-release attribution
- *(sao)* Environments and deploy census
- *(sao)* Fix propagation read model
- *(sao)* Evidence-based closure conditions
- *(sao)* Closure policies and context resolver
- *(sao)* Closure audit and premature-closure memory
- *(sao)* Time-to-truth metric
- *(sao)* Gitea issues driver
- *(sao)* Sentry logs driver
- *(sao)* Filament surfaces for releases, environments and closure policies
- *(sao)* Read-only filament surface for closure audits
- *(sao)* Apply closure decisions to the ticket workflow
- *(sao)* Deterministic ownership suggestion with read-only surface
- *(sao)* Codeowners ownership-evidence resolver
- *(sao)* Normalize the commit author in vcs reads
- *(sao)* Recent-touch ownership-evidence resolver
- *(sao)* Blame capability and blame-concentration ownership resolver
- *(sao)* Contributor identity directory as the ownership identity-map source
- *(sao)* Ownership suggestion coordinator
- *(sao)* Discover a pull request's changed files for ownership
- *(sao)* Phase 8 — AI-phrased ownership suggestions
- *(sao)* Request optional AI phrasing through Core's event seam
- *(sao)* Connection health check command and Filament action
- *(sao)* Graylog logs driver
- *(sao)* Eight more logs drivers (error trackers + log aggregators)
- *(sao)* Three more issues drivers (youtrack, azure devops, linear)
- *(sao)* Inbound webhook transport for logs connections
- *(sao)* Scheduled inbound issue polling (pull transport)
- *(sao)* Read-only Filament surface for ingest events
- *(sao)* Auto-open tickets from error signals
- *(sao)* Schedule the connection health probe
- *(sao)* Sao:ingest:replay command to dry-run a stored event
- *(sao)* Manual accept action for ownership suggestions
- *(sao)* Writable Filament CRUD for source profiles
- *(sao)* Expose operational actions as HTTP domain actions
- *(sao)* Add tickets `transitions` read action for guided kanban drops
- *(sao)* Model ticket↔label and ticket↔watcher pivots explicitly
- *(sao)* Add release-health read-model (#2)
- *(sao)* Deploy & rollout ingest core (#1)
- *(sao)* Deploy webhook transport — deploy capability + drivers + ingest (#1)
- *(sao)* Attribution core — Fixes/Mentions change refs from code text (spec #1 phase 1)
- *(sao)* Complete fix-attribution pipeline — release attr, pull scan, PR webhook, closure (spec #1 phases 2-5)
- *(sao)* Deterministic release promotion — ReleaseRegistrar + ReleaseSyncService (spec #1)
- *(sao)* External tracker migration importer + cutover (spec #2)
- *(sao)* Data retention prune (sao:prune) — keep the store bounded
- *(sao)* Resumable tracker import + comment/attachment history
- *(import)* Register a sao.ticket entity for the generic bulk import

### 🚜 Refactor

- *(sao)* Capability calls receive a BindingContext (phase 3b task 2)

### 📚 Documentation

- *(sao)* Record what slice 1a delivered
- *(sao)* Add developer "how it works" section to module RAG doc
- *(sao)* Document phase 1b ticket enrichment
- *(sao)* Document the external issues driver roster
- *(sao)* Document vcs/releases on the git-host drivers
- *(sao)* Document phase 2 signals and loop protection
- *(sao)* Note the signal filament surface
- *(sao)* Document phase 4 ingest and source profiles
- *(sao)* Document phase 5b code-to-work, releases and deploy census
- *(sao)* Document phase 6 fix propagation and evidence-based closure
- *(sao)* Document the gitea and sentry drivers
- *(sao)* Add plan for release health & deploy ingest
- *(sao)* RAG glossary updates for deploy/release-health/attribution/closure; clarify release-version normalization

### 🧪 Testing

- *(sao)* Per-capability conformance suite with in-memory driver (phase 3a task 6)
- *(sao)* Add dev seeder for the SAO SPA with demo data, a role and a user

### ⚙️ Miscellaneous Tasks

- *(models)* Remove unnecessary comments from model properties

## [0.2.0] - 2026-08-04

### 🚀 Features

- *(sao)* Seed domain permissions and gate the workflow override
- *(sao)* Add Filament resources for the configuration entities

### ⚙️ Miscellaneous Tasks

- *(models)* Add IdeHelper mixins to project and ticket models

## [0.1.0] - 2026-08-03

### 🚀 Features

- *(sao)* Add the table registry and domain enums
- *(sao)* Add the project entity with its ticket counter
- *(sao)* Add global ticket statuses with canonical categories
- *(sao)* Add shareable workflow schemes and their transitions
- *(sao)* Add ticket types with per-project association
- *(sao)* Add tickets with a row-locked per-project key allocator
- *(sao)* Enforce workflow transitions in the domain service
- *(sao)* Add ticket comments with human and system origins
- *(sao)* Build the ticket timeline from comments and versions

### 🐛 Bug Fixes

- *(sao)* Put the column comment before constrained(), not after
- *(sao)* Respect model connection affinity in the key allocator

### 🚜 Refactor

- *(sao)* Remove dead scaffolding and enforce the Core dependency
- *(sao)* Use Core HasActivation instead of hand-rolled activation

### 📚 Documentation

- *(sao)* Add licence, readme, changelog and repository hygiene
- *(sao)* Add the module glossary and RAG corpus

### 🧪 Testing

- *(sao)* Add module test harness and registration coverage

### ⚙️ Miscellaneous Tasks

- *(sao)* Declare module identity, licence and Core dependency
- *(sao)* Align quality tooling with the sibling modules
- *(sao)* Add release scripts and module agent rules

<!-- generated by git-cliff -->
