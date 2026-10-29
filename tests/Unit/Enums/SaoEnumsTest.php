<?php

declare(strict_types=1);

use Modules\SAO\Enums\CommentOrigin;
use Modules\SAO\Enums\ReleaseStatus;
use Modules\SAO\Enums\ReleaseTagKind;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Enums\StatusCategory;
use Modules\SAO\Enums\TicketPriority;
use Modules\SAO\Enums\TicketReleaseState;

test('every SAO table name is prefixed with sao_', function (): void {
    foreach (SAOTables::cases() as $case) {
        expect($case->value)->toStartWith('sao_');
    }
});

test('the table registry declares the phase 1a tables plus the phase 3a connections table', function (): void {
    $values = array_column(SAOTables::cases(), 'value');

    expect($values)->toEqualCanonicalizing([
        'sao_projects',
        'sao_ticket_statuses',
        'sao_workflow_schemes',
        'sao_workflow_transitions',
        'sao_ticket_types',
        'sao_project_ticket_types',
        'sao_tickets',
        'sao_ticket_comments',
        'sao_labels',
        'sao_ticket_label',
        'sao_ticket_watchers',
        'sao_ticket_relations',
        'sao_saved_filters',
        'sao_signals',
        'sao_signal_occurrences',
        'sao_signal_aliases',
        'sao_source_profiles',
        'sao_ingest_events',
        'sao_change_refs',
        'sao_releases',
        'sao_release_tags',
        'sao_ticket_releases',
        'sao_environments',
        'sao_deployments',
        'sao_closure_policies',
        'sao_closure_audits',
        'sao_ownership_suggestions',
        'sao_contributor_identities',
        'sao_connections',
        'sao_project_bindings',
        'sao_ticket_links',
        'sao_sync_operations',
        'sao_import_runs',
        'sao_correlation_rules',
    ]);
});

test('status categories are the five canonical ones', function (): void {
    expect(StatusCategory::values())->toBe([
        'open',
        'in_progress',
        'resolved',
        'closed',
        'rejected',
    ]);
});

test('a closed category is terminal and an open one is not', function (): void {
    expect(StatusCategory::Closed->isTerminal())->toBeTrue();
    expect(StatusCategory::Rejected->isTerminal())->toBeTrue();
    expect(StatusCategory::Open->isTerminal())->toBeFalse();
    expect(StatusCategory::InProgress->isTerminal())->toBeFalse();
    expect(StatusCategory::Resolved->isTerminal())->toBeFalse();
});

test('priorities are fixed and ordered from low to urgent', function (): void {
    expect(TicketPriority::values())->toBe(['low', 'normal', 'high', 'urgent']);
});

test('comment origins distinguish humans from automation', function (): void {
    expect(CommentOrigin::values())->toBe(['human', 'system']);
});

test('release tag kinds are ordered by maturity precedence', function (): void {
    expect(ReleaseTagKind::values())->toBe(['alpha', 'beta', 'candidate', 'stable']);

    $sorted = collect(ReleaseTagKind::cases())
        ->sortBy(fn (ReleaseTagKind $kind): int => $kind->precedence())
        ->values()
        ->all();

    expect($sorted)->toBe([
        ReleaseTagKind::Alpha,
        ReleaseTagKind::Beta,
        ReleaseTagKind::Candidate,
        ReleaseTagKind::Stable,
    ]);
});

test('release status carries the observed lifecycle stage', function (): void {
    expect(ReleaseStatus::values())->toBe(['observed', 'announced', 'shipped']);
});

test('ticket release state carries the affected attribution', function (): void {
    expect(TicketReleaseState::values())->toBe(['affected', 'promised', 'shipped']);
});

test('every enum exposes an in: validation rule', function (string $rule): void {
    expect($rule)->toStartWith('in:');
})->with([
    fn (): string => StatusCategory::validationRule(),
    fn (): string => TicketPriority::validationRule(),
    fn (): string => CommentOrigin::validationRule(),
]);
