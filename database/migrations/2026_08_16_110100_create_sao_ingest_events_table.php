<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Enums\SAOTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = SAOTables::IngestEvents->value;

        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('connection_id')->nullable()
                ->comment('Null for the internal (host-app) source')
                ->constrained(SAOTables::Connections->value, 'id', "{$table_name}_connection_FK")
                ->nullOnDelete();
            $table->string('pipeline')->default(IngestPipeline::Profile->value)
                ->comment('The ingest path that recorded the event: profile, logs, deploy or code');
            $table->string('delivery_id')->comment('The id the source assigned to this delivery');
            $table->json('payload');
            $table->foreignId('source_profile_id')->nullable()
                ->constrained(SAOTables::SourceProfiles->value, 'id', "{$table_name}_profile_FK")
                ->nullOnDelete();
            $table->enum('status', IngestStatus::values())->default(IngestStatus::Received->value);
            $table->string('outcome')->nullable()->comment('Explicit reason for the status (reliable silence)');
            $table->foreignId('project_id')->nullable()
                ->constrained(SAOTables::Projects->value, 'id', "{$table_name}_project_FK")
                ->nullOnDelete();
            $table->string('winning_rule')->nullable()->comment('Which correlation rule attached the event');
            $table->foreignId('signal_id')->nullable()
                ->constrained(SAOTables::Signals->value, 'id', "{$table_name}_signal_FK")
                ->nullOnDelete();

            $table->timestamp('reprocessed_at')->nullable()->comment('When the event was last replayed through its pipeline');

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            MigrateUtils::prefixIndex($table, 'source_profile_id');
            MigrateUtils::prefixIndex($table, 'project_id');
            MigrateUtils::prefixIndex($table, 'signal_id');

            // Idempotency: a re-delivered id for the same connection is recorded once.
            $table->unique(['connection_id', 'delivery_id'], "{$table_name}_delivery_UN");
            $table->index('status', "{$table_name}_status_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SAOTables::IngestEvents->value);
    }
};
