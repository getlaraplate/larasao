<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\Contracts;

use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\Page;

/**
 * Read commits and ranges, compare branches, read a file at a ref, open PRs.
 *
 * Contract shape only in phase 3a; a real driver implements it in phase 5.
 */
interface VcsCapability
{
    public function commits(BindingContext $context, string $range, ?string $cursor = null): Page;

    /**
     * @return array<string, mixed>
     */
    public function compare(BindingContext $context, string $base, string $head): array;

    /**
     * Whether `$sha` is reachable from `$ref` (a branch, tag or commit): true when the
     * ref contains the commit (the commit itself counts), false when it does not
     * (diverged or behind), null when the driver cannot tell.
     *
     * Null covers an unknown commit or ref, a sha too short or not hexadecimal to be
     * asked about, any provider error and an unexpected response; it never throws for
     * an HTTP or network failure. A caller must not store null as false: null is retried.
     */
    public function containsCommit(BindingContext $context, string $sha, string $ref): ?bool;

    public function fileAtRef(BindingContext $context, string $path, string $ref): ?string;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function openPullRequest(BindingContext $context, array $attributes): array;
}
