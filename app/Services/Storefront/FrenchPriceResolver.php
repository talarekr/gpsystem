<?php

namespace App\Services\Storefront;

use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Facades\Schema;

/** Existing EUR amounts only. No API, conversion, price writes or SKU-only guesses. */
class FrenchPriceResolver
{
    public function resolve(?Part $part): array
    {
        $policy = config('storefront-fr-checkout.pricing_policy', 'existing_eur');
        if (! $part || ! in_array($policy, ['existing_eur', 'catalog_eur'], true)) {
            return ['cents' => null, 'source' => null];
        }
        if ($part->currency === 'EUR' && is_numeric($part->price) && (float) $part->price > 0) {
            return ['cents' => (int) round((float) $part->price * 100), 'source' => 'parts.price(EUR)'];
        }
        if ($policy === 'catalog_eur' || ! Schema::hasTable('marketplace_listings') || ! Schema::hasTable('marketplace_accounts')) {
            return ['cents' => null, 'source' => null];
        }
        $listings = MarketplaceListing::query()->where('part_id', $part->id)->where('currency', 'EUR')
            ->where('price', '>', 0)->where('quantity', '>', 0)->whereIn('status', ['active', 'ACTIVE', 'published', 'listed'])
            ->where(function ($query): void {
                $query->where('marketplace', 'ebay_fr')->orWhere(function ($query): void {
                    $query->where('marketplace', 'ebay')->whereHas('account', fn ($q) => $q->where('code', 'ebay_fr'));
                });
            })->orderBy('id')->get();
        $amounts = $listings->map(fn ($listing) => (int) round((float) $listing->price * 100))->unique();
        if ($amounts->count() !== 1 || $amounts->first() <= 0) {
            return ['cents' => null, 'source' => null];
        }

        return ['cents' => $amounts->first(), 'source' => 'marketplace_listings:'.$listings->first()->id.'(ebay_fr,EUR)'];
    }
}
