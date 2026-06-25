<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_categories', function (Blueprint $table): void {
            if (! Schema::hasColumn('part_categories', 'is_visible')) {
                $table->boolean('is_visible')->default(true)->after('legacy_payload');
                $table->index(['is_visible', 'parent_id'], 'part_categories_visible_parent_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('part_categories', function (Blueprint $table): void {
            if (Schema::hasColumn('part_categories', 'is_visible')) {
                try {
                    $table->dropIndex('part_categories_visible_parent_idx');
                } catch (\Throwable) {
                    // The index is only created when this migration adds the column.
                }
                $table->dropColumn('is_visible');
            }
        });
    }
};
