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

abstract class MarketplaceApiSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.settings.marketplace-api-settings';
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationIcon = null;

    public array $data = [];

    abstract protected function accountDefinitions(): array;

    public function mount(): void
    {
        $state = [];
        foreach ($this->accountDefinitions() as $code => $definition) {
            $account = $this->getAccount($code, $definition);
            $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
            $settings = is_array($account->api_settings) ? $account->api_settings : [];
            $state[$code] = [
                'api_enabled' => (bool) $account->api_enabled,
                'api_mode' => $account->api_mode ?: 'dry_run',
                'api_base_url' => $account->api_base_url ?: ($definition['api_base_url'] ?? ''),
                'seller_account_id' => $settings['seller_account_id'] ?? '',
                'marketplace_id' => $settings['marketplace_id'] ?? ($definition['marketplace_id'] ?? ''),
                'site_id' => $settings['site_id'] ?? ($definition['site_id'] ?? ''),
                'payment_policy_id' => $settings['payment_policy_id'] ?? ($definition['payment_policy_id'] ?? ''),
                'return_policy_id' => $settings['return_policy_id'] ?? ($definition['return_policy_id'] ?? ''),
            ];
            foreach ($definition['credential_fields'] as $field => $label) {
                $state[$code][$field] = '';
            }
        }
        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        $sections = [];
        foreach ($this->accountDefinitions() as $code => $definition) {
            $credentialInputs = [];
            foreach ($definition['credential_fields'] as $field => $label) {
                $credentialInputs[] = TextInput::make("{$code}.{$field}")->label($label)->password()->revealable(false)->autocomplete('off')->maxLength(1024)->helperText('Zostaw puste, aby zachować obecną zaszyfrowaną wartość.');
            }

            $sections[] = Section::make($definition['label'].' — Ustawienia API')
                ->description('Konfiguracja zapisywana bez uruchamiania synchronizacji, publikacji ani aktualizacji marketplace.')
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make("{$code}.api_enabled")->label('API enabled')->helperText('Sama flaga konfiguracji; nie uruchamia live sync.'),
                        Select::make("{$code}.api_mode")->label('API mode')->options(['dry_run' => 'dry_run', 'live' => 'live'])->default('dry_run')->required(),
                    ]),
                    TextInput::make("{$code}.api_base_url")->label('API base URL')->url()->maxLength(255),
                    Grid::make(2)->schema([
                        TextInput::make("{$code}.seller_account_id")->label('Seller/account id')->maxLength(255),
                        TextInput::make("{$code}.marketplace_id")->label('Marketplace id / site')->maxLength(255),
                        TextInput::make("{$code}.site_id")->label('Site id')->maxLength(255),
                    ]),
                    Grid::make(2)->schema($this->policyInputs($code)),
                    Grid::make(2)->schema($credentialInputs),
                    Placeholder::make("{$code}.credentials_status")->label('Credentials configured')->content(fn () => new HtmlString($this->credentialsConfigured($code, $definition) ? '<strong>yes</strong>' : '<strong>no</strong>')),
                    ...$this->additionalAccountSchema($code, $definition),
                ]);
        }

        return $form->schema($sections)->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        foreach ($this->accountDefinitions() as $code => $definition) {
            $account = $this->getAccount($code, $definition);
            $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
            $settings = is_array($account->api_settings) ? $account->api_settings : [];
            foreach (array_keys($definition['credential_fields']) as $field) {
                $value = trim((string) ($state[$code][$field] ?? ''));
                if ($value !== '') $credentials[$field] = $value;
            }
            $account->fill([
                'marketplace' => $definition['marketplace'], 'code' => $code, 'name' => $definition['name'], 'status' => 'active',
                'api_enabled' => (bool) ($state[$code]['api_enabled'] ?? false),
                'api_base_url' => rtrim((string) ($state[$code]['api_base_url'] ?: ($definition['api_base_url'] ?? '')), '/'),
                'api_mode' => (string) ($state[$code]['api_mode'] ?: 'dry_run'),
                'api_credentials' => $credentials,
                'api_settings' => array_filter(array_merge($settings, [
                    'seller_account_id' => trim((string) ($state[$code]['seller_account_id'] ?? '')),
                    'marketplace_id' => trim((string) ($state[$code]['marketplace_id'] ?? '')),
                    'site_id' => trim((string) ($state[$code]['site_id'] ?? '')),
                    'payment_policy_id' => trim((string) ($state[$code]['payment_policy_id'] ?? '')),
                    'return_policy_id' => trim((string) ($state[$code]['return_policy_id'] ?? '')),
                ]), fn ($value) => $value !== ''),
            ])->save();
        }
        $this->mount();
        Notification::make()->title('Zapisano ustawienia API. Nie wykonano połączenia ani synchronizacji.')->success()->send();
    }

    public function getFormActions(): array { return [Action::make('save')->label('Zapisz')->submit('save')]; }

    protected function policyInputs(string $code): array
    {
        return [];
    }

    protected function additionalAccountSchema(string $code, array $definition): array
    {
        return [];
    }

    protected function getAccount(string $code, array $definition): MarketplaceAccount
    {
        return MarketplaceAccount::query()->firstOrCreate(['code' => $code], ['marketplace' => $definition['marketplace'], 'name' => $definition['name'], 'status' => 'active', 'api_base_url' => $definition['api_base_url'] ?? null, 'api_mode' => 'dry_run']);
    }

    protected function credentialsConfigured(string $code, array $definition): bool
    {
        $credentials = $this->getAccount($code, $definition)->api_credentials ?? [];
        foreach ($definition['required_credentials'] as $field) if (blank($credentials[$field] ?? null)) return false;
        return true;
    }
}
