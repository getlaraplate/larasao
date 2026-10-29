<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\MatchType;
use Modules\SAO\Enums\SAOTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = SAOTables::CorrelationRules->value;

        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->unsignedInteger('priority')->default(100)->comment('Lower wins');
            $table->boolean('is_active')->default(true);
            $table->enum('target', CorrelationTarget::values())->comment('The canonical field the pattern is compared with');
            $table->enum('match', MatchType::values())->default(MatchType::Exact->value);
            $table->string('pattern');
            $table->foreignId('connection_id')->nullable()
                ->comment('Scope: only events received on this connection')
                ->constrained(SAOTables::Connections->value, 'id', "{$table_name}_connection_FK")
                ->cascadeOnDelete();
            $table->foreignId('source_profile_id')->nullable()
                ->comment('Scope: only events normalized by this profile')
                ->constrained(SAOTables::SourceProfiles->value, 'id', "{$table_name}_profile_FK")
                ->cascadeOnDelete();
            $table->foreignId('project_id')
                ->constrained(SAOTables::Projects->value, 'id', "{$table_name}_project_FK")
                ->cascadeOnDelete();
            $table->string('environment')->nullable()
                ->comment('Environment name written on the occurrence; no Environment row is created from it');

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            MigrateUtils::prefixIndex($table, 'connection_id');
            MigrateUtils::prefixIndex($table, 'source_profile_id');
            MigrateUtils::prefixIndex($table, 'project_id');
            $table->index(['is_active', 'priority'], "{$table_name}_active_priority_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SAOTables::CorrelationRules->value);
    }
};
