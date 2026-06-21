<?php

namespace App\Filament\Pages\Settings;

use App\Models\MarketplaceAccount;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

class OvokoSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.settings.ovoko-settings';
    protected static bool $shouldRegisterNavigation = true;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Ovoko';
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $title = 'Ovoko → Ustawienia API';
    protected static ?int $navigationSort = 50;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $account = $this->getAccount();
        $credentials = $account->api_credentials ?? [];

        $this->form->fill([
            'api_enabled' => (bool) $account->api_enabled,
            'api_base_url' => $account->api_base_url ?: 'https://api.rrr.lt',
            'api_mode' => $account->api_mode ?: 'dry_run',
            'username' => '',
            'password' => '',
            'user_token' => '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Ustawienia API')
                    ->description('Bezpieczna konfiguracja Ovoko/RRR. Ten ekran nie wykonuje połączeń API, testów połączenia ani synchronizacji.')
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('api_enabled')
                                ->label('API enabled')
                                ->helperText('Włącza tylko flagę konfiguracji w GPS. Nie uruchamia komunikacji z Ovoko.'),
                            Select::make('api_mode')
                                ->label('API mode')
                                ->options(['dry_run' => 'dry_run', 'live' => 'live'])
                                ->default('dry_run')
                                ->required(),
                        ]),
                        TextInput::make('api_base_url')
                            ->label('API base URL')
                            ->default('https://api.rrr.lt')
                            ->required()
                            ->url()
                            ->maxLength(255),
                        Grid::make(3)->schema([
                            TextInput::make('username')
                                ->label('Username')
                                ->password()
                                ->revealable(false)
                                ->autocomplete('off')
                                ->maxLength(255)
                                ->helperText('Zostaw puste, aby zachować obecną wartość. Po zapisaniu nie pokazujemy wartości jawnie.'),
                            TextInput::make('password')
                                ->label('Password')
                                ->password()
                                ->revealable(false)
                                ->autocomplete('new-password')
                                ->maxLength(255)
                                ->helperText('Zostaw puste, aby zachować obecne hasło.'),
                            TextInput::make('user_token')
                                ->label('User token')
                                ->password()
                                ->revealable(false)
                                ->autocomplete('off')
                                ->maxLength(255)
                                ->helperText('Zostaw puste, aby zachować obecny token.'),
                        ]),
                        Placeholder::make('credentials_status')
                            ->label('Credentials configured')
                            ->content(fn (): HtmlString => new HtmlString($this->credentialsConfigured() ? '<strong>yes</strong>' : '<strong>no</strong>')),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $account = $this->getAccount();
        $credentials = $account->api_credentials ?? [];

        foreach (['username', 'password', 'user_token'] as $key) {
            $value = trim((string) ($state[$key] ?? ''));
            if ($value !== '') {
                $credentials[$key] = $value;
            }
        }

        $account->fill([
            'marketplace' => 'ovoko',
            'code' => 'ovoko_main',
            'name' => 'Ovoko main',
            'status' => 'active',
            'api_enabled' => (bool) ($state['api_enabled'] ?? false),
            'api_base_url' => rtrim((string) ($state['api_base_url'] ?: 'https://api.rrr.lt'), '/'),
            'api_mode' => (string) ($state['api_mode'] ?: 'dry_run'),
            'api_credentials' => $credentials,
        ])->save();

        $this->form->fill([
            'api_enabled' => (bool) $account->api_enabled,
            'api_base_url' => $account->api_base_url,
            'api_mode' => $account->api_mode,
            'username' => '',
            'password' => '',
            'user_token' => '',
        ]);

        Notification::make()->title('Zapisano ustawienia API Ovoko. Nie wykonano połączenia API.')->success()->send();
    }

    public function getFormActions(): array
    {
        return [Action::make('save')->label('Zapisz')->submit('save')];
    }

    private function getAccount(): MarketplaceAccount
    {
        return MarketplaceAccount::query()->firstOrCreate(
            ['code' => 'ovoko_main'],
            ['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'status' => 'active', 'api_base_url' => 'https://api.rrr.lt', 'api_mode' => 'dry_run']
        );
    }

    private function credentialsConfigured(): bool
    {
        $credentials = $this->getAccount()->api_credentials ?? [];

        return filled($credentials['username'] ?? null)
            && filled($credentials['password'] ?? null)
            && filled($credentials['user_token'] ?? null);
    }
}
