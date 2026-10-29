<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\SAO\Enums\ChangeRefRelation;
use Modules\SAO\Enums\ChangeRefType;
use Modules\SAO\Enums\TicketReleaseState;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\FixPresence;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketRelease;
use Modules\SAO\Services\FixStatusResolver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->resolver = new FixStatusResolver();
});

test('a signal can be correlated with a ticket and back', function (): void {
    $ticket = Ticket::factory()->create();
    $signal = Signal::factory()->create(['ticket_id' => $ticket->id]);

    expect($signal->ticket->is($ticket))->toBeTrue()
        ->and($ticket->signals()->pluck('id')->all())->toBe([$signal->id]);
});

test('the merged pull requests scope selects only merged pull-request refs', function (): void {
    $ticket = Ticket::factory()->create();
    ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'type' => ChangeRefType::Commit, 'identifier' => 'abc']);
    ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'type' => ChangeRefType::PullRequest, 'identifier' => '7', 'merged_at' => null]);
    $merged = ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'type' => ChangeRefType::PullRequest, 'identifier' => '8', 'merged_at' => now()]);

    expect(ChangeRef::query()->mergedPullRequests()->pluck('id')->all())->toBe([$merged->id])
        ->and($merged->isMergedPullRequest())->toBeTrue();
});

test('a merged pull request that only mentions the ticket is not counted as a fix', function (): void {
    $ticket = Ticket::factory()->create();
    ChangeRef::factory()->create([
        'ticket_id' => $ticket->id,
        'type' => ChangeRefType::PullRequest,
        'identifier' => '11',
        'merged_at' => now(),
        'relation' => ChangeRefRelation::Mentions,
    ]);

    expect($this->resolver->forTicket($ticket->refresh())->pull_request_merged)->toBeFalse();

    ChangeRef::factory()->create([
        'ticket_id' => $ticket->id,
        'type' => ChangeRefType::PullRequest,
        'identifier' => '12',
        'merged_at' => now(),
        'relation' => ChangeRefRelation::Fixes,
    ]);

    expect($this->resolver->forTicket($ticket->refresh())->pull_request_merged)->toBeTrue();
});

test('a fix released but deployed only to staging is reported as missing on production', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'type' => ChangeRefType::PullRequest, 'identifier' => '9', 'merged_at' => now()]);

    $release = Release::factory()->for($project)->shipped()->create(['version' => '1.4.0']);
    TicketRelease::factory()->create([
        'ticket_id' => $ticket->id,
        'release_id' => $release->id,
        'state' => TicketReleaseState::Shipped,
    ]);

    Environment::factory()->for($project)->create(['name' => 'staging', 'current_version' => '1.4.0']);
    Environment::factory()->for($project)->create(['name' => 'production', 'current_version' => '1.3.9']);

    $status = $this->resolver->forTicket($ticket->refresh(), 'production');

    expect($status->pull_request_merged)->toBeTrue()
        ->and($status->fix_released)->toBeTrue()
        ->and($status->released_version)->toBe('1.4.0')
        ->and($status->deployed_environments)->toBe(['staging'])
        ->and($status->missing_environments)->toBe(['production'])
        ->and($status->deployed_there)->toBeFalse();
});

test('without a shipped release the fix is not released', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $release = Release::factory()->for($project)->create(['version' => '2.0.0']);
    TicketRelease::factory()->create([
        'ticket_id' => $ticket->id,
        'release_id' => $release->id,
        'state' => TicketReleaseState::Promised,
    ]);

    $status = $this->resolver->forTicket($ticket->refresh(), 'production');

    expect($status->fix_released)->toBeFalse()
        ->and($status->released_version)->toBeNull()
        ->and($status->deployed_there)->toBeFalse();
});

/**
 * @return array{0: Project, 1: Ticket}
 */
function containmentProject(): array
{
    $project = Project::factory()->create();

    return [$project, Ticket::factory()->for($project)->create()];
}

test('an environment whose presence row for its current commit is true is contained, false is not contained, null is neither', function (): void {
    [$project, $ticket] = containmentProject();
    $fix = ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'relation' => ChangeRefRelation::Fixes, 'commit_sha' => str_repeat('a', 40)]);

    $dev = Environment::factory()->for($project)->create(['name' => 'dev', 'current_commit' => str_repeat('1', 40)]);
    $staging = Environment::factory()->for($project)->create(['name' => 'staging', 'current_commit' => str_repeat('2', 40)]);
    $production = Environment::factory()->for($project)->create(['name' => 'production', 'current_commit' => str_repeat('3', 40)]);

    FixPresence::factory()->create(['change_ref_id' => $fix->id, 'environment_id' => $dev->id, 'deployed_commit' => $dev->current_commit, 'contained' => true]);
    FixPresence::factory()->create(['change_ref_id' => $fix->id, 'environment_id' => $staging->id, 'deployed_commit' => $staging->current_commit, 'contained' => false]);
    FixPresence::factory()->unknown()->create(['change_ref_id' => $fix->id, 'environment_id' => $production->id, 'deployed_commit' => $production->current_commit]);

    $status = $this->resolver->forTicket($ticket->refresh());

    expect($status->contained_environments)->toBe(['dev'])
        ->and($status->not_contained_environments)->toBe(['staging']);
});

