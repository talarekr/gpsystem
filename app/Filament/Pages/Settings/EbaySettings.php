<?php

namespace App\Filament\Pages\Settings;

use App\Models\MarketplaceAccount;
use App\Services\Marketplace\Api\EbayApiClient;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;

class EbaySettings extends MarketplaceApiSettingsPage
{
    public const ENCRYPTED_NOTE_KEY = 'marketplace.ebay.encrypted_note';

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
            'ebay_de' => ['label' => 'eBay DE', 'marketplace' => 'ebay_de', 'name' => 'eBay DE', 'api_base_url' => 'https://api.ebay.com', 'marketplace_id' => 'EBAY_DE', 'site_id' => '77', 'credential_fields' => $credentials, 'required_credentials' => ['client_id', 'client_secret', 'refresh_token'], 'payment_policy_id' => '259264220013', 'return_policy_id' => '259264151013', 'api_settings_defaults' => $this->locationDefaults('ebay_de')],
            'ebay_fr' => ['label' => 'eBay FR', 'marketplace' => 'ebay_fr', 'name' => 'eBay FR', 'api_base_url' => 'https://api.ebay.com', 'marketplace_id' => 'EBAY_FR', 'site_id' => '71', 'credential_fields' => $credentials, 'required_credentials' => ['client_id', 'client_secret', 'refresh_token'], 'payment_policy_id' => '260547435013', 'return_policy_id' => '260547447013', 'api_settings_defaults' => $this->locationDefaults('ebay_fr')],
        ];
    }

    private function locationDefaults(string $code): array
    {
        return array_merge(
            (array) config('product-hub.ebay.default_location', []),
            (array) config("product-hub.ebay.accounts.{$code}", [])
        );
    }

    protected function policyInputs(string $code): array
    {
        return [
            TextInput::make("{$code}.payment_policy_id")->label('Payment policy ID')->maxLength(255)->helperText('Lokalna konfiguracja read-only; nie wykonuje operacji write do eBay.'),
            TextInput::make("{$code}.return_policy_id")->label('Return policy ID')->maxLength(255)->helperText('Lokalna konfiguracja read-only; nie wykonuje operacji write do eBay.'),
        ];
    }

    protected function credentialInputs(string $code, array $definition): array
    {
        $inputs = parent::credentialInputs($code, $definition);
        $inputs[] = TextInput::make("{$code}.ebay_encrypted_note")
            ->label('Hasło')
            ->type('text')
            ->autocomplete('off')
            ->rules(['nullable', 'string', 'max:255'])
            ->maxLength(255)
            ->helperText('Pole techniczne, szyfrowane w aplikacji. Nie jest używane do logowania do eBay.');

        return $inputs;
    }

    protected function additionalAccountState(MarketplaceAccount $account, array $definition): array
    {
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $encrypted = $settings[self::ENCRYPTED_NOTE_KEY] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return ['ebay_encrypted_note' => ''];
        }

        try {
            return ['ebay_encrypted_note' => Crypt::decryptString($encrypted)];
        } catch (DecryptException) {
            return ['ebay_encrypted_note' => ''];
        }
    }

    protected function saveAdditionalAccountState(MarketplaceAccount $account, array $state, array $definition): void
    {
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $value = trim((string) ($state['ebay_encrypted_note'] ?? ''));

        if ($value === '') {
            unset($settings[self::ENCRYPTED_NOTE_KEY]);
        } else {
            $settings[self::ENCRYPTED_NOTE_KEY] = Crypt::encryptString($value);
        }

        $account->api_settings = $settings;
    }

    protected function additionalAccountSchema(string $code, array $definition): array
    {
        return [
            Placeholder::make("{$code}.ebay_developer_callback_info")
                ->label('eBay Developer OAuth callback')
                ->content(fn () => new HtmlString('W eBay Developer ustaw <strong>Auth accepted URL</strong> i <strong>Auth declined URL</strong> na:<br><code>https://gpswiss.pl/admin/ebay/oauth/callback</code>')),
            Actions::make([
                Action::make('connect_'.$code)
                    ->label('Połącz '.$definition['label'])
                    ->url(route('admin.ebay.oauth.redirect', ['channel' => $code]))
                    ->openUrlInNewTab(false),
            ]),
            Placeholder::make("{$code}.business_policies")
                ->label('eBay Business Policies — read-only')
                ->content(fn () => new HtmlString($this->businessPoliciesHtml($code))),
        ];
    }
    private function businessPoliciesHtml(string $code): string
    {
        try {
            $account = MarketplaceAccount::query()->where('code', $code)->first();
            $payload = (new EbayApiClient($code, $account))->businessPoliciesDiagnostics();
        } catch (\Throwable) {
            return '<div><strong>eBay Business Policies</strong><div>Diagnostyka read-only nie powiodła się bez ujawniania danych dostępowych.</div></div>';
        }

        $html = '<div style="display:grid; gap: 0.75rem;">';
        $html .= '<div><strong>'.e($payload['marketplace_id'] ?? strtoupper($code)).'</strong> · API mode: <code>'.e($payload['api_mode'] ?? '').'</code> · read-only/dry-run</div>';

        foreach ([
            'fulfillment_policies' => 'Fulfillment policies',
            'payment_policies' => 'Payment policies',
            'return_policies' => 'Return policies',
        ] as $key => $label) {
            $html .= $this->policiesTableHtml($label, $payload[$key] ?? []);
        }

        if (($payload['blockers'] ?? []) !== []) {
            $html .= '<div><strong>Blockers:</strong><ul><li>'.implode('</li><li>', array_map('e', $payload['blockers'])).'</li></ul></div>';
        }

        if (($payload['warnings'] ?? []) !== []) {
            $html .= '<div><strong>Warnings:</strong><ul><li>'.implode('</li><li>', array_map('e', $payload['warnings'])).'</li></ul></div>';
        }

        return $html.'</div>';
    }

    private function policiesTableHtml(string $label, array $policies): string
    {
        $html = '<div><strong>'.e($label).'</strong>';
        if ($policies === []) return $html.'<div>Brak policy lub brak dostępu read-only.</div></div>';

        $html .= '<table style="width:100%; border-collapse: collapse; margin-top: .25rem;"><thead><tr><th style="text-align:left; border-bottom:1px solid #ddd;">ID</th><th style="text-align:left; border-bottom:1px solid #ddd;">Nazwa</th><th style="text-align:left; border-bottom:1px solid #ddd;">Typ kategorii / marketplace</th></tr></thead><tbody>';
        foreach ($policies as $policy) {
            $categoryTypes = $policy['categoryTypes'] ?? [];
            $categoryText = is_array($categoryTypes) ? implode(', ', array_map(fn ($row) => is_array($row) ? (string) ($row['name'] ?? json_encode($row)) : (string) $row, $categoryTypes)) : (string) $categoryTypes;
            $marketplace = (string) ($policy['marketplaceId'] ?? '');
            $html .= '<tr><td style="padding:.25rem 0;">'.e($policy['id'] ?? '').'</td><td>'.e($policy['name'] ?? '').'</td><td>'.e(trim($categoryText.($marketplace !== '' ? ' / '.$marketplace : ''))).'</td></tr>';
        }

        return $html.'</tbody></table></div>';
    }

}
