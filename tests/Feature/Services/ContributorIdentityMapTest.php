<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;
use Modules\SAO\Models\ContributorIdentity;
use Modules\SAO\Services\ContributorIdentityMap;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->map = new ContributorIdentityMap();
});

test('the map merges any-provider and provider-specific entries', function (): void {
    $ada = User::factory()->create();
    $grace = User::factory()->create();

    ContributorIdentity::factory()->anyProvider()->create(['identity' => 'ada@example.com', 'user_id' => $ada->id]);
    ContributorIdentity::factory()->forProvider('github')->create(['identity' => 'ghopper', 'user_id' => $grace->id]);

    expect($this->map->forProvider('github'))->toBe([
        'ada@example.com' => $ada->id,
        'ghopper' => $grace->id,
    ]);
});

test('a provider-specific entry overrides an any-provider one for the same identity', function (): void {
    $shared = User::factory()->create();
    $githubUser = User::factory()->create();

    ContributorIdentity::factory()->anyProvider()->create(['identity' => 'octo', 'user_id' => $shared->id]);
    ContributorIdentity::factory()->forProvider('github')->create(['identity' => 'octo', 'user_id' => $githubUser->id]);

    expect($this->map->forProvider('github')['octo'])->toBe($githubUser->id);
});

test('specific entries for another provider are excluded', function (): void {
    $gitlabUser = User::factory()->create();
    ContributorIdentity::factory()->forProvider('gitlab')->create(['identity' => 'glab', 'user_id' => $gitlabUser->id]);

    expect($this->map->forProvider('github'))->toBe([]);
});

test('the provider-specific rows are ordered last by a CASE, as Db2 cannot order by a predicate', function (): void {
    $sql = Db2PretendConnection::capture(function (): void {
        expect($this->map->forProvider('github'))->toBe([]);
    });

    expect($sql)->toHaveCount(1)
        ->and($sql[0])->toContain('order by CASE WHEN provider = ? THEN 1 ELSE 0 END asc')
        ->and($sql[0])->not->toContain('(provider = ?)');
});
