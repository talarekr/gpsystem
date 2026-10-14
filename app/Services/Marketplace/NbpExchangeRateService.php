<?php

namespace App\Services\Marketplace;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NbpExchangeRateService
{
    private const LEGACY_EUR_CACHE_KEY = 'nbp_table_a_eur_rate';

    /** Existing cache only: no provider requests, cache refresh, or writes. */
    public function cachedEurPlnForPreview(): array
    {
        foreach (['nbp_table_a_rate:EUR:latest', self::LEGACY_EUR_CACHE_KEY] as $key) {
            $cached = $this->previewCacheValue($key);
            if (! is_array($cached) || ! ($cached['ok'] ?? false)
                || ($cached['currency'] ?? null) !== 'EUR' || ($cached['source'] ?? null) !== 'NBP_TABLE_A'
                || ! is_numeric($cached['rate'] ?? null) || ! is_finite((float) $cached['rate']) || (float) $cached['rate'] <= 0) {
                continue;
            }
            $date = $cached['effective_date'] ?? null;
            if (! is_string($date) || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
                continue;
            }
            try {
                $effective = CarbonImmutable::createFromFormat('!Y-m-d', $date);
                if (! $effective || $effective->toDateString() !== $date || $effective->isFuture()
                    || $effective->lessThan(today()->subDays(7))) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            return array_replace($cached, ['rate' => (float) $cached['rate'], 'cached' => true, 'cache_key' => $key]);
        }

        return $this->unavailable('EUR', 'No valid existing NBP EUR cache (maximum age: seven days). Preview never refreshes it.');
    }

    /** Laravel database/file cache get() deletes expired entries; inspect without cleanup. */
    private function previewCacheValue(string $key): mixed
    {
        try {
            $name = Cache::getDefaultDriver();
            $store = Cache::store($name)->getStore();
            if ($store instanceof DatabaseStore) {
                $table = config("cache.stores.{$name}.table", 'cache');
                $row = $store->getConnection()->table($table)->where('key', $store->getPrefix().$key)->first();
                if (! $row || (int) $row->expiration <= now()->timestamp) {
                    return null;
                }
                $value = $row->value;
                if (! str_contains($value, ':') && ! str_contains($value, ';')) {
                    $value = base64_decode($value, true);
                }

                return is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : null;
            }
            if ($store instanceof FileStore) {
                $path = $store->path($key);
                if (! is_file($path)) {
                    return null;
                }
                $contents = $store->getFilesystem()->get($path);
                $expires = substr($contents, 0, 10);
                if (! ctype_digit($expires) || (int) $expires <= now()->timestamp) {
                    return null;
                }

                return @unserialize(substr($contents, 10), ['allowed_classes' => false]);
            }
            // Redis GET does not perform application writes; array is process-local test storage.
            if ($store instanceof RedisStore || $store instanceof ArrayStore) {
                return $store->get($key);
            }
        } catch (\Throwable) {
            // Unsupported/unavailable cache is a blocker, never a reason to refresh or log a write.
        }

        return null;
    }

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
