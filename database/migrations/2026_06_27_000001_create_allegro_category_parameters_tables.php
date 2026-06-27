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
                $table->string('category_id')->unique();
                $table->json('raw_response');
                $table->timestamp('fetched_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('allegro_parameter_mappings')) {
            Schema::create('allegro_parameter_mappings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('local_category_id');
                $table->string('allegro_category_id');
                $table->string('parameter_id');
                $table->string('parameter_name')->nullable();
                $table->string('source_field')->nullable();
                $table->string('fixed_value_id')->nullable();
                $table->string('fixed_value_label')->nullable();
                $table->boolean('is_required_override')->nullable();
                $table->timestamps();
                $table->unique(['local_category_id', 'allegro_category_id', 'parameter_id'], 'allegro_parameter_mappings_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_parameter_mappings');
        Schema::dropIfExists('allegro_category_parameters_cache');
    }
};
