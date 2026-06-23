<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            if (! Schema::hasColumn('parts', 'needs_review')) {
                $table->boolean('needs_review')->default(false)->index()->after('needs_listing');
            }
            if (! Schema::hasColumn('parts', 'review_reason')) {
                $table->string('review_reason')->nullable()->after('needs_review');
            }
            if (! Schema::hasColumn('parts', 'review_detected_at')) {
                $table->timestamp('review_detected_at')->nullable()->index()->after('review_reason');
            }
            if (! Schema::hasColumn('parts', 'review_source')) {
                $table->string('review_source')->nullable()->index()->after('review_detected_at');
            }
            if (! Schema::hasColumn('parts', 'review_metadata')) {
                $table->json('review_metadata')->nullable()->after('review_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            foreach (['review_metadata', 'review_source', 'review_detected_at', 'review_reason', 'needs_review'] as $column) {
                if (Schema::hasColumn('parts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
