<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Modules\SAO\Models\ContributorIdentity;

/**
 * Builds the `identity => user_id` map the ownership resolvers consume from the
 * persisted contributor directory. Provider-agnostic entries form the base and
 * provider-specific ones override them, so a handle that means different people
 * on two hosts resolves correctly per provider.
 */
final class ContributorIdentityMap
{
    /**
     * The provider-specific rows are ordered by a CASE, not by a bare comparison: Db2 cannot order by a predicate.
     *
     * @return array<string, int>
     */
    public function forProvider(string $provider): array
    {
        $map = [];

        // Load the any-provider base first, then let provider-specific rows win
        // by overwriting as we iterate.
        $rows = ContributorIdentity::query()
            ->whereIn('provider', [ContributorIdentity::ANY_PROVIDER, $provider])
            ->orderByRaw('CASE WHEN provider = ? THEN 1 ELSE 0 END asc', [$provider])
            ->get(['provider', 'identity', 'user_id']);

        foreach ($rows as $row) {
            $map[$row->identity] = $row->user_id;
        }

        return $map;
    }
}
