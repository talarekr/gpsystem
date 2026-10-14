<?php

namespace App\Services\Storefront;

use App\Models\Part;
use App\Services\Marketplace\NbpExchangeRateService;
use Carbon\CarbonImmutable;

/** Approved priority: explicit local eBay DE EUR, Part EUR, then PLN/NBP. No eBay FR price. */
class FrenchPriceResolver
{
    public function __construct(private NbpExchangeRateService $rates, private EbayDeLocalPrice $de) {}

    public function resolve(?Part $part, ?array $previewRate = null): array
    {
        $policy = config('storefront-fr-checkout.pricing_policy', 'existing_eur_nbp');
        if (! $part || ! in_array($policy, ['existing_eur', 'catalog_eur', 'existing_eur_nbp'], true)) {
            return ['cents' => null, 'source' => null];
        }
        $de = $this->de->resolve($part);
        if ($de['cents'] !== null || $de['reason'] === 'ambiguous_ebay_listing') {
            return $de;
        }
        if ($part->currency === 'EUR' && is_numeric($part->price) && is_finite((float) $part->price)
            && (float) $part->price >= 0.01 && (float) $part->price <= 999999.99) {
            return ['cents' => (int) round((float) $part->price * 100), 'source' => 'parts.price(EUR)'];
        }

        return $this->converted($part, $policy, $previewRate);
    }

    public function rateForCurrentRequest(): array
    {
        $request = request();
        $key = 'storefront.fr.nbp.eur_rate';
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->rates->currentEurPln());
        }

        return $request->attributes->get($key);
    }

    private function converted(Part $part, string $policy, ?array $previewRate = null): array
    {
        $unavailable = ['cents' => null, 'source' => null];
        if ($policy !== 'existing_eur_nbp' || $part->currency !== 'PLN'
            || ! is_numeric($part->price) || ! is_finite((float) $part->price) || (float) $part->price <= 0) {
            return $unavailable;
        }
        try {
            $rate = $previewRate ?? $this->rateForCurrentRequest();
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
