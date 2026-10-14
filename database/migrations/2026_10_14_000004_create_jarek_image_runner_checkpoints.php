<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_jarek_image_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('status');
            $table->string('phase');
            $table->json('checkpoint');
            $table->timestamps();
            $table->index(['actor_id', 'id']);
        });
        Schema::create('storefront_jarek_image_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->foreign('run_id')->references('run_id')->on('storefront_jarek_image_runs')->cascadeOnDelete();
            // Technical audit references only; no FK/update/cascade into supplier tables.
            $table->unsignedBigInteger('source_id');
            $table->unsignedTinyInteger('image_number')->default(0);
            $table->string('source_name', 300)->nullable();
            $table->string('local_url', 2048)->nullable();
            $table->string('fingerprint')->nullable();
            $table->string('classification');
            $table->string('status');
            $table->string('result_code')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'source_id', 'image_number']);
            $table->index(['run_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_jarek_image_items');
        Schema::dropIfExists('storefront_jarek_image_runs');
    }
};
