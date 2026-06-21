<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            if (! Schema::hasColumn('parts', 'needs_listing')) {
                $table->boolean('needs_listing')->default(false)->index()->after('is_visible_storefront');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            if (Schema::hasColumn('parts', 'needs_listing')) {
                $table->dropColumn('needs_listing');
            }
        });
    }
};
