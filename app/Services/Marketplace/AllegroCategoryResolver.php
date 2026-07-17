<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use Illuminate\Support\Facades\Schema;

class AllegroCategoryResolver
{
    public function resolve(?Part $part, mixed $formSelection = null): array
    {
        $formSelection = trim((string) $formSelection);
        if ($formSelection !== '') {
            return ['id' => $formSelection, 'source' => 'form_selection', 'mapping' => null];
        }

        $override = data_get(is_array($part?->review_metadata) ? $part->review_metadata : [], 'marketplace_category_overrides.allegro.external_category_id');
        if (filled($override)) {
            return ['id' => (string) $override, 'source' => 'part_override', 'mapping' => null];
        }

        if (! $part || blank($part->category_id) || ! Schema::hasTable('marketplace_category_mappings')) {
            return ['id' => null, 'source' => null, 'mapping' => null];
        }

        $mapping = MarketplaceCategoryMapping::query()
            ->where('local_category_id', $part->category_id)
            ->whereIn('channel', ['allegro_main', 'allegro'])
            ->whereNotNull('external_category_id')
            ->orderByRaw("case when channel = 'allegro_main' then 0 when channel = 'allegro' then 1 else 2 end")
            ->orderBy('id')
            ->first();

        return ['id' => $mapping?->external_category_id ? (string) $mapping->external_category_id : null, 'source' => $mapping ? 'mapping' : null, 'mapping' => $mapping];
    }
}
