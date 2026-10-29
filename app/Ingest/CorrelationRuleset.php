<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Models\CorrelationRule;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;

/**
 * The ordered, inspectable ruleset that attaches an ingested event to a project.
 * Every correlation records which rule won (or that none did), so a misroute is
 * diagnosable rather than silent (§14). It stops at the first match, in order:
 * the operator rules in scope (priority, then id; recorded `rule:{id}`), the
 * built-in rule mapping a canonical `project_key` to a `Project.key_prefix`
 * (recorded `project_key`), and, on the driver path only, the connection's
 * single `logs` binding (recorded `binding`). The driver path never routes
 * outside the projects bound to the connection with `logs`: an operator rule or
 * a project key naming any other project does not match there.
 *
 * The environment is the winning rule's, else the payload's, else the event's
 * `server_host`, else none.
 */
final class CorrelationRuleset
{
    /**
     * @param  array<string, mixed>  $canonical
     * @return array{project: ?Project, rule: ?string, environment: ?string}
     */
    public function correlate(array $canonical, CorrelationScope $scope): array
    {
        $logsBindings = $scope->logsBindingRequired ? $this->logsBindings($scope) : null;
        $logsProjectIds = $logsBindings === null ? null : array_map(static fn (ProjectBinding $binding): int => (int) $binding->project_id, $logsBindings);

        foreach ($this->rulesInScope($scope) as $rule) {
            if ($logsProjectIds !== null && ! in_array($rule->project_id, $logsProjectIds, true)) {
                continue;
            }

            $value = $rule->target->valueFrom($canonical);

            if ($value === null || ! $rule->match->matches($rule->pattern, $value)) {
                continue;
            }

            if ($rule->project instanceof Project) {
                return $this->result($canonical, $rule->project, "rule:{$rule->getKey()}", $rule->environment);
            }
        }

        $projectKey = CorrelationTarget::ProjectKey->valueFrom($canonical);

        if ($projectKey !== null) {
            $project = Project::query()->where('key_prefix', $projectKey)->first();

            if ($project instanceof Project && ($logsProjectIds === null || in_array((int) $project->getKey(), $logsProjectIds, true))) {
                return $this->result($canonical, $project, 'project_key', null);
            }
        }

        if ($logsBindings !== null && count($logsBindings) === 1 && $logsBindings[0]->project instanceof Project) {
            return $this->result($canonical, $logsBindings[0]->project, 'binding', null);
        }

        return $this->result($canonical, null, null, null);
    }

    /**
     * @param  Builder<CorrelationRule>  $query
     * @return Builder<CorrelationRule>
     */
    private static function inScope(Builder $query, string $column, ?int $value): Builder
    {
        $query->whereNull($column);

        return $value === null ? $query : $query->orWhere($column, $value);
    }

    /**
     * @return Collection<int, CorrelationRule>
     */
    private function rulesInScope(CorrelationScope $scope): Collection
    {
        return CorrelationRule::query()
            ->with('project')
            ->active()
            ->where(static fn (Builder $query): Builder => self::inScope($query, 'connection_id', $scope->connectionId))
            ->where(static fn (Builder $query): Builder => self::inScope($query, 'source_profile_id', $scope->sourceProfileId))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<ProjectBinding>
     */
    private function logsBindings(CorrelationScope $scope): array
    {
        return ProjectBinding::query()
            ->with('project')
            ->where('connection_id', $scope->connectionId)
            ->where('capability', Capability::Logs)
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array{project: ?Project, rule: ?string, environment: ?string}
     */
    private function result(array $canonical, ?Project $project, ?string $rule, ?string $ruleEnvironment): array
    {
        return [
            'project' => $project,
            'rule' => $rule,
            'environment' => $ruleEnvironment ?? $this->fallbackEnvironment($canonical),
        ];
    }

    /**
     * @param  array<string, mixed>  $canonical
     */
    private function fallbackEnvironment(array $canonical): ?string
    {
        foreach (['environment', 'server_host'] as $field) {
            $value = $canonical[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
