<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('allegro_category_parameters_cache')) {
            Schema::create('allegro_category_parameters_cache', function (Blueprint $table): void {
                $table->id();
                $table->string('allegro_category_id')->unique();
                $table->json('raw_response');
                $table->timestamp('fetched_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('allegro_parameter_mappings')) {
            Schema::create('allegro_parameter_mappings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('local_category_id')->nullable()->index();
                $table->string('allegro_category_id')->index();
                $table->string('parameter_id')->index();
                $table->string('parameter_name')->nullable();
                $table->string('source_field')->nullable();
                $table->string('fixed_value_id')->nullable();
                $table->string('fixed_value_label')->nullable();
                $table->boolean('enabled')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['local_category_id', 'allegro_category_id', 'parameter_id'], 'alg_param_mapping_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_parameter_mappings');
        Schema::dropIfExists('allegro_category_parameters_cache');
    }
};
