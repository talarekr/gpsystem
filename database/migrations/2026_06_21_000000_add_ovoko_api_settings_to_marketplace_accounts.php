<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table): void {
            $table->boolean('api_enabled')->default(false)->after('status');
            $table->string('api_base_url')->nullable()->after('api_enabled');
            $table->string('api_mode')->default('dry_run')->after('api_base_url');
            $table->longText('api_credentials')->nullable()->after('api_mode');
            $table->json('api_settings')->nullable()->after('api_credentials');
            $table->timestamp('last_connection_check_at')->nullable()->after('api_settings');
            $table->string('last_connection_status')->nullable()->after('last_connection_check_at');
            $table->text('last_connection_message')->nullable()->after('last_connection_status');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'api_enabled',
                'api_base_url',
                'api_mode',
                'api_credentials',
                'api_settings',
                'last_connection_check_at',
                'last_connection_status',
                'last_connection_message',
            ]);
        });
    }
};
