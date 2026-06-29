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
            ->whereIn('code', ['ebay_de', 'ebay_fr'])
            ->orderBy('id')
            ->each(function (object $account): void {
                $settings = json_decode((string) ($account->api_settings ?? '[]'), true);
                $settings = is_array($settings) ? $settings : [];

                foreach ($this->defaults() as $key => $value) {
                    if (! array_key_exists($key, $settings) || blank($settings[$key])) {
                        $settings[$key] = $value;
                    }
                }

                DB::table('marketplace_accounts')
                    ->where('id', $account->id)
                    ->update(['api_settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            });
    }

    public function down(): void
    {
        // Intentionally no-op: do not remove or alter merchant location settings that may now be in use.
    }

    private function defaults(): array
    {
        return [
            'merchant_location_key' => 'gpswiss-pl',
            'inventory_location_key' => 'gpswiss-pl',
            'inventory_location_name' => 'gpswiss-pl',
        ];
    }
};
