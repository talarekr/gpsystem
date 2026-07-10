<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ovoko_car_model_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->index();
            $table->unsignedTinyInteger('batch_size')->default(5);
            $table->unsignedSmallInteger('delay_seconds')->default(10);
            $table->boolean('only_missing')->default(true);
            $table->unsignedInteger('total_brand_count')->default(0);
            $table->unsignedInteger('processed_brand_count')->default(0);
            $table->unsignedInteger('synced_models_count')->default(0);
            $table->unsignedInteger('failed_brand_count')->default(0);
            $table->unsignedInteger('last_offset')->default(0);
            $table->json('processed_brand_ids')->nullable();
            $table->json('failed_brands')->nullable();
            $table->json('last_batch')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ovoko_car_model_sync_runs');
    }
};
