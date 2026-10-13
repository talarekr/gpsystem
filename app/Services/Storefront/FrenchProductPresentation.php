<?php

namespace App\Services\Storefront;

use App\Models\Part;

/** Public FR uses exactly the read-only price and delivery sources of checkout. */
class FrenchProductPresentation
{
    public function __construct(private FrenchPriceResolver $prices, private FrenchDeliveryQuoteService $delivery) {}

    public function forPart(?Part $part): array
    {
        $price = $this->prices->resolve($part);
        $quote = $this->delivery->forPart($part);

        return [
            'cents' => $price['cents'], 'currency' => 'EUR', 'source' => $price['source'],
            'delivery' => $quote,
            'can_buy' => $price['cents'] !== null && $price['cents'] > 0 && $quote['available'],
        ];
    }
}
