<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ovoko_stock_sync_runs')) return;

        Schema::create('ovoko_stock_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('mode', 20)->index();
            $table->string('status', 20)->index();
            $table->unsignedInteger('batch_size')->default(20);
            $table->unsignedInteger('total_candidates')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedBigInteger('last_processed_part_id')->nullable()->index();
            $table->unsignedInteger('no_change_count')->default(0);
            $table->unsignedInteger('would_update_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('top_blockers')->nullable();
            $table->json('recent_results')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ovoko_stock_sync_runs');
    }
};
