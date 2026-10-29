<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\SourceProfile;

/**
 * A pure dry-run: replay a stored event's payload against a (possibly modified)
 * profile to see what *would* happen — the canonical fields, the correlation and
 * the would-be status — without writing anything. It lets an operator tune a
 * profile against a real payload before making it live.
 */
final readonly class IngestReplayService
{
    public function __construct(
        private PayloadMatcher $matcher,
        private PayloadNormalizer $normalizer,
        private CorrelationRuleset $ruleset,
    ) {}

    /**
     * @return array{matches: bool, canonical: array<string, mixed>, project_id: ?int, winning_rule: ?string, environment: ?string, would_be_status: string}
     */
    public function dryRun(IngestEvent $event, SourceProfile $profile): array
    {
        $payload = $event->payload;

        if (! $this->matcher->matches($profile, $payload)) {
            return [
                'matches' => false,
                'canonical' => [],
                'project_id' => null,
                'winning_rule' => null,
                'environment' => null,
                'would_be_status' => IngestStatus::Discarded->value,
            ];
        }

        $canonical = $this->normalizer->normalize($profile, $payload);
        $correlation = $this->ruleset->correlate($canonical, CorrelationScope::forProfile($event->connection, $profile));

        return [
            'matches' => true,
            'canonical' => $canonical,
            'project_id' => $correlation['project']?->getKey(),
            'winning_rule' => $correlation['rule'],
            'environment' => $correlation['environment'],
            'would_be_status' => $correlation['project'] === null
                ? IngestStatus::Uncorrelated->value
                : IngestStatus::Ingested->value,
        ];
    }
}
