<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ovoko_stock_sync_run_items')) return;

        Schema::create('ovoko_stock_sync_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ovoko_stock_sync_run_id')->constrained('ovoko_stock_sync_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('part_id')->nullable()->index();
            $table->string('ovoko_id')->nullable()->index();
            $table->string('action', 60)->index();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['ovoko_stock_sync_run_id', 'part_id'], 'ovoko_stock_sync_run_items_run_part_unique');
            $table->index(['ovoko_stock_sync_run_id', 'action'], 'ovoko_stock_sync_run_items_run_action_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ovoko_stock_sync_run_items');
    }
};
