<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('allegro_parameter_selections')) {
            return;
        }

        Schema::create('allegro_parameter_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->string('allegro_category_id')->index();
            $table->string('parameter_id')->index();
            $table->string('parameter_name')->nullable();
            $table->string('value_id');
            $table->string('value_label')->nullable();
            $table->timestamps();

            $table->index('part_id');
            $table->unique(['part_id', 'allegro_category_id', 'parameter_id', 'value_id'], 'allegro_param_selection_unique');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: these selections are user-entered publishing data.
    }
};
