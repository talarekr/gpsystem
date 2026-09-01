<?php

namespace App\Services\Admin;

use App\Services\Marketplace\NbpExchangeRateService;
use Carbon\CarbonInterface;

class CurrencyConversionService
{
    public function __construct(private readonly NbpExchangeRateService $rates) {}

    public function toPln(float|int|string|null $amount, ?string $currency, CarbonInterface|string|null $date): array
    {
        $originalAmount = is_numeric($amount) ? (float) $amount : null;
        $originalCurrency = strtoupper(trim((string) ($currency ?: 'PLN')));
        $base = ['original_amount' => $originalAmount, 'original_currency' => $originalCurrency, 'exchange_rate' => null, 'exchange_rate_unit' => 1, 'exchange_rate_effective_date' => null, 'exchange_rate_table_no' => null, 'converted_amount_pln' => null, 'conversion_source' => $originalCurrency === 'PLN' ? 'identity' : 'nbp', 'conversion_status' => 'unconverted', 'warning' => null];

        if ($originalAmount === null) {
            return $base + ['warning' => 'Order amount is missing or not numeric.'];
        }
        if ($originalCurrency === 'PLN') {
            return array_merge($base, ['exchange_rate' => 1.0, 'converted_amount_pln' => $originalAmount, 'conversion_status' => 'not_required']);
        }

        $rate = $this->rates->rateFor($originalCurrency, $date);
        if (! ($rate['ok'] ?? false)) {
            return array_merge($base, ['exchange_rate_unit' => $rate['unit'] ?? 1, 'warning' => $rate['warning'] ?? 'NBP rate unavailable.']);
        }

        return array_merge($base, [
            'exchange_rate' => $rate['quoted_rate'],
            'exchange_rate_unit' => $rate['unit'],
            'exchange_rate_effective_date' => $rate['effective_date'],
            'exchange_rate_table_no' => $rate['table_no'],
            'converted_amount_pln' => round($originalAmount * ((float) $rate['quoted_rate'] / (int) $rate['unit']), 2),
            'conversion_status' => 'converted',
        ]);
    }
}
