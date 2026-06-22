<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketplace_category_mappings') && ! Schema::hasColumn('marketplace_category_mappings', 'notes')) {
            Schema::table('marketplace_category_mappings', function (Blueprint $table): void {
                $table->text('notes')->nullable()->after('fulfillment_policy_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketplace_category_mappings') && Schema::hasColumn('marketplace_category_mappings', 'notes')) {
            Schema::table('marketplace_category_mappings', function (Blueprint $table): void {
                $table->dropColumn('notes');
            });
        }
    }
};
