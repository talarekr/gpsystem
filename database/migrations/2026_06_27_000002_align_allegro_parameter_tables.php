<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('allegro_category_parameters_cache') && ! Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id')) {
            Schema::table('allegro_category_parameters_cache', function (Blueprint $table): void {
                $table->string('allegro_category_id')->nullable()->unique()->after('id');
            });
        }
        if (Schema::hasTable('allegro_parameter_mappings')) {
            Schema::table('allegro_parameter_mappings', function (Blueprint $table): void {
                if (! Schema::hasColumn('allegro_parameter_mappings', 'enabled')) $table->boolean('enabled')->default(true)->after('fixed_value_label');
                if (! Schema::hasColumn('allegro_parameter_mappings', 'metadata')) $table->json('metadata')->nullable()->after('enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('allegro_parameter_mappings')) {
            Schema::table('allegro_parameter_mappings', function (Blueprint $table): void {
                if (Schema::hasColumn('allegro_parameter_mappings', 'metadata')) $table->dropColumn('metadata');
                if (Schema::hasColumn('allegro_parameter_mappings', 'enabled')) $table->dropColumn('enabled');
            });
        }
        if (Schema::hasTable('allegro_category_parameters_cache') && Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id')) {
            Schema::table('allegro_category_parameters_cache', function (Blueprint $table): void { $table->dropColumn('allegro_category_id'); });
        }
    }
};
