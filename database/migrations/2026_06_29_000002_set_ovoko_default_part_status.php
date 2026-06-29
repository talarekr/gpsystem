<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_accounts')) return;

        DB::table('marketplace_accounts')
            ->where('code', 'ovoko_main')
            ->orderBy('id')
            ->each(function (object $account): void {
                $settings = json_decode((string) ($account->api_settings ?? '[]'), true);
                $settings = is_array($settings) ? $settings : [];

                $settings['default_part_status'] = 0;
                $settings['ovoko_default_part_status'] = 0;

                DB::table('marketplace_accounts')
                    ->where('id', $account->id)
                    ->update(['api_settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            });
    }

    public function down(): void
    {
        // Intentionally no-op: do not remove or alter Ovoko account settings that may now be in use.
    }
};
