<?php

namespace App\Services\Marketplace;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NbpExchangeRateService
{
    private const LEGACY_EUR_CACHE_KEY = 'nbp_table_a_eur_rate';

    public function eurPln(): array
    {
        $cached = Cache::get(self::LEGACY_EUR_CACHE_KEY);
        if (is_array($cached) && is_numeric($cached['rate'] ?? null)) {
            return $cached + ['ok' => true, 'source' => 'NBP_TABLE_A', 'cached' => true, 'warning' => null];
        }

        $result = $this->rateFor('EUR');
        if ($result['ok']) {
            Cache::put(self::LEGACY_EUR_CACHE_KEY, $result, now()->addHours($this->cacheHours()));
        }

        return $result;
    }

    /** Return the most recent table-A rate not later than the requested date. */
    public function rateFor(string $currency, CarbonInterface|string|null $date = null): array
    {
        $currency = strtoupper(trim($currency));
        $requestedDate = $date ? CarbonImmutable::parse($date)->startOfDay() : null;
        $cacheKey = 'nbp_table_a_rate:'.$currency.':'.($requestedDate?->toDateString() ?? 'latest');

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached + ['cached' => true];
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            return $this->unavailable($currency, 'Invalid currency code.');
        }

        try {
            $url = 'https://api.nbp.pl/api/exchangerates/rates/a/'.strtolower($currency).'/';
            if ($requestedDate) {
                // Two weeks covers weekends and Polish public-holiday closures. The selected
                // row is explicitly constrained to <= order date, so a future rate is impossible.
                $end = $requestedDate->min(CarbonImmutable::today());
                $url .= $end->subDays(14)->toDateString().'/'.$end->toDateString().'/';
            }

            $response = Http::acceptJson()->timeout(10)->get($url, ['format' => 'json']);
            if (! $response->successful()) {
                return $this->unavailable($currency, 'NBP table A request failed with HTTP '.$response->status().'.');
            }

            $rates = collect($response->json('rates', []))
                ->filter(fn ($row) => is_numeric($row['mid'] ?? null) && (float) $row['mid'] > 0)
                ->when($requestedDate, fn ($rows) => $rows->filter(fn ($row) => ($row['effectiveDate'] ?? '9999-12-31') <= $requestedDate->toDateString()))
                ->sortByDesc('effectiveDate');
            $row = $rates->first();
            if (! $row) {
                return $this->unavailable($currency, 'No NBP table A rate exists on or before the requested date.');
            }

            // NBP API publishes a per-unit mid rate. For currencies customarily displayed
            // per 100 units, expose that quotation unit while retaining the per-unit rate.
            $unit = $currency === 'HUF' ? 100 : 1;
            $result = [
                'ok' => true,
                'currency' => $currency,
                'rate' => round((float) $row['mid'], 8),
                'quoted_rate' => round((float) $row['mid'] * $unit, 8),
                'unit' => $unit,
                'source' => 'NBP_TABLE_A',
                'cached' => false,
                'effective_date' => $row['effectiveDate'] ?? null,
                'table_no' => $row['no'] ?? null,
                'fetched_at' => now()->toISOString(),
                'warning' => null,
            ];
            Cache::put($cacheKey, $result, now()->addHours($this->cacheHours()));

            return $result;
        } catch (\Throwable $exception) {
            return $this->unavailable($currency, 'NBP table A rate unavailable: '.$exception->getMessage());
        }
    }

    private function cacheHours(): int
    {
        return max(1, (int) config('product-hub.exchange_rates.nbp_cache_hours', 12));
    }

    private function unavailable(string $currency, string $warning): array
    {
        return ['ok' => false, 'currency' => $currency, 'rate' => null, 'quoted_rate' => null, 'unit' => $currency === 'HUF' ? 100 : 1, 'source' => 'NBP_TABLE_A', 'cached' => false, 'effective_date' => null, 'table_no' => null, 'fetched_at' => null, 'warning' => $warning];
    }
}
