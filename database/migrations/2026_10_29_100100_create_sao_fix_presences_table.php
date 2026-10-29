<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\SAO\Enums\SAOTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = SAOTables::FixPresences->value;

        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('change_ref_id')
                ->constrained(SAOTables::ChangeRefs->value, 'id', "{$table_name}_change_ref_FK")
                ->cascadeOnDelete();
            $table->foreignId('environment_id')
                ->constrained(SAOTables::Environments->value, 'id', "{$table_name}_environment_FK")
                ->cascadeOnDelete();
            $table->string('deployed_commit')->comment('The environment commit the answer is about');
            $table->boolean('contained')->nullable()
                ->comment('Whether the deployed commit contains the fix commit; null when the VCS could not tell, retried');
            $table->timestamp('checked_at')->comment('When the VCS was last asked');

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            MigrateUtils::prefixIndex($table, 'environment_id');

            $table->unique(['change_ref_id', 'environment_id', 'deployed_commit'], "{$table_name}_triple_UN");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SAOTables::FixPresences->value);
    }
};
