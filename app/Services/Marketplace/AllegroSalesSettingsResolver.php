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
            $result[$key] = array_merge($result[$key], [
                'searched_name' => $row['searched_name'] ?? $result[$key]['searched_name'],
                'resolved_id' => $row['id'] ?? null,
                'id' => $row['id'] ?? null,
                'status' => ($row['found'] ?? false) ? 'mapped' : (($row['reason'] ?? null) === 'read_failed' ? 'read_failed' : 'missing'),
                'http_status' => $row['http_status'] ?? null,
                'active' => $row['active'] ?? null,
            ]);
        }

        foreach (['shippingRates' => 'allegro_shipping_rate_missing_or_inactive', 'returnPolicy' => 'allegro_returnPolicy_missing:'.self::RETURN_POLICY_NAME, 'impliedWarranty' => 'allegro_impliedWarranty_missing:'.self::IMPLIED_WARRANTY_NAME, 'warranty' => 'allegro_warranty_missing:'.self::WARRANTY_NAME] as $key => $blocker) {
            if (($result[$key]['status'] ?? null) !== 'mapped') $result['blockers'][] = $blocker;
        }

        $result['blockers'] = array_values(array_unique($result['blockers']));
        return $result;
    }

    private function emptyRow(?string $name, string $status): array
    {
        return ['searched_name' => $name ?: null, 'resolved_id' => null, 'id' => null, 'status' => $status];
    }
}
