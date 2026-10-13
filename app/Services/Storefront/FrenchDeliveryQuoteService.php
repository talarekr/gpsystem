<?php

namespace App\Services\Storefront;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Services\Marketplace\EbayShippingPolicyResolutionService;
use Illuminate\Support\Facades\Schema;

/** Read-only adapter of existing local eBay FR category/policy assignments. No API/imports. */
class FrenchDeliveryQuoteService
{
    public function __construct(private EbayShippingPolicyResolutionService $policies) {}

    public function forPart(?Part $part): array
    {
        $unavailable = ['available' => false, 'amount_cents' => null, 'currency' => 'EUR'];
        if (! $part?->category_id || ! Schema::hasTable('marketplace_category_mappings')) {
            return $unavailable + ['reason' => 'delivery_mapping_missing'];
        }
        $mapping = MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)
            ->where('channel', 'ebay_fr')->first();
        if ($mapping?->is_blocked) {
            return $unavailable + ['reason' => 'delivery_mapping_blocked'];
        }
        // Resolver uses the first same-external-category fallback; reject ambiguous rates.
        if ($mapping?->external_category_id && (! $mapping->shipping_group || ! $mapping->fulfillment_policy_id)) {
            $pairs = MarketplaceCategoryMapping::query()->where('channel', 'ebay_fr')
                ->where('external_category_id', $mapping->external_category_id)
                ->whereNotNull('shipping_group')->whereNotNull('fulfillment_policy_id')
                ->get()->map(fn ($row) => $row->shipping_group.'|'.$row->fulfillment_policy_id)->unique();
            if ($pairs->count() > 1) {
                return $unavailable + ['reason' => 'delivery_mapping_ambiguous'];
            }
        }
        $resolved = $this->policies->resolve($part, $mapping, 'ebay_fr');
        $fallbackId = $resolved['fallback_mapping']['id'] ?? null;
        if ($fallbackId && MarketplaceCategoryMapping::query()->find($fallbackId)?->is_blocked) {
            return $unavailable + ['reason' => 'delivery_mapping_blocked'];
        }
        $group = $resolved['shipping_group'];
        $policy = $resolved['selected_fulfillment_policy_id'];
        $known = $this->policies->availablePolicyMapping('ebay_fr');
        if (! $group || ! isset($known[$group]) || $known[$group] !== $policy
            || ! preg_match('/^fr_([0-9]+)_eur$/', $group, $match)) {
            return $unavailable + ['reason' => 'delivery_policy_unrecognized'];
        }

        return [
            'available' => true, 'amount_cents' => (int) $match[1] * 100, 'currency' => 'EUR',
            'shipping_group' => $group, 'fulfillment_policy_id' => $policy,
            'mapping_id' => $mapping?->id, 'fallback_mapping_id' => $fallbackId,
            'source' => $resolved['shipping_group_source'],
        ];
    }
}
