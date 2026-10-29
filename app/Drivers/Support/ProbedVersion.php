<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\Support;

/**
 * What a probe learned about a running environment. The version is always
 * present; the commit and branch only when the endpoint reports them.
 */
final readonly class ProbedVersion
{
    public function __construct(
        public string $version,
        public ?string $commit = null,
        public ?string $branch = null,
    ) {}
}
