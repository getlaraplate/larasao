<?php

declare(strict_types=1);

namespace Modules\SAO\ApplicationContent;

use Illuminate\Support\Str;
use Modules\Core\ApplicationContent\Data\ApplicationContentHit;
use Modules\SAO\Models\Ticket;

final class SaoTicketEvidenceProjector
{
    public function project(Ticket $ticket, string $requestedLocale, string $strategy, ?float $score): ?ApplicationContentHit
    {
        $label = $this->plainText((string) $ticket->title);

        if ($label === '') {
            return null;
        }

        $key = (string) $ticket->key;
        $description = $this->plainText((string) ($ticket->description ?? ''));
        $body = $description === '' ? $label : $description;

        [$excerpt, $excerptTruncated] = $this->truncate($body, $this->maximumChars('max_excerpt_chars', 2000, 1000));
        [$label, $labelTruncated] = $this->truncate($label, $this->maximumChars('max_label_chars', 200, 200));

        return new ApplicationContentHit(
            id: 'sao.tickets:' . $key,
            source: 'sao.tickets',
            module: 'sao',
            entity: 'tickets',
            recordKey: $key,
            excerpt: $excerpt,
            label: $label,
            canonicalReference: '/app/sao/tickets/' . $key,
            locale: $requestedLocale,
            strategy: $strategy,
            score: $score,
            revision: $ticket->updated_at->toIso8601String(),
            truncated: $excerptTruncated || $labelTruncated,
        );
    }

    /**
     * Resolve a character cap: the projector's own ceiling, never above the
     * DTO's configured maximum, so a lowered config can never make the hit
     * exceed its validation bound.
     */
    private function maximumChars(string $key, int $default, int $ceiling): int
    {
        return min($ceiling, max(1, (int) config('application-content.' . $key, $default)));
    }

    /**
     * @return array{string, bool}
     */
    private function truncate(string $value, int $maximum): array
    {
        if ($maximum >= mb_strlen($value)) {
            return [$value, false];
        }

        return [Str::limit($value, $maximum, ''), true];
    }

    /**
     * Reduce a free-text ticket field to plain text safe for evidence:
     * strip markup and control characters, collapse whitespace.
     */
    private function plainText(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return mb_trim($value);
    }
}
