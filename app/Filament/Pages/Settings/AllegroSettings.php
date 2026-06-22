<?php

namespace App\Filament\Pages\Settings;

use App\Support\Marketplace\AllegroOAuthConfig;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Illuminate\Support\HtmlString;

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
                'api_base_url' => 'https://api.allegro.pl',
                'credential_fields' => ['client_id' => 'Client ID', 'client_secret' => 'Client secret'],
                'required_credentials' => ['client_id', 'client_secret', 'access_token', 'refresh_token'],
            ],
        ];
    }

    protected function additionalAccountSchema(string $code, array $definition): array
    {
        if ($code !== AllegroOAuthConfig::ACCOUNT_CODE) return [];

        return [
            Section::make('Połączenie OAuth Allegro')
                ->description('Tylko connect OAuth i zapis tokenów. Nie uruchamia syncu, zamówień, ofert, cen ani stanów.')
                ->schema([
                    Grid::make(2)->schema([
                        Placeholder::make('allegro_oauth_status')->label('Status połączenia')->content(fn () => new HtmlString($this->oauthStatusHtml())),
                        Placeholder::make('allegro_redirect_uri')->label('Redirect URI do panelu Allegro')->content(new HtmlString('<code>'.e(AllegroOAuthConfig::REDIRECT_URI).'</code>')),
                        Placeholder::make('allegro_access_token_status')->label('Access token')->content(fn () => new HtmlString($this->credentialStatusHtml('access_token'))),
                        Placeholder::make('allegro_refresh_token_status')->label('Refresh token')->content(fn () => new HtmlString($this->credentialStatusHtml('refresh_token'))),
                    ]),
                    Actions::make([
                        Action::make('connect_allegro')
                            ->label('Połącz z Allegro')
                            ->url(route('admin.allegro.oauth.redirect'))
                            ->openUrlInNewTab(false),
                    ]),
                ]),
        ];
    }

    private function oauthStatusHtml(): string
    {
        $readiness = AllegroOAuthConfig::readiness($this->getAccount(AllegroOAuthConfig::ACCOUNT_CODE, $this->accountDefinitions()[AllegroOAuthConfig::ACCOUNT_CODE]));

        return $readiness['credentials_configured']
            ? '<strong>Połączono</strong> — tokeny są skonfigurowane i zaszyfrowane.'
            : '<strong>Niepołączono</strong> — użyj przycisku „Połącz z Allegro”.';
    }

    private function credentialStatusHtml(string $key): string
    {
        $account = $this->getAccount(AllegroOAuthConfig::ACCOUNT_CODE, $this->accountDefinitions()[AllegroOAuthConfig::ACCOUNT_CODE]);
        $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];

        return filled($credentials[$key] ?? null) ? '<strong>skonfigurowany</strong>' : '<strong>brak</strong>';
    }
}
