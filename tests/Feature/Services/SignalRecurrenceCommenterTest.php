<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Enums\CommentOrigin;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Enums\StatusCategory;
use Modules\SAO\Ingest\IngestReprocessService;
use Modules\SAO\Ingest\SignalIngestService;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Models\SourceProfile;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketComment;
use Modules\SAO\Models\TicketStatus;
use Modules\SAO\Services\SignalRecurrenceCommenter;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function recurrence_payload(array $overrides = []): array
{
    return array_merge([
        'kind' => 'exception',
        'class' => 'RuntimeException',
        'file' => 'Modules/AI/app/Jobs/GenerateEmbeddingsJob.php',
        'function' => 'handle',
        'message' => 'Model failed id=1',
        'environment' => 'production',
    ], $overrides);
}

/**
 * A project with a signal that already has one occurrence at the frozen base time, a ticket created five
 * minutes later and linked to the signal. Time is frozen at one minute after the ticket.
 *
 * @return array{0: Project, 1: Signal, 2: Ticket, 3: CarbonImmutable}
 */
function recurrence_linked_signal(StatusCategory $category = StatusCategory::Open, bool $linked = true): array
{
    $base = CarbonImmutable::now()->startOfSecond();
    test()->travelTo($base);

    $project = Project::factory()->create();
    $signal = app(SignalIngestService::class)->ingest($project, recurrence_payload());

    test()->travelTo($base->addMinutes(5));
    $ticket = Ticket::factory()->forProject($project)->create([
        'ticket_status_id' => TicketStatus::factory()->category($category)->create()->getKey(),
    ]);

    if ($linked) {
        $signal->update(['ticket_id' => $ticket->getKey()]);
    }

    test()->travelTo($base->addMinutes(6));

    return [$project, $signal, $ticket, $base];
}

function recurrence_comments(Ticket $ticket): Illuminate\Support\Collection
{
    return TicketComment::query()->where('ticket_id', $ticket->getKey())->orderBy('id')->get();
}

test('an occurrence on an open ticket posts a system comment with the source key', function (): void {
    [$project, $signal, $ticket] = recurrence_linked_signal();

    app(SignalIngestService::class)->ingest($project, recurrence_payload());

    $comment = recurrence_comments($ticket)->sole();

    expect($comment->origin)->toBe(CommentOrigin::System)
        ->and($comment->author_id)->toBeNull()
        ->and($comment->source_key)->toBe('signal-recurrence:' . $signal->getKey())
        ->and($comment->source_key)->toBe(SignalRecurrenceCommenter::sourceKey($signal));
});

test('an occurrence on an in-progress ticket posts a comment', function (): void {
    [$project, , $ticket] = recurrence_linked_signal(StatusCategory::InProgress);

    app(SignalIngestService::class)->ingest($project, recurrence_payload());

    expect(recurrence_comments($ticket))->toHaveCount(1);
});

test('an occurrence on a terminal ticket posts nothing', function (StatusCategory $category): void {
    [$project, , $ticket] = recurrence_linked_signal($category);

    app(SignalIngestService::class)->ingest($project, recurrence_payload());

    expect(recurrence_comments($ticket))->toHaveCount(0);
})->with([
    'closed' => [StatusCategory::Closed],
    'rejected' => [StatusCategory::Rejected],
]);

test('a signal with no ticket posts nothing', function (): void {
    [$project, , $ticket] = recurrence_linked_signal(linked: false);

    app(SignalIngestService::class)->ingest($project, recurrence_payload());

    expect(TicketComment::query()->count())->toBe(0)
        ->and(recurrence_comments($ticket))->toHaveCount(0);
});

test('nothing is posted when the setting is off', function (): void {
    [$project, , $ticket] = recurrence_linked_signal();
    config(['sao.signals.recurrence_comment.enabled' => false]);

    app(SignalIngestService::class)->ingest($project, recurrence_payload());

    expect(recurrence_comments($ticket))->toHaveCount(0);
});

test('an occurrence dropped by the rate limit posts nothing', function (): void {
    [$project, $signal, $ticket] = recurrence_linked_signal();
    config(['sao.signals.max_occurrences_per_window' => 1]);

    app(SignalIngestService::class)->ingest($project, recurrence_payload());

    expect($signal->fresh()->occurrence_count)->toBe(1)
        ->and(recurrence_comments($ticket))->toHaveCount(0);
});

