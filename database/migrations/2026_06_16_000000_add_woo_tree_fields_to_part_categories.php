<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_categories', function (Blueprint $table): void {
            if (! Schema::hasColumn('part_categories', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()->after('id')->constrained('part_categories')->nullOnDelete();
            }
            if (! Schema::hasColumn('part_categories', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('slug');
            }
            if (! Schema::hasColumn('part_categories', 'full_slug_path')) {
                $table->text('full_slug_path')->nullable()->after('category_path');
            }
            if (! Schema::hasColumn('part_categories', 'woo_product_count')) {
                $table->integer('woo_product_count')->nullable()->after('full_slug_path');
            }
            if (! Schema::hasColumn('part_categories', 'description')) {
                $table->text('description')->nullable()->after('woo_product_count');
            }
            if (! Schema::hasColumn('part_categories', 'thumbnail_url')) {
                $table->text('thumbnail_url')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('part_categories', function (Blueprint $table): void {
            foreach (['thumbnail_url', 'description', 'woo_product_count', 'full_slug_path', 'sort_order'] as $column) {
                if (Schema::hasColumn('part_categories', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('part_categories', 'parent_id')) {
                $table->dropConstrainedForeignId('parent_id');
            }
        });
    }
};
