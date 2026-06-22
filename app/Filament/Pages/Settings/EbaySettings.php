<?php

namespace App\Filament\Pages\Settings;

use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;

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
        $credentials = ['client_id' => 'Client ID', 'client_secret' => 'Client secret', 'dev_id' => 'Dev ID', 'ru_name' => 'RuName / redirect URI', 'expires_at' => 'Expires at'];
        return [
            'ebay_de' => ['label' => 'eBay DE', 'marketplace' => 'ebay_de', 'name' => 'eBay DE', 'api_base_url' => 'https://api.ebay.com', 'marketplace_id' => 'EBAY_DE', 'site_id' => '77', 'credential_fields' => $credentials, 'required_credentials' => ['client_id', 'client_secret', 'refresh_token']],
            'ebay_fr' => ['label' => 'eBay FR', 'marketplace' => 'ebay_fr', 'name' => 'eBay FR', 'api_base_url' => 'https://api.ebay.com', 'marketplace_id' => 'EBAY_FR', 'site_id' => '71', 'credential_fields' => $credentials, 'required_credentials' => ['client_id', 'client_secret', 'refresh_token']],
        ];
    }

    protected function additionalAccountSchema(string $code, array $definition): array
    {
        if ($code !== 'ebay_de') return [];

        return [
            Actions::make([
                Action::make('connect_'.$code)
                    ->label('Połącz eBay DE')
                    ->url(route('admin.ebay.oauth.redirect', ['channel' => $code]))
                    ->openUrlInNewTab(false),
            ]),
        ];
    }
}
