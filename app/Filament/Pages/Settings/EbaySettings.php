<?php

namespace App\Filament\Pages\Settings;

class EbaySettings extends MarketplaceApiSettingsPage
{
    protected static bool $shouldRegisterNavigation = true;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'eBay';
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $title = 'eBay → Ustawienia API';
    protected static ?int $navigationSort = 52;

    protected function accountDefinitions(): array
    {
        $credentials = ['client_id' => 'Client ID', 'client_secret' => 'Client secret', 'dev_id' => 'Dev ID', 'ru_name' => 'RuName / redirect URI', 'access_token' => 'Access token', 'refresh_token' => 'Refresh token', 'expires_at' => 'Expires at'];
        return [
            'ebay_de' => ['label' => 'eBay DE', 'marketplace' => 'ebay_de', 'name' => 'eBay DE', 'api_base_url' => 'https://api.ebay.com', 'marketplace_id' => 'EBAY_DE', 'site_id' => '77', 'credential_fields' => $credentials, 'required_credentials' => ['client_id', 'client_secret', 'access_token', 'refresh_token']],
            'ebay_fr' => ['label' => 'eBay FR', 'marketplace' => 'ebay_fr', 'name' => 'eBay FR', 'api_base_url' => 'https://api.ebay.com', 'marketplace_id' => 'EBAY_FR', 'site_id' => '71', 'credential_fields' => $credentials, 'required_credentials' => ['client_id', 'client_secret', 'access_token', 'refresh_token']],
        ];
    }
}
