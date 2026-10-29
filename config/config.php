<?php

declare(strict_types=1);

return [
    'name' => 'SAO',

    // Driver framework (phase 3a). The registry is open: a driver is registered
    // code, a connection is a configured instance of it. Register a driver by
    // listing its class here, or from any service provider's boot() via
    // app(DriverRegistry::class)->register(...) — adding a provider never
    // requires editing SAO. The drivers bundled with SAO are listed in `registered` below.
    //
    // Secrets live on the connection (encrypted, write-only) or an env
    // credential_ref; product-behaviour configuration (thresholds, policy
    // toggles) belongs in Core settings, never here and never in the database
    // as a secret.
    // Signal ingest guards (phase 2). The per-group rate limiter caps how many
    // occurrences of one signal are recorded within a rolling window — layer 2
    // of the loop protection, so a fast-looping error inside an observed app
    // cannot flood the store.
    'signals' => [
        'max_occurrences_per_window' => 1000,
        'window_minutes' => 60,

        // Automatic ticket opening from error signals (sao:signals:auto-open).
        // Opt-in: a signal opens a ticket once it reaches `min_occurrences` and
        // has none linked yet. The command is always runnable; only its
        // scheduled registration is gated by `enabled`.
        'auto_open' => [
            'enabled' => false,
            'min_occurrences' => 1,
        ],

        // Recurrence comment (SignalRecurrenceCommenter): when an occurrence is
        // recorded on a signal whose ticket is not closed, the ticket gets one
        // system comment per cooldown summing up what recurred. The cooldown is
        // read as at least 1 minute.
        'recurrence_comment' => [
            'enabled' => true,
            'cooldown_minutes' => 60,
        ],
    ],

    // Scheduled inbound polling for trackers with no push transport
    // (sao:sync:issues). Safe with nothing configured — a binding must opt in
    // with an inbound sync direction, so an empty install polls nothing. Set
    // SAO_SYNC_ENABLED=false to keep the command manual-only.
    'sync' => [
        'enabled' => (bool) env('SAO_SYNC_ENABLED', true),
        'cron' => (string) env('SAO_SYNC_CRON', '0 * * * *'),
    ],

    // Scheduled connection health probe (sao:connection:health). Opt-in: off by
    // default so no live probe runs unless an operator enables it; the command
    // stays runnable on demand regardless.
    'health' => [
        'enabled' => (bool) env('SAO_HEALTH_SCHEDULE', false),
        'cron' => (string) env('SAO_HEALTH_CRON', '*/15 * * * *'),
    ],

    // Release-health read model (ReleaseHealthService). Post-hoc, correlation
    // only — never a promote/rollback gate. `window_days` bounds how long after a
    // release its signals are attributed to it; the baseline is the previous
    // release over an equal window. A pre-existing group key is "regressed" only
    // when its in-window count clears `regression_min_occurrences` AND exceeds its
    // baseline count by `regression_rate_factor`. The verdict turns Regressed once
    // any signal regresses or at least `regressed_new_signals` brand-new group
    // keys appear inside the window.
    'release_health' => [
        'window_days' => 7,
        'regression_rate_factor' => 1.5,
        'regression_min_occurrences' => 3,
        'regressed_new_signals' => 3,
    ],

    // Code-to-work attribution (CodeReferenceWriter / TicketReferenceExtractor).
    // A ticket key in a commit message or PR body preceded by one of these verbs
    // is recorded as a fix (counts as resolution evidence); any other reference is
    // a mention (timeline context only).
    'attribution' => [
        'closing_verbs' => [
            'fix', 'fixes', 'fixed',
            'close', 'closes', 'closed',
            'resolve', 'resolves', 'resolved',
        ],

        // Non-semver tags carrying one of these markers are treated as candidate
        // (RC) tags; a semver pre-release segment (1.4.0-rc.1) is always candidate
        // regardless of this list.
        'prerelease_markers' => [
            '-rc', '-beta', '-alpha', '-pre', '-dev', '-snapshot',
        ],
    ],

    // Evidence-based closure activation (ClosureCoordinator / sao:closure:run).
    // Off by default: closure policies only ever *propose* a close (recorded as a
    // ClosureAudit, no state change). Turn `auto_close.enabled` on to let a
    // satisfied `close` policy actually close the ticket through WorkflowService
    // (audited and auto-reversible on recurrence). A `shadow` binding keeps the
    // prudent `propose` default regardless.
    'closure' => [
        'auto_close' => [
            'enabled' => false,
        ],
    ],

    // Data retention (sao:prune). Hard-deletes aged, high-volume data so the store
    // does not grow without bound. Off the scheduler by default (`enabled`); the
    // command is always runnable, and `--dry-run` reports without deleting. Windows
    // are in days. `closed_project_days` is a grace period after a project is
    // deactivated before its heavy data (signals, occurrences, ingest, deployments,
    // tickets) is purged — the project, environments and releases stay as anagraphic.
    'retention' => [
        'enabled' => (bool) env('SAO_RETENTION_SCHEDULE', false),
        'cron' => (string) env('SAO_RETENTION_CRON', '0 3 * * *'),
        'signal_occurrences_days' => (int) env('SAO_RETENTION_OCCURRENCES_DAYS', 90),
        'ingest_events_days' => (int) env('SAO_RETENTION_INGEST_DAYS', 30),
        'deployments_days' => (int) env('SAO_RETENTION_DEPLOYMENTS_DAYS', 180),
        'closed_project_days' => (int) env('SAO_RETENTION_CLOSED_PROJECT_DAYS', 30),
    ],

    'drivers' => [
        // list<class-string<Modules\SAO\Drivers\Contracts\DriverInterface>>
        'registered' => [
            Modules\SAO\Drivers\External\RedmineDriver::class,
            Modules\SAO\Drivers\External\JiraDriver::class,
            Modules\SAO\Drivers\External\GitHubDriver::class,
            Modules\SAO\Drivers\External\GitLabDriver::class,
            Modules\SAO\Drivers\External\BitbucketDriver::class,
            Modules\SAO\Drivers\External\GiteaDriver::class,
            Modules\SAO\Drivers\External\YouTrackDriver::class,
            Modules\SAO\Drivers\External\AzureDevOpsDriver::class,
            Modules\SAO\Drivers\External\LinearDriver::class,
            Modules\SAO\Drivers\External\SentryDriver::class,
            Modules\SAO\Drivers\External\GraylogDriver::class,
            Modules\SAO\Drivers\External\GlitchTipDriver::class,
            Modules\SAO\Drivers\External\RollbarDriver::class,
            Modules\SAO\Drivers\External\BugsnagDriver::class,
            Modules\SAO\Drivers\External\HoneybadgerDriver::class,
            Modules\SAO\Drivers\External\GrafanaDriver::class,
            Modules\SAO\Drivers\External\DatadogDriver::class,
            Modules\SAO\Drivers\External\ElasticDriver::class,
            Modules\SAO\Drivers\External\BetterStackDriver::class,
            Modules\SAO\Drivers\External\WebhookDeployDriver::class,
            Modules\SAO\Drivers\External\GitHubDeploymentDriver::class,
            Modules\SAO\Drivers\External\WebhookCodeDriver::class,
            Modules\SAO\Drivers\External\GitHubPullRequestDriver::class,
        ],
    ],
];
