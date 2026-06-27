<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('allegro_category_parameters_cache')) {
            return;
        }

        if (! Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id')) {
            Schema::table('allegro_category_parameters_cache', function (Blueprint $table): void {
                $table->string('allegro_category_id')->nullable()->after('id');
            });
        }

        if (Schema::hasColumn('allegro_category_parameters_cache', 'category_id') && Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id')) {
            DB::table('allegro_category_parameters_cache')
                ->whereNull('allegro_category_id')
                ->whereNotNull('category_id')
                ->update(['allegro_category_id' => DB::raw('category_id')]);

            Schema::table('allegro_category_parameters_cache', function (Blueprint $table): void {
                $table->dropColumn('category_id');
            });
        }

        if (Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id')) {
            try {
                Schema::table('allegro_category_parameters_cache', function (Blueprint $table): void {
                    $table->unique('allegro_category_id', 'allegro_category_parameters_cache_allegro_category_id_unique');
                });
            } catch (\Throwable) {
                // Keep the migration safe on databases where the compatible unique index already exists.
            }
        }
    }

    public function down(): void
    {
        // Intentionally no-op: this migration removes a legacy duplicate column while keeping cache data.
    }
};
