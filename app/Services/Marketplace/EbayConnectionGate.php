<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Schema;

class EbayConnectionGate
{
    public const SETTING_KEY = 'write_connection_enabled';

    public function account(): ?MarketplaceAccount
    {
        if (! Schema::hasTable('marketplace_accounts')) {
            return null;
        }

        return MarketplaceAccount::query()->where('code', 'ebay_de')->first();
    }

    public function writeEnabled(?MarketplaceAccount $account = null): bool
    {
        $account ??= $this->account();

        if (! $account?->api_enabled) {
            return false;
        }

        $settings = is_array($account->api_settings) ? $account->api_settings : [];

        return array_key_exists(self::SETTING_KEY, $settings)
            ? (bool) $settings[self::SETTING_KEY]
            : (bool) config('marketplace.external_api_writes_enabled', false);
    }

    /** @return array<string, mixed> */
    public function status(?MarketplaceAccount $account = null): array
    {
        $account ??= $this->account();
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $explicit = array_key_exists(self::SETTING_KEY, $settings);

        return [
            'write_enabled' => $this->writeEnabled($account),
            'account_configured' => $account !== null,
            'account_api_enabled' => (bool) $account?->api_enabled,
            'setting_source' => $explicit ? 'database' : 'GPS_EXTERNAL_API_WRITES_ENABLED',
            'explicit_setting' => $explicit ? (bool) $settings[self::SETTING_KEY] : null,
            'no_ebay_request_performed' => true,
        ];
    }
}
