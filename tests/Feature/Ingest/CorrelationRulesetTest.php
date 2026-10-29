<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\MatchType;
use Modules\SAO\Ingest\CorrelationRuleset;
use Modules\SAO\Ingest\CorrelationScope;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\CorrelationRule;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Models\SourceProfile;

uses(RefreshDatabase::class);

function sao_correlation_rule(Project $project, string $pattern, array $attributes = []): CorrelationRule
{
    return CorrelationRule::factory()->create(array_merge([
        'project_id' => $project->getKey(),
        'target' => CorrelationTarget::ServerHost,
        'match' => MatchType::Exact,
        'pattern' => $pattern,
    ], $attributes));
}

function sao_correlation_logs_connection(): Connection
{
    return Connection::factory()->create([
        'driver_key' => 'glitchtip',
        'capabilities' => [Capability::Logs],
        'credential' => ['secret' => 'shared'],
        'base_url' => null,
    ]);
}

function sao_correlation_logs_bound(Connection $connection, Project $project): ProjectBinding
{
    return ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Logs,
    ]);
}

function sao_correlation_ruleset(): CorrelationRuleset
{
    return app(CorrelationRuleset::class);
}

test('priority then id breaks ties', function (): void {
    $low = Project::factory()->create();
    $high = Project::factory()->create();
    $profile = SourceProfile::factory()->create();

    sao_correlation_rule($low, 'web-01', ['priority' => 200]);
    $winner = sao_correlation_rule($high, 'web-01', ['priority' => 10]);
    sao_correlation_rule($low, 'web-01', ['priority' => 10]);

    $result = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forProfile(null, $profile));

    expect($result['project']?->getKey())->toBe($high->getKey())
        ->and($result['rule'])->toBe("rule:{$winner->getKey()}");
});

test('an inactive rule is skipped', function (): void {
    $project = Project::factory()->create();
    $profile = SourceProfile::factory()->create();
    sao_correlation_rule($project, 'web-01', ['is_active' => false]);

    $result = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forProfile(null, $profile));

    expect($result['project'])->toBeNull()
        ->and($result['rule'])->toBeNull();
});

test('a rule scoped to a connection is ignored on another one', function (): void {
    $project = Project::factory()->create();
    $profile = SourceProfile::factory()->create();
    $connectionA = sao_correlation_logs_connection();
    $connectionB = sao_correlation_logs_connection();
    $rule = sao_correlation_rule($project, 'web-01', ['connection_id' => $connectionA->getKey()]);

    $onA = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forProfile($connectionA, $profile));
    $onB = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forProfile($connectionB, $profile));
    $noConnection = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forProfile(null, $profile));

    expect($onA['rule'])->toBe("rule:{$rule->getKey()}")
        ->and($onB['project'])->toBeNull()
        ->and($noConnection['project'])->toBeNull();
});

test('a rule scoped to a profile is ignored for another one', function (): void {
    $project = Project::factory()->create();
    $profileP = SourceProfile::factory()->create();
    $profileQ = SourceProfile::factory()->create();
    $rule = sao_correlation_rule($project, 'web-01', ['source_profile_id' => $profileP->getKey()]);

    $forP = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forProfile(null, $profileP));
    $forQ = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forProfile(null, $profileQ));

    expect($forP['rule'])->toBe("rule:{$rule->getKey()}")
        ->and($forQ['project'])->toBeNull();
});

test('a rule beats the built-in project key rule', function (): void {
    $byKey = Project::factory()->create(['key_prefix' => 'WIDGETS']);
    $byRule = Project::factory()->create();
    $profile = SourceProfile::factory()->create();
    $rule = sao_correlation_rule($byRule, 'web-01');

    $result = sao_correlation_ruleset()->correlate(
        ['project_key' => $byKey->key_prefix, 'server_host' => 'web-01'],
        CorrelationScope::forProfile(null, $profile),
    );

    expect($result['project']?->getKey())->toBe($byRule->getKey())
        ->and($result['rule'])->toBe("rule:{$rule->getKey()}");
});

test('the built-in project key rule still works with no rules', function (): void {
    $project = Project::factory()->create(['key_prefix' => 'WIDGETS']);
    $profile = SourceProfile::factory()->create();

    $result = sao_correlation_ruleset()->correlate(['project_key' => 'WIDGETS'], CorrelationScope::forProfile(null, $profile));

    expect($result['project']?->getKey())->toBe($project->getKey())
        ->and($result['rule'])->toBe('project_key');
});

test('on the logs path two bindings and no rule gives no project', function (): void {
    $connection = sao_correlation_logs_connection();
    sao_correlation_logs_bound($connection, Project::factory()->create());
    sao_correlation_logs_bound($connection, Project::factory()->create());

    $result = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forLogs($connection));

    expect($result['project'])->toBeNull()
        ->and($result['rule'])->toBeNull();
});

test('a single logs binding routes the event to its project', function (): void {
    $connection = sao_correlation_logs_connection();
    $project = Project::factory()->create();
    sao_correlation_logs_bound($connection, $project);
    sao_correlation_logs_bound(sao_correlation_logs_connection(), Project::factory()->create());

    $result = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forLogs($connection));

    expect($result['project']?->getKey())->toBe($project->getKey())
        ->and($result['rule'])->toBe('binding');
});

