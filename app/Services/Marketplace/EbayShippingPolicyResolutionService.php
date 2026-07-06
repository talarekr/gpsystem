<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Support\Facades\Schema;

class EbayShippingPolicyResolutionService
{
    /** @return array<string, string> */
    public function availablePolicyMapping(string $channel): array
    {
        return $channel === 'ebay_fr'
            ? ['fr_55_eur' => '260547694013', 'fr_70_eur' => '260547464013', 'fr_130_eur' => '260547754013']
            : ['de_30_eur' => '259264150013', 'de_50_eur' => '259677066013', 'de_130_eur' => '259636579013'];
    }

    /** @return array<string, mixed> */
    public function resolve(?Part $part, ?MarketplaceCategoryMapping $mapping, string $channel): array
    {
        $fallback = $this->fallbackMapping($part, $mapping, $channel);
        $shippingGroup = filled($mapping?->shipping_group) ? (string) $mapping->shipping_group : (filled($fallback?->shipping_group) ? (string) $fallback->shipping_group : null);
        $fulfillmentPolicyId = filled($mapping?->fulfillment_policy_id) ? (string) $mapping->fulfillment_policy_id : (filled($fallback?->fulfillment_policy_id) ? (string) $fallback->fulfillment_policy_id : null);
        $shippingSource = filled($mapping?->shipping_group) ? 'marketplace_category_mappings.shipping_group' : ($fallback ? $this->sourceLabel($fallback, 'shipping_group') : null);
        $fulfillmentSource = filled($mapping?->fulfillment_policy_id) ? 'marketplace_category_mappings.fulfillment_policy_id' : ($fallback ? $this->sourceLabel($fallback, 'fulfillment_policy_id') : null);

        $missing = [];
        if (blank($shippingGroup)) $missing[] = $this->missingMessage($part, $mapping, $channel, 'shipping_group');
        if (blank($fulfillmentPolicyId)) $missing[] = $this->missingMessage($part, $mapping, $channel, 'fulfillment_policy_id');

        return [
            'local_category_id' => $part?->category_id,
            'local_category_name' => $part?->category?->name ?? $mapping?->local_category_name,
            'ebay_marketplace' => $channel,
            'ebay_category_id' => $mapping?->external_category_id,
            'ebay_category_name' => $mapping?->external_category_name,
            'ebay_category_path' => $mapping?->external_category_path,
            'shipping_group' => $shippingGroup,
            'shipping_group_source' => $shippingSource,
            'selected_fulfillment_policy_id' => $fulfillmentPolicyId,
            'fulfillment_policy_source' => $fulfillmentSource,
            'selected_fulfillment_policy_name' => $this->fulfillmentPolicyName($fulfillmentPolicyId, $shippingGroup),
            'available_policy_mapping' => $this->availablePolicyMapping($channel),
            'fallback_mapping' => $fallback ? [
                'id' => $fallback->id,
                'local_category_id' => $fallback->local_category_id,
                'channel' => $fallback->channel,
                'external_category_id' => $fallback->external_category_id,
                'external_category_name' => $fallback->external_category_name,
                'external_category_path' => $fallback->external_category_path,
            ] : null,
            'missing' => $missing,
        ];
    }

    public function fulfillmentPolicyName(?string $policyId, ?string $shippingGroup): ?string
    {
        if (blank($policyId)) return null;
        return match ((string) $policyId) {
            '259264150013' => 'Wysyłka 30 euro', '259677066013' => 'Wysyłka 50 euro', '259636579013' => 'Wysyłka 130 euro',
            '260547694013' => 'Wysyłka FR 55 euro', '260547464013' => 'Wysyłka FR 70 euro', '260547754013' => 'Wysyłka FR 130 euro',
            default => $shippingGroup,
        };
    }

    private function fallbackMapping(?Part $part, ?MarketplaceCategoryMapping $mapping, string $channel): ?MarketplaceCategoryMapping
    {
        if (! Schema::hasTable('marketplace_category_mappings')) return null;
        $needsShipping = blank($mapping?->shipping_group);
        $needsPolicy = blank($mapping?->fulfillment_policy_id);
        if (! $needsShipping && ! $needsPolicy) return null;

        if (filled($mapping?->external_category_id)) {
            $sameExternal = MarketplaceCategoryMapping::query()
                ->where('channel', $channel)
                ->where('external_category_id', (string) $mapping->external_category_id)
                ->where(function ($query) use ($needsShipping, $needsPolicy): void {
                    if ($needsShipping) $query->whereNotNull('shipping_group');
                    if ($needsPolicy) $query->whereNotNull('fulfillment_policy_id');
                })
                ->orderByRaw('case when local_category_id = ? then 0 else 1 end', [$mapping->local_category_id])
                ->first();
            if ($sameExternal) return $sameExternal;
        }

        foreach ($this->ancestorCategoryIds($part) as $categoryId) {
            $ancestor = MarketplaceCategoryMapping::query()
                ->where('local_category_id', $categoryId)
                ->whereIn('channel', [$channel, 'ebay'])
                ->where(function ($query) use ($needsShipping, $needsPolicy): void {
                    if ($needsShipping) $query->whereNotNull('shipping_group');
                    if ($needsPolicy) $query->whereNotNull('fulfillment_policy_id');
                })
                ->orderByRaw('case when channel = ? then 0 else 1 end', [$channel])
                ->first();
            if ($ancestor) return $ancestor;
        }

        return null;
    }

    /** @return array<int, int> */
    private function ancestorCategoryIds(?Part $part): array
    {
        $ids = [];
        $category = $part?->category;
        if (! $category && filled($part?->category_id) && Schema::hasTable('part_categories')) $category = PartCategory::query()->find($part->category_id);
        while ($category?->parent_id) {
            $ids[] = (int) $category->parent_id;
            $category = PartCategory::query()->find($category->parent_id);
        }
        return $ids;
    }

    private function sourceLabel(MarketplaceCategoryMapping $mapping, string $field): string
    {
        return 'marketplace_category_mappings.'.$field.'.fallback(mapping_id='.$mapping->id.', local_category_id='.$mapping->local_category_id.', channel='.$mapping->channel.')';
    }

    private function missingMessage(?Part $part, ?MarketplaceCategoryMapping $mapping, string $channel, string $field): string
    {
        $expected = $field === 'shipping_group' ? 'shipping_group' : 'fulfillment_policy_id';
        $path = $mapping?->external_category_path ?: $mapping?->external_category_name ?: '—';

        return sprintf(
            'Brak %s dla eBay marketplace=%s, category_id=%s, category_path/name=%s, local_category_id=%s, expected=%s.',
            $field === 'shipping_group' ? 'grupy wysyłkowej' : 'polityki wysyłki',
            $channel,
            $mapping?->external_category_id ?: '—',
            $path,
            $part?->category_id ?: '—',
            $expected,
        );
    }
}
