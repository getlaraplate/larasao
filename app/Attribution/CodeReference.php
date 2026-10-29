<?php

declare(strict_types=1);

namespace Modules\SAO\Attribution;

use Carbon\CarbonInterface;
use Modules\SAO\Enums\ChangeRefType;

/**
 * A normalized code artefact — a commit, pull request or tag — with the text
 * ({@see $text}, e.g. a commit message or a PR title + body) that may reference
 * tickets. Both the pull scan and the PR-merge webhook build this and hand it to
 * {@see CodeReferenceWriter}; the transport-agnostic middle of the attribution
 * pipeline.
 *
 * {@see $commitSha} is the commit an environment could contain to carry the change
 * (a merged pull request's merge commit); a commit reference is its own sha.
 */
final readonly class CodeReference
{
    public function __construct(
        public ChangeRefType $type,
        public string $identifier,
        public string $text,
        public ?string $url = null,
        public ?string $source = null,
        public ?CarbonInterface $mergedAt = null,
        public ?string $baseRef = null,
        public ?string $headRef = null,
        public ?string $commitSha = null,
    ) {}
}
