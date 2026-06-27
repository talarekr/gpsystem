<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NbpExchangeRateService
{
    private const CACHE_KEY = 'nbp_table_a_eur_rate';
    private const TABLE_A_EUR_URL = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/?format=json';

    public function eurPln(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && is_numeric($cached['rate'] ?? null)) {
            return $cached + ['ok' => true, 'source' => 'NBP_TABLE_A', 'cached' => true, 'warning' => null];
        }

        try {
            $response = Http::acceptJson()->timeout(10)->get(self::TABLE_A_EUR_URL);
            if (! $response->successful()) {
                return $this->unavailable('NBP EUR/PLN rate request failed with HTTP '.$response->status().'.');
            }

            $payload = $response->json();
            $rate = $payload['rates'][0]['mid'] ?? null;
            if (! is_numeric($rate) || (float) $rate <= 0) {
                return $this->unavailable('NBP EUR/PLN response did not contain a valid mid rate.');
            }

            $data = [
                'ok' => true,
                'rate' => round((float) $rate, 6),
                'source' => 'NBP_TABLE_A',
                'cached' => false,
                'effective_date' => $payload['rates'][0]['effectiveDate'] ?? null,
                'table_no' => $payload['rates'][0]['no'] ?? $payload['no'] ?? null,
                'fetched_at' => now()->toISOString(),
                'warning' => null,
            ];

            Cache::put(self::CACHE_KEY, $data, now()->addHours((int) config('product-hub.exchange_rates.nbp_cache_hours', 12)));

            return $data;
        } catch (\Throwable $exception) {
            return $this->unavailable('NBP EUR/PLN rate unavailable: '.$exception->getMessage());
        }
    }

    private function unavailable(string $warning): array
    {
        return [
            'ok' => false,
            'rate' => null,
            'source' => 'NBP_TABLE_A',
            'cached' => false,
            'effective_date' => null,
            'table_no' => null,
            'fetched_at' => null,
            'warning' => $warning,
        ];
    }
}