test('a second occurrence inside the cooldown posts nothing, and one after it posts the counts of its span', function (): void {
    [$project, , $ticket, $base] = recurrence_linked_signal();
    $service = app(SignalIngestService::class);

    $service->ingest($project, recurrence_payload(['environment' => 'production', 'version' => '1.0.0']));
    $firstCommentAt = $base->addMinutes(6);

    $this->travelTo($firstCommentAt->addMinutes(30));
    $service->ingest($project, recurrence_payload(['environment' => 'staging', 'version' => '1.0.0']));

    expect(recurrence_comments($ticket))->toHaveCount(1);

    $this->travelTo($firstCommentAt->addMinutes(61));
    $service->ingest($project, recurrence_payload(['environment' => 'production', 'version' => '1.1.0']));

    $comments = recurrence_comments($ticket);
    $first = $comments->first()->body;
    $second = $comments->last()->body;

    expect($comments)->toHaveCount(2)
        ->and($first)->toContain('1 time since the ticket was created')
        ->and($first)->toContain('Environments: production')
        ->and($first)->toContain('Versions: 1.0.0')
        ->and($second)->toContain('2 times since the previous recurrence comment')
        ->and($second)->toContain('First occurrence in this span: ' . $firstCommentAt->addMinutes(30)->utc()->format('Y-m-d H:i:s') . ' UTC')
        ->and($second)->toContain('Last occurrence in this span: ' . $firstCommentAt->addMinutes(61)->utc()->format('Y-m-d H:i:s') . ' UTC')
        ->and($second)->toContain('Environments: production, staging')
        ->and($second)->toContain('Versions: 1.0.0, 1.1.0');
});

test('a cooldown of zero behaves as one minute', function (): void {
    [$project, , $ticket, $base] = recurrence_linked_signal();
    config(['sao.signals.recurrence_comment.cooldown_minutes' => 0]);
    $service = app(SignalIngestService::class);

    $service->ingest($project, recurrence_payload());
    $service->ingest($project, recurrence_payload());

    expect(recurrence_comments($ticket))->toHaveCount(1);

    $this->travelTo($base->addMinutes(6)->addMinutes(2));
    $service->ingest($project, recurrence_payload());

    expect(recurrence_comments($ticket))->toHaveCount(2);
});

test('the span is counted by recording time so replayed occurrences count', function (): void {
    [$project, , $ticket] = recurrence_linked_signal();

    app(SignalIngestService::class)->ingest($project, recurrence_payload(), now()->subDays(30));

    $comment = recurrence_comments($ticket)->sole();

    expect($comment->body)->toContain('1 time since the ticket was created')
        ->and($comment->body)->toContain('First occurrence in this span: ' . now()->subDays(30)->utc()->format('Y-m-d H:i:s') . ' UTC');
});

test('reprocessing three hundred old events of one signal posts exactly one comment and keeps the original times', function (): void {
    $project = Project::factory()->create(['key_prefix' => 'WIDGETS']);
    SourceProfile::factory()->create([
        'name' => 'glitchtip',
        'matchers' => [['path' => 'source', 'operator' => 'equals', 'value' => 'glitchtip']],
        'field_bindings' => ['message' => 'error.message', 'class' => 'error.type', 'project_key' => 'project.slug'],
    ]);
    $payload = [
        'source' => 'glitchtip',
        'project' => ['slug' => 'WIDGETS'],
        'error' => ['type' => 'RuntimeException', 'message' => 'boom id=1'],
    ];
    $oldest = now()->subDays(20)->startOfSecond();

    foreach (range(0, 299) as $index) {
        IngestEvent::factory()->create([
            'pipeline' => IngestPipeline::Profile,
            'payload' => $payload,
            'status' => IngestStatus::Uncorrelated,
            'created_at' => $oldest->addMinutes($index),
        ]);
    }

    $service = app(IngestReprocessService::class);
    $service->reprocess(IngestEvent::query()->oldest('id')->firstOrFail());

    $signal = Signal::query()->sole();
    $ticket = Ticket::factory()->forProject($project)->create();
    $signal->update(['ticket_id' => $ticket->getKey()]);
    $this->travelTo(now()->addMinutes(1));

    $service->reprocessMany(IngestEvent::query()->where('status', IngestStatus::Uncorrelated));

    $occurredAt = SignalOccurrence::query()->where('signal_id', $signal->getKey())->orderBy('occurred_at');

    expect(IngestEvent::query()->where('status', IngestStatus::Ingested)->count())->toBe(300)
        ->and($signal->fresh()->occurrence_count)->toBe(300)
        ->and(recurrence_comments($ticket))->toHaveCount(1)
        ->and((clone $occurredAt)->first()->occurred_at->equalTo($oldest))->toBeTrue()
        ->and((clone $occurredAt)->where('occurred_at', '>=', now()->subDays(1))->count())->toBe(0);
});
