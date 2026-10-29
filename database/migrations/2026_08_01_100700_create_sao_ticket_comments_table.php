<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;
use Modules\SAO\Enums\CommentOrigin;
use Modules\SAO\Enums\SAOTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = SAOTables::TicketComments->value;

        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained(SAOTables::Tickets->value, 'id', "{$table_name}_ticket_FK")
                ->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()
                ->comment('Null for system comments')
                ->constrained(CoreTables::Users->value, 'id', "{$table_name}_author_FK")
                ->nullOnDelete();
            $table->enum('origin', CommentOrigin::values())->default(CommentOrigin::Human->value);
            $table->string('source_key')->nullable()->comment('Which automation wrote it, for system comments');
            $table->text('body');

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            MigrateUtils::prefixIndex($table, 'author_id');

            $table->index(['ticket_id', 'source_key', 'created_at'], "{$table_name}_ticket_source_created_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SAOTables::TicketComments->value);
    }
};