test('a presence row for an older commit than the current one counts for nothing', function (): void {
    [$project, $ticket] = containmentProject();
    $fix = ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'relation' => ChangeRefRelation::Fixes, 'commit_sha' => str_repeat('a', 40)]);

    $environment = Environment::factory()->for($project)->create(['name' => 'production', 'current_commit' => str_repeat('3', 40)]);
    FixPresence::factory()->create(['change_ref_id' => $fix->id, 'environment_id' => $environment->id, 'deployed_commit' => str_repeat('9', 40), 'contained' => true]);

    $status = $this->resolver->forTicket($ticket->refresh());

    expect($status->contained_environments)->toBe([])
        ->and($status->not_contained_environments)->toBe([]);

    FixPresence::factory()->create(['change_ref_id' => $fix->id, 'environment_id' => $environment->id, 'deployed_commit' => $environment->current_commit, 'contained' => false]);

    expect($this->resolver->forTicket($ticket->refresh())->not_contained_environments)->toBe(['production']);
});

test('with several fixing change refs one contained row makes the environment contained', function (): void {
    [$project, $ticket] = containmentProject();
    $first = ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'relation' => ChangeRefRelation::Fixes, 'commit_sha' => str_repeat('a', 40)]);
    $second = ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'relation' => ChangeRefRelation::Fixes, 'commit_sha' => str_repeat('b', 40)]);

    $environment = Environment::factory()->for($project)->create(['name' => 'production', 'current_commit' => str_repeat('3', 40)]);
    FixPresence::factory()->create(['change_ref_id' => $first->id, 'environment_id' => $environment->id, 'deployed_commit' => $environment->current_commit, 'contained' => false]);
    FixPresence::factory()->create(['change_ref_id' => $second->id, 'environment_id' => $environment->id, 'deployed_commit' => $environment->current_commit, 'contained' => true]);

    $status = $this->resolver->forTicket($ticket->refresh());

    expect($status->contained_environments)->toBe(['production'])
        ->and($status->not_contained_environments)->toBe([]);
});

test('mentions and other projects environments never feed the containment lists', function (): void {
    [$project, $ticket] = containmentProject();
    $mention = ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'relation' => ChangeRefRelation::Mentions, 'commit_sha' => str_repeat('a', 40)]);
    $environment = Environment::factory()->for($project)->create(['name' => 'dev', 'current_commit' => str_repeat('1', 40)]);
    FixPresence::factory()->create(['change_ref_id' => $mention->id, 'environment_id' => $environment->id, 'deployed_commit' => $environment->current_commit, 'contained' => true]);

    $fix = ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'relation' => ChangeRefRelation::Fixes, 'commit_sha' => str_repeat('b', 40)]);
    $foreign = Environment::factory()->create(['name' => 'aaa', 'current_commit' => str_repeat('4', 40)]);
    FixPresence::factory()->create(['change_ref_id' => $fix->id, 'environment_id' => $foreign->id, 'deployed_commit' => $foreign->current_commit, 'contained' => true]);

    $status = $this->resolver->forTicket($ticket->refresh());

    expect($status->contained_environments)->toBe([])
        ->and($status->not_contained_environments)->toBe([]);
});

test('containment is reported with a shipped release too and leaves the deployment lists untouched', function (): void {
    [$project, $ticket] = containmentProject();
    $fix = ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'relation' => ChangeRefRelation::Fixes, 'commit_sha' => str_repeat('a', 40)]);
    $release = Release::factory()->for($project)->shipped()->create(['version' => '1.4.0']);
    TicketRelease::factory()->create(['ticket_id' => $ticket->id, 'release_id' => $release->id, 'state' => TicketReleaseState::Shipped]);

    $staging = Environment::factory()->for($project)->create(['name' => 'staging', 'current_version' => '1.4.0', 'current_commit' => str_repeat('2', 40)]);
    $production = Environment::factory()->for($project)->create(['name' => 'production', 'current_version' => '1.3.9', 'current_commit' => str_repeat('3', 40)]);
    FixPresence::factory()->create(['change_ref_id' => $fix->id, 'environment_id' => $staging->id, 'deployed_commit' => $staging->current_commit, 'contained' => true]);
    FixPresence::factory()->create(['change_ref_id' => $fix->id, 'environment_id' => $production->id, 'deployed_commit' => $production->current_commit, 'contained' => false]);

    $status = $this->resolver->forTicket($ticket->refresh(), 'production');

    expect($status->deployed_environments)->toBe(['staging'])
        ->and($status->missing_environments)->toBe(['production'])
        ->and($status->deployed_there)->toBeFalse()
        ->and($status->contained_environments)->toBe(['staging'])
        ->and($status->not_contained_environments)->toBe(['production']);
});

test('containment costs one presence query however many environments there are', function (): void {
    [$project, $ticket] = containmentProject();
    $fix = ChangeRef::factory()->create(['ticket_id' => $ticket->id, 'relation' => ChangeRefRelation::Fixes, 'commit_sha' => str_repeat('a', 40)]);

    foreach (['dev', 'staging', 'production'] as $index => $name) {
        $environment = Environment::factory()->for($project)->create(['name' => $name, 'current_commit' => str_repeat((string) $index, 40)]);
        FixPresence::factory()->create(['change_ref_id' => $fix->id, 'environment_id' => $environment->id, 'deployed_commit' => $environment->current_commit, 'contained' => true]);
    }

    DB::enableQueryLog();
    $this->resolver->forTicket($ticket->refresh());
    $presenceQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains((string) $query['query'], 'sao_fix_presences'))->count();
    DB::disableQueryLog();

    expect($presenceQueries)->toBe(1);
});
