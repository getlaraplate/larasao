<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\CommentOrigin;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketComment;

/**
 * @extends Factory<TicketComment>
 */
final class TicketCommentFactory extends Factory
{
    /**
     * @var class-string<TicketComment>
     */
    protected $model = TicketComment::class;

    /**
     * @return array<model-property<TicketComment>, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'author_id' => null,
            'origin' => CommentOrigin::Human,
            'source_key' => null,
            'body' => $this->faker->paragraph(),
        ];
    }

    public function system(string $source_key = 'ingest'): self
    {
        return $this->state(fn (): array => [
            'origin' => CommentOrigin::System,
            'source_key' => $source_key,
            'author_id' => null,
        ]);
    }
}