test('on the logs path a rule to a project without a logs binding is skipped and the next rule wins', function (): void {
    $connection = sao_correlation_logs_connection();
    $unbound = Project::factory()->create();
    $bound = Project::factory()->create();
    sao_correlation_logs_bound($connection, $bound);
    sao_correlation_logs_bound($connection, Project::factory()->create());
    sao_correlation_rule($unbound, 'web-01', ['priority' => 1]);
    $winner = sao_correlation_rule($bound, 'web-01', ['priority' => 2]);

    $result = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forLogs($connection));

    expect($result['project']?->getKey())->toBe($bound->getKey())
        ->and($result['rule'])->toBe("rule:{$winner->getKey()}");
});

test('on the logs path a project key hit on a project without a logs binding does not match', function (): void {
    $connection = sao_correlation_logs_connection();
    $unbound = Project::factory()->create(['key_prefix' => 'WIDGETS']);
    $bound = Project::factory()->create();
    sao_correlation_logs_bound($connection, $bound);

    $result = sao_correlation_ruleset()->correlate(['project_key' => 'WIDGETS'], CorrelationScope::forLogs($connection));

    expect($result['project']?->getKey())->toBe($bound->getKey())
        ->and($result['rule'])->toBe('binding');

    sao_correlation_logs_bound($connection, $unbound);

    $routed = sao_correlation_ruleset()->correlate(['project_key' => 'WIDGETS'], CorrelationScope::forLogs($connection));

    expect($routed['project']?->getKey())->toBe($unbound->getKey())
        ->and($routed['rule'])->toBe('project_key');
});

test('the same rule routes freely on the generic path', function (): void {
    $project = Project::factory()->create();
    $profile = SourceProfile::factory()->create();
    sao_correlation_rule($project, 'web-01');

    $result = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forProfile(sao_correlation_logs_connection(), $profile));

    expect($result['project']?->getKey())->toBe($project->getKey());
});

test('the request host and project key targets are evaluated', function (): void {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $profile = SourceProfile::factory()->create();
    sao_correlation_rule($project, '*.example.com', ['target' => CorrelationTarget::RequestHost, 'match' => MatchType::Wildcard]);
    sao_correlation_rule($other, 'ext-key', ['target' => CorrelationTarget::ProjectKey, 'priority' => 200]);
    $scope = CorrelationScope::forProfile(null, $profile);

    $byHost = sao_correlation_ruleset()->correlate(['request_url' => 'https://shop.example.com:8443/a?b=1'], $scope);
    $byKey = sao_correlation_ruleset()->correlate(['project_key' => 'EXT-KEY'], $scope);
    $none = sao_correlation_ruleset()->correlate(['request_url' => 'not a url'], $scope);

    expect($byHost['project']?->getKey())->toBe($project->getKey())
        ->and($byKey['project']?->getKey())->toBe($other->getKey())
        ->and($none['project'])->toBeNull();
});

test('the environment is the rule one, else the payload one, else the server host, else null', function (): void {
    $project = Project::factory()->create();
    $profile = SourceProfile::factory()->create();
    $scope = CorrelationScope::forProfile(null, $profile);
    sao_correlation_rule($project, 'web-01', ['environment' => 'staging']);

    $fromRule = sao_correlation_ruleset()->correlate(['server_host' => 'web-01', 'environment' => 'production'], $scope);

    CorrelationRule::query()->delete();
    Project::factory()->create(['key_prefix' => 'WIDGETS']);
    $fromPayload = sao_correlation_ruleset()->correlate(['project_key' => 'WIDGETS', 'environment' => 'production', 'server_host' => 'web-01'], $scope);
    $fromHost = sao_correlation_ruleset()->correlate(['project_key' => 'WIDGETS', 'server_host' => 'web-01'], $scope);
    $none = sao_correlation_ruleset()->correlate(['project_key' => 'WIDGETS'], $scope);

    expect($fromRule['environment'])->toBe('staging')
        ->and($fromPayload['environment'])->toBe('production')
        ->and($fromHost['environment'])->toBe('web-01')
        ->and($none['environment'])->toBeNull();
});

test('on the logs path the binding of a deleted project is ignored, so the live single binding still routes', function (): void {
    $connection = sao_correlation_logs_connection();
    $live = Project::factory()->create();
    $deleted = Project::factory()->create();
    sao_correlation_logs_bound($connection, $live);
    sao_correlation_logs_bound($connection, $deleted);
    $deleted->delete();

    $result = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forLogs($connection));

    expect(Project::withTrashed()->find($deleted->getKey())?->trashed())->toBeTrue()
        ->and($result['project']?->getKey())->toBe($live->getKey())
        ->and($result['rule'])->toBe('binding');
});

test('a rule targeting a deleted project is skipped and the next rule wins', function (): void {
    $deleted = Project::factory()->create();
    $live = Project::factory()->create();
    $profile = SourceProfile::factory()->create();
    sao_correlation_rule($deleted, 'web-01', ['priority' => 1]);
    $winner = sao_correlation_rule($live, 'web-01', ['priority' => 2]);
    $deleted->delete();

    $result = sao_correlation_ruleset()->correlate(['server_host' => 'web-01'], CorrelationScope::forProfile(null, $profile));

    expect($result['project']?->getKey())->toBe($live->getKey())
        ->and($result['rule'])->toBe("rule:{$winner->getKey()}");
});
