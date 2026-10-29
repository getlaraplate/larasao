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
        $table_name = SAOTables::Environments->value;

        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('project_id')
                ->constrained(SAOTables::Projects->value, 'id', "{$table_name}_project_FK")
                ->cascadeOnDelete();
            $table->string('name')->comment('e.g. production, staging');
            $table->string('base_url', 2048)->nullable()->comment('Operator-set address probed for the running version; http or https');
            $table->string('current_version')->nullable()->comment('The version last seen running');
            $table->string('current_commit')->nullable()->comment('The commit last seen running');
            $table->string('current_branch')->nullable()->comment('The branch last seen running');
            $table->timestamp('last_seen_at')->nullable()->comment('When the version was last observed or probed');

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            $table->unique(['project_id', 'name'], "{$table_name}_project_name_UN");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SAOTables::Environments->value);
    }
};
