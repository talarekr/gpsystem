<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketplace_listings', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('last_synced_at');
            }
            if (! Schema::hasColumn('marketplace_listings', 'last_api_status')) {
                $table->string('last_api_status')->nullable()->after('last_seen_at');
            }
            if (! Schema::hasColumn('marketplace_listings', 'not_seen_in_active_api_at')) {
                $table->timestamp('not_seen_in_active_api_at')->nullable()->after('last_api_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            if (Schema::hasColumn('marketplace_listings', 'not_seen_in_active_api_at')) {
                $table->dropColumn('not_seen_in_active_api_at');
            }
            if (Schema::hasColumn('marketplace_listings', 'last_api_status')) {
                $table->dropColumn('last_api_status');
            }
            if (Schema::hasColumn('marketplace_listings', 'last_seen_at')) {
                $table->dropColumn('last_seen_at');
            }
        });
    }
};
