<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Services\Marketplace\Api\AllegroApiClient;

class AllegroSalesSettingsResolver
{
    public const SHIPPING_RATE_OPTIONS = [
        'BLACHARKA' => 'BLACHARKA',
        'KURIER DPD' => 'KURIER DPD',
        'KURIER DPD NIESTANDARDOWY' => 'KURIER DPD NIESTANDARDOWY',
        'LISTY' => 'LISTY',
        'ODBIÓR OSOBISTY' => 'ODBIÓR OSOBISTY',
        'Paczkomat Inpost a b c' => 'Paczkomat Inpost a b c',
        'SILNIK SKRZYNIA DRZWI' => 'SILNIK SKRZYNIA DRZWI',
    ];

    public const RETURN_POLICY_NAME = 'ZWROTGOLD';
    public const IMPLIED_WARRANTY_NAME = 'GWARANCJA GOLD';
    public const WARRANTY_NAME = 'GWARANTGOLD';

    public const MANUAL_SHIPPING_RATE_IDS = [
        'BLACHARKA' => '83eb8cd1-5d22-42bc-b007-0959e4c06a9e',
        'KURIER DPD' => '4d213acd-564e-420c-baa6-9d49fd194984',
        'KURIER DPD NIESTANDARDOWY' => '82c9b952-37e0-4378-8911-cd8a5e7d7816',
        'LISTY' => '916e0e26-9888-46e8-9235-93bce61a1bc4',
        'ODBIÓR OSOBISTY' => 'e953136b-c682-4525-86dd-1dc13e1cb5ea',
        'Paczkomat Inpost a b c' => 'bef666d8-0702-40d3-8d98-83f295439b5c',
        'SILNIK SKRZYNIA DRZWI' => 'f077dd82-29f5-4c22-9f61-879ee059e44f',
    ];

    public const MANUAL_AFTER_SALES_SERVICE_IDS = [
        'returnPolicy' => '91968c35-8bc3-4d74-baba-3609e4013f63',
        'impliedWarranty' => '1d19a257-7203-4227-88a8-f79f28531eea',
        'warranty' => '6174a76b-b25c-4994-909c-fb7a161deea8',
    ];

    public function resolve(?MarketplaceAccount $account, ?string $shippingRateName): array
    {
        $shippingRateName = trim((string) $shippingRateName);
        $result = [
            'selected_allegro_shipping_rate_name' => $shippingRateName !== '' ? $shippingRateName : null,
            'shippingRates' => $this->emptyRow($shippingRateName, $shippingRateName === '' ? 'missing' : 'read_failed'),
            'returnPolicy' => $this->emptyRow(self::RETURN_POLICY_NAME, 'read_failed'),
            'impliedWarranty' => $this->emptyRow(self::IMPLIED_WARRANTY_NAME, 'read_failed'),
            'warranty' => $this->emptyRow(self::WARRANTY_NAME, 'read_failed'),
            'blockers' => [],
        ];

        if ($shippingRateName === '') {
            $result['blockers'][] = 'missing_allegro_shipping_rate';
        } elseif (! array_key_exists($shippingRateName, self::SHIPPING_RATE_OPTIONS)) {
            $result['shippingRates']['status'] = 'missing';
            $result['blockers'][] = 'allegro_shipping_rate_not_active:'.$shippingRateName;
        }

        if (! $account || ! ($account->api_enabled ?? false) || ! in_array($account->status, ['enabled', 'active'], true)) {
            foreach (['returnPolicy', 'impliedWarranty', 'warranty'] as $key) {
                $result[$key]['status'] = 'read_failed';
            }
            $result['blockers'][] = 'allegro_sales_settings_account_unavailable';
            return $result;
        }

        $client = new AllegroApiClient('allegro_main', $account);
        $resolved = $client->resolveSalesSettingsByNames([
            'shippingRates' => $shippingRateName,
            'returnPolicy' => self::RETURN_POLICY_NAME,
            'impliedWarranty' => self::IMPLIED_WARRANTY_NAME,
            'warranty' => self::WARRANTY_NAME,
        ]);

        foreach (['shippingRates', 'returnPolicy', 'impliedWarranty', 'warranty'] as $key) {
            if ($key === 'shippingRates' && $shippingRateName === '') continue;
            $row = $resolved[$key] ?? [];
            $readStatus = ($row['reason'] ?? null) === 'read_failed' ? 'read_failed' : (($row['found'] ?? false) ? 'read_ok' : 'read_ok');
            $id = $row['id'] ?? null;
            $status = ($row['found'] ?? false) ? 'mapped' : (($row['reason'] ?? null) === 'read_failed' ? 'read_failed' : 'missing');

            if (blank($id)) {
                $manualId = $this->manualId($key, $row['searched_name'] ?? $result[$key]['searched_name'] ?? null);
                if (filled($manualId)) {
                    $id = $manualId;
                    $status = 'manual_id';
                }
            }

            $result[$key] = array_merge($result[$key], [
                'searched_name' => $row['searched_name'] ?? $result[$key]['searched_name'],
                'resolved_id' => $id,
                'id' => $id,
                'status' => $status,
                'read_status' => $readStatus,
                'http_status' => $row['http_status'] ?? null,
                'active' => $row['active'] ?? null,
            ]);
        }

        foreach (['shippingRates' => 'allegro_shipping_rate_missing_or_inactive', 'returnPolicy' => 'allegro_returnPolicy_missing:'.self::RETURN_POLICY_NAME, 'impliedWarranty' => 'allegro_impliedWarranty_missing:'.self::IMPLIED_WARRANTY_NAME, 'warranty' => 'allegro_warranty_missing:'.self::WARRANTY_NAME] as $key => $blocker) {
            if (! in_array(($result[$key]['status'] ?? null), ['mapped', 'manual_id'], true)) $result['blockers'][] = $blocker;
        }

        $result['blockers'] = array_values(array_unique($result['blockers']));
        return $result;
    }

    private function emptyRow(?string $name, string $status): array
    {
        return ['searched_name' => $name ?: null, 'resolved_id' => null, 'id' => null, 'status' => $status, 'read_status' => null];
    }

    private function manualId(string $key, ?string $searchedName): ?string
    {
        if ($key === 'shippingRates') {
            $searchedName = trim((string) $searchedName);
            return self::MANUAL_SHIPPING_RATE_IDS[$searchedName] ?? null;
        }

        return self::MANUAL_AFTER_SALES_SERVICE_IDS[$key] ?? null;
    }
}
