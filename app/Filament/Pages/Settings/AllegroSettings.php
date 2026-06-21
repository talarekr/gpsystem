<?php

namespace App\Filament\Pages\Settings;

class AllegroSettings extends MarketplaceApiSettingsPage
{
    protected static bool $shouldRegisterNavigation = true;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Allegro';
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $title = 'Allegro → Ustawienia API';
    protected static ?int $navigationSort = 51;

    protected function accountDefinitions(): array
    {
        return [
            'allegro_main' => [
                'label' => 'Allegro', 'marketplace' => 'allegro', 'name' => 'Allegro main',
                'api_base_url' => 'https://api.allegro.pl.allegrosandbox.pl',
                'credential_fields' => ['client_id' => 'Client ID', 'client_secret' => 'Client secret', 'access_token' => 'Access token', 'refresh_token' => 'Refresh token', 'expires_at' => 'Expires at'],
                'required_credentials' => ['client_id', 'client_secret', 'access_token', 'refresh_token'],
            ],
        ];
    }
}
