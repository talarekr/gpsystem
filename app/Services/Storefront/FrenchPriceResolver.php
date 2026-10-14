<?php

namespace App\Services\Storefront;

use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\NbpExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/** Existing EUR first; approved PLN fallback through the shared NBP table-A service. */
class FrenchPriceResolver
{
    public function __construct(private NbpExchangeRateService $rates) {}

    public function resolve(?Part $part): array
    {
        $policy = config('storefront-fr-checkout.pricing_policy', 'existing_eur_nbp');
        if (! $part || ! in_array($policy, ['existing_eur', 'catalog_eur', 'existing_eur_nbp'], true)) {
            return ['cents' => null, 'source' => null];
        }
        if ($part->currency === 'EUR' && is_numeric($part->price) && (float) $part->price > 0) {
            return ['cents' => (int) round((float) $part->price * 100), 'source' => 'parts.price(EUR)'];
        }
        if ($policy === 'catalog_eur') {
            return ['cents' => null, 'source' => null];
        }
        if (! Schema::hasTable('marketplace_listings') || ! Schema::hasTable('marketplace_accounts')) {
            return $this->converted($part, $policy);
        }
        $listings = MarketplaceListing::query()->where('part_id', $part->id)->where('currency', 'EUR')
            ->where('price', '>', 0)->where('quantity', '>', 0)->whereIn('status', ['active', 'ACTIVE', 'published', 'listed'])
            ->where(function ($query): void {
                $query->where('marketplace', 'ebay_fr')->orWhere(function ($query): void {
                    $query->where('marketplace', 'ebay')->whereHas('account', fn ($q) => $q->where('code', 'ebay_fr'));
                });
            })->orderBy('id')->get();
        $amounts = $listings->map(fn ($listing) => (int) round((float) $listing->price * 100))->unique();
        if ($amounts->isEmpty()) {
            return $this->converted($part, $policy);
        }
        if ($amounts->count() !== 1 || $amounts->first() <= 0) {
            return ['cents' => null, 'source' => null];
        }

        return ['cents' => $amounts->first(), 'source' => 'marketplace_listings:'.$listings->first()->id.'(ebay_fr,EUR)'];
    }

    private function converted(Part $part, string $policy): array
    {
        $unavailable = ['cents' => null, 'source' => null];
        if ($policy !== 'existing_eur_nbp' || $part->currency !== 'PLN'
            || ! is_numeric($part->price) || ! is_finite((float) $part->price) || (float) $part->price <= 0) {
            return $unavailable;
        }
        try {
            // One lookup per HTTP request, including failures; existing service owns the cache/API.
            $request = request();
            $key = 'storefront.fr.nbp.eur_rate';
            if (! $request->attributes->has($key)) {
                $request->attributes->set($key, $this->rates->currentEurPln());
            }
            $rate = $request->attributes->get($key);
            $value = is_numeric($rate['rate'] ?? null) ? (float) $rate['rate'] : 0;
            $date = $rate['effective_date'] ?? null;
            if (! ($rate['ok'] ?? false) || ($rate['currency'] ?? null) !== 'EUR'
                || ($rate['source'] ?? null) !== 'NBP_TABLE_A' || ! is_finite($value) || $value <= 0
                || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $unavailable;
            }
            $effective = CarbonImmutable::createFromFormat('!Y-m-d', $date);
            if (! $effective || $effective->toDateString() !== $date || $effective->isFuture()
                || $effective->lessThan(today()->subDays(7))) {
                return $unavailable;
            }
            // Existing eBay rounding policy: round(PLN / EURPLN, 2), half up, no markup.
            $eur = round((float) $part->price / $value, 2, PHP_ROUND_HALF_UP);
            $cents = round($eur * 100, 0, PHP_ROUND_HALF_UP);
            if (! is_finite($cents) || $cents < 1 || $cents > 99999999) {
                return $unavailable;
            }

            return ['cents' => (int) $cents, 'source' => 'parts.price(PLN)->NBP_TABLE_A(EUR)',
                'conversion' => ['source_currency' => 'PLN', 'source_amount' => (string) $part->price,
                    'currency' => 'EUR', 'rate' => $value, 'effective_date' => $date,
                    'table_no' => $rate['table_no'] ?? null, 'rounding' => 'half_up_2_decimals']];
        } catch (\Throwable) {
            return $unavailable;
        }
    }
}
