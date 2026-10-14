<?php

namespace App\Services\Storefront;

use App\Models\Part;
use App\Services\Marketplace\NbpExchangeRateService;

/** Storefront FR: parts.price PLN +25%, converted using the existing NBP runtime. */
class FrenchPriceResolver
{
    public function __construct(private NbpExchangeRateService $rates) {}

    public function resolve(?Part $part, ?array $previewRate = null): array
    {
        if (! $part) {
            return ['cents' => null, 'source' => null, 'reason' => 'missing_part'];
        }
        // Non-PLN records are unavailable, never guessed or taken from a marketplace.
        $rate = $previewRate ?? ($part->currency === 'PLN' ? $this->rateForCurrentRequest() : []);
        $price = FrenchPlnPricing::convert($part->price, $part->currency, FrenchPlnPricing::PART_MULTIPLIER, $rate);

        return $price + ['source' => $price['cents'] === null ? null : 'parts.price(PLN)*1.25->NBP_TABLE_A(EUR)',
            'conversion' => ['source_currency' => $part->currency, 'source_amount' => (string) $part->price,
                'currency' => 'EUR', 'rate' => $price['nbp_eur_rate'], 'effective_date' => $rate['effective_date'] ?? null,
                'table_no' => $rate['table_no'] ?? null, 'rounding' => 'half_up_2_decimals']];
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
}
