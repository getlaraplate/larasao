<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\Internal;

use Modules\SAO\Data\ChangeContext;
use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\Contracts\IssuesCapability;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConfigurationField;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Drivers\Support\DriverConfigurationSchema;
use Modules\SAO\Drivers\Support\HealthCheckResult;
use Modules\SAO\Drivers\Support\NormalizedIssue;
use Modules\SAO\Drivers\Support\Page;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketComment;
use Modules\SAO\Models\TicketType;
use Modules\SAO\Services\TicketCreationService;
use Modules\SAO\Services\TicketQueryService;
use Override;

/**
 * The `issues` capability served by SAO's own tickets — the network-free
 * reference implementation every external issues driver is measured against.
 *
 * Reads go through {@see TicketQueryService} (ACL-scoped) and writes through the
 * ticket domain services, so the driver never bypasses authorization or the
 * workflow. The target project and ticket type come from the binding config
 * (`project`, `ticket_type`); `page_size` is configurable so pagination is
 * exercised. Statuses are already canonical here, so `translateStatus` is a
 * plain lookup over the binding map.
 */
final readonly class InternalIssuesDriver implements DriverInterface, IssuesCapability
{
    private const int DEFAULT_PAGE_SIZE = 50;

    private const string SOURCE_KEY = 'internal';

    public function __construct(
        private TicketQueryService $query,
        private TicketCreationService $creation,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'internal';
    }

    /**
     * @return list<Capability>
     */
    #[Override]
    public function capabilities(): array
    {
        return [Capability::Issues];
    }

    /**
     * @return list<IngestMode>
     */
    #[Override]
    public function ingestModes(): array
    {
        return [IngestMode::InProcess];
    }

    #[Override]
    public function configurationSchema(): DriverConfigurationSchema
    {
        return new DriverConfigurationSchema([
            new ConfigurationField('project', 'integer', 'Project', required: true, secret: false),
            new ConfigurationField('ticket_type', 'integer', 'Ticket type', required: true, secret: false),
            new ConfigurationField('page_size', 'integer', 'Page size', required: false, secret: false),
        ]);
    }

    #[Override]
    public function healthCheck(ConnectionContext $context): HealthCheckResult
    {
        return HealthCheckResult::healthy();
    }

    /**
     * @return array<string, ?string>|null
     */
    #[Override]
    public function lookup(BindingContext $context, string $remoteId): ?array
    {
        $ticket = $this->query->visible()->with('status')->where('key', $remoteId)->first();

        return $ticket instanceof Ticket ? $this->normalize($ticket)->toArray() : null;
    }

    #[Override]
    public function list(BindingContext $context, ?string $cursor = null): Page
    {
        $pageSize = $this->pageSize($context);
        $offset = $cursor === null ? 0 : (int) $cursor;

        $query = $this->query->visible()->with('status')->orderBy('id');

        $projectId = $context->config['project'] ?? null;

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $rows = $query->skip($offset)->take($pageSize + 1)->get();
        $hasMore = $pageSize < $rows->count();

        $items = $rows->take($pageSize)
            ->map(fn (Ticket $ticket): array => $this->normalize($ticket)->toArray())
            ->values()
            ->all();

        return new Page($items, $hasMore ? (string) ($offset + $pageSize) : null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, ?string>
     */
    #[Override]
    public function create(BindingContext $context, array $attributes): array
    {
        $project = Project::query()->whereKey($context->config['project'])->firstOrFail();
        $type = TicketType::query()->whereKey($context->config['ticket_type'])->firstOrFail();

        $ticket = $this->creation->open($project, $type, [
            'title' => $attributes['title'] ?? '',
            'description' => $attributes['body'] ?? ($attributes['description'] ?? null),
        ], ChangeContext::forAutomation(self::SOURCE_KEY));

        $ticket->load('status');

        return $this->normalize($ticket)->toArray();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, ?string>
     */
    #[Override]
    public function update(BindingContext $context, string $remoteId, array $attributes): array
    {
        $ticket = $this->query->visible()->with('status')->where('key', $remoteId)->firstOrFail();

        $changes = array_filter([
            'title' => $attributes['title'] ?? null,
            'description' => $attributes['body'] ?? ($attributes['description'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);

        if ($changes !== []) {
            $ticket->fill($changes)->save();
        }

        $ticket->load('status');

        return $this->normalize($ticket)->toArray();
    }

    #[Override]
    public function comment(BindingContext $context, string $remoteId, string $body): void
    {
        $ticket = $this->query->visible()->where('key', $remoteId)->firstOrFail();

        TicketComment::postFor($ticket, $body, ChangeContext::forAutomation(self::SOURCE_KEY));
    }

    /**
     * @param  array<string, string>  $statusMap
     */
    #[Override]
    public function translateStatus(array $statusMap, string $remoteStatus): ?string
    {
        return $statusMap[$remoteStatus] ?? null;
    }

    private function pageSize(BindingContext $context): int
    {
        $configured = $context->config['page_size'] ?? null;

        return is_numeric($configured) ? max(1, (int) $configured) : self::DEFAULT_PAGE_SIZE;
    }

    private function normalize(Ticket $ticket): NormalizedIssue
    {
        return new NormalizedIssue(
            remoteId: (string) $ticket->key,
            title: (string) $ticket->title,
            key: $ticket->key,
            body: $ticket->description,
            remoteStatus: $ticket->status?->category?->value,
            remotePriority: $ticket->priority->value,
            assignee: $ticket->assignee_id !== null ? (string) $ticket->assignee_id : null,
            url: null,
            createdAt: $ticket->created_at->toIso8601String(),
            updatedAt: $ticket->updated_at->toIso8601String(),
        );
    }
}
