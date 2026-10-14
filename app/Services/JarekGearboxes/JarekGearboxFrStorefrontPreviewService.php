<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\NbpExchangeRateService;
use App\Services\Storefront\FrenchDeliveryQuoteService;
use App\Services\Storefront\FrenchPlnPricing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Inspection only. Never save models, refresh rates, translate, or invoke providers. */
class JarekGearboxFrStorefrontPreviewService
{
    public function __construct(private NbpExchangeRateService $rates, private FrenchDeliveryQuoteService $delivery) {}

    public function preview(int $limit = 50, int $offset = 0): array
    {
        $base = [
            'read_only' => true, 'dry_run' => true, 'database_write' => false,
            'marketplace_write' => false, 'stock_write' => false, 'external_api_requests' => false,
            'apply_available' => false, 'storefront_code' => 'gpswiss_fr', 'generated_at' => now()->toIso8601String(),
            'eligibility_definition' => 'eligible means safe to apply with current code; data readiness alone is insufficient',
            'required_next_stage' => ['fr_only_visibility', 'independent_jarek_fr_stock', 'jarek_fr_price_resolver'],
        ];
        $summary = array_fill_keys([
            'total_jarek_gearboxes', 'eligible_for_fr_count', 'blocked_count', 'already_in_parts_count',
            'needs_part_creation_count', 'missing_price_count', 'non_pln_price_count', 'missing_images_count',
            'missing_description_count', 'missing_delivery_mapping_count', 'visibility_risk_count',
            'duplicate_risk_count', 'stock_behavior_required_count', 'data_ready_count',
        ], 0);
        if (! Schema::hasTable('jarek_gearboxes')) {
            return $base + ['ok' => false, 'blockers' => ['jarek_table_missing'], 'summary' => $summary, 'sample_products' => []];
        }

        $products = JarekGearbox::query()->orderBy('id')->get();
        $rate = $products->isEmpty() ? null : $this->rates->cachedEurPlnForPreview();
        $parts = Schema::hasTable('parts') ? Part::query()->where(function ($q) use ($products): void {
            $q->where('source_system', 'jarek')->orWhere('sku', 'like', 'JAREK-%')
                ->orWhere('sku', 'like', 'JAREK-GB-%')
                ->orWhereIn('external_id', $products->pluck('allegro_offer_id')->filter()->all());
        })->get() : collect();
        $visibleIds = $parts->isEmpty() ? [] : Part::query()->storefrontVisible()->whereIn('id', $parts->modelKeys())->pluck('id')->all();
        $categories = Schema::hasTable('part_categories') ? PartCategory::query()->get() : collect();
        $mappings = Schema::hasTable('marketplace_category_mappings')
            ? MarketplaceCategoryMapping::query()->whereIn('channel', ['allegro', 'allegro_main'])->get() : collect();
        $listings = Schema::hasTable('marketplace_listings') ? MarketplaceListing::query()->with('account:id,code')
            ->where(function ($q) use ($parts, $products): void {
                $q->whereIn('part_id', $parts->modelKeys())->orWhereIn('sku', $products->map(fn ($p) => 'JAREK-'.($p->allegro_offer_id ?: $p->id))->all());
            })->get() : collect();
        $rows = $products->map(fn ($p) => $this->inspect($p, $parts, $visibleIds, $categories, $mappings, $listings, $rate));
        $summary['total_jarek_gearboxes'] = $rows->count();
        $summary['eligible_for_fr_count'] = $rows->where('status', 'eligible')->count();
        $summary['blocked_count'] = $rows->where('status', 'blocked')->count();
        $summary['already_in_parts_count'] = $rows->where('existing_part', true)->count();
        $summary['needs_part_creation_count'] = $rows->where('needs_part_creation', true)->count();
        $summary['data_ready_count'] = $rows->where('data_ready', true)->count();
        foreach ([
            'missing_price_count' => 'missing_price', 'non_pln_price_count' => 'non_pln_price',
            'missing_images_count' => 'missing_images', 'missing_description_count' => 'missing_description',
            'missing_delivery_mapping_count' => 'missing_delivery_mapping', 'visibility_risk_count' => 'pl_visibility_risk',
            'duplicate_risk_count' => 'duplicate_risk', 'stock_behavior_required_count' => 'independent_fr_stock_required',
        ] as $counter => $blocker) {
            $summary[$counter] = $rows->filter(fn ($row) => in_array($blocker, $row['blockers'], true))->count();
        }

        return $base + [
            'ok' => true, 'summary' => $summary, 'nbp_rate' => $rate,
            'blocker_counts' => $rows->flatMap(fn ($row) => $row['blockers'])->countBy()->all(),
            'offset' => max(0, $offset), 'limit' => max(1, min(200, $limit)),
            'sample_products' => $rows->slice(max(0, $offset), max(1, min(200, $limit)))->values()->all(),
            'next_offset' => max(0, $offset) + $rows->slice(max(0, $offset), max(1, min(200, $limit)))->count(),
        ];
    }

    private function inspect(JarekGearbox $product, Collection $parts, array $visibleIds, Collection $categories, Collection $mappings, Collection $listings, ?array $rate): array
    {
        $offerId = trim((string) $product->allegro_offer_id);
        $sku = 'JAREK-'.($offerId ?: $product->id);
        $matches = $parts->filter(fn ($p) => $p->sku === $sku || $p->sku === 'JAREK-GB-'.$product->id
            || ($p->source_system === 'jarek' && (
                ($offerId !== '' && (string) $p->external_id === $offerId)
                || (string) data_get($p->legacy_payload, 'jarek_gearbox_id') === (string) $product->id
                || (string) data_get($p->legacy_payload, 'source_jarek_gearbox_id') === (string) $product->id)))->values();
        $part = $matches->count() === 1 ? $matches->first() : null;
        [$category, $categorySource, $categoryBlocker] = $this->category($product, $part, $categories, $mappings);
        // Unsaved Part is only a typed input to the existing local delivery adapter.
        $deliveryPart = new Part;
        $deliveryPart->category_id = $category?->id;
        $delivery = $this->delivery->forPart($deliveryPart);
        $images = $product->displayImageUrls();
        $hasDescription = $this->hasDescription($product);
        $currency = strtoupper(trim((string) $product->currency));
        $price = is_numeric($product->price) && is_finite((float) $product->price) && (float) $product->price > 0 ? (float) $product->price : null;
        $pricing = FrenchPlnPricing::convert($price, $currency,
            FrenchPlnPricing::JAREK_MULTIPLIER, $rate ?? []);
        $marked = $pricing['price_pln_after_margin'];
        $eur = $pricing['cents'] === null ? null : $pricing['cents'] / 100;
        $dataBlockers = [];
        if (trim((string) $product->title) === '') {
            $dataBlockers[] = 'missing_title';
        }
        if ($offerId === '') {
            $dataBlockers[] = 'missing_source_identifier';
        }
        if ($price === null) {
            $dataBlockers[] = 'missing_price';
        }
        if ($currency !== 'PLN') {
            $dataBlockers[] = 'non_pln_price';
        }
        if ($images === []) {
            $dataBlockers[] = 'missing_images';
        }
        if (! $hasDescription) {
            $dataBlockers[] = 'missing_description';
        }
        if (! ($rate['ok'] ?? false)) {
            $dataBlockers[] = 'nbp_cached_rate_unavailable';
        }
        if ($eur !== null && ($eur < 0.01 || $eur > 999999.99)) {
            $dataBlockers[] = 'eur_price_out_of_checkout_range';
        }
        if ($categoryBlocker) {
            $dataBlockers[] = $categoryBlocker;
        }
        if ($category && $category->is_visible === false) {
            $dataBlockers[] = 'part_category_hidden';
        }
        if (! $delivery['available']) {
            $dataBlockers[] = 'missing_delivery_mapping';
        }
        if (! Schema::hasTable('parts')) {
            $dataBlockers[] = 'parts_table_missing';
        }
        if ($matches->count() > 1) {
            $dataBlockers[] = 'duplicate_risk';
        }
        if ($part && $part->source_system !== 'jarek') {
            $dataBlockers[] = 'existing_part_source_conflict';
        }
        $existingListings = $listings->filter(fn ($l) => in_array($l->part_id, $matches->modelKeys(), true)
            || $l->sku === $sku || ($product->ebay_inventory_sku && $l->sku === $product->ebay_inventory_sku));
        $duplicateSkus = $existingListings->groupBy(fn ($l) => $l->marketplace.'|'.$l->marketplace_account_id.'|'.$l->sku)->filter(fn ($group) => $group->count() > 1);
        if ($duplicateSkus->isNotEmpty()) {
            $dataBlockers[] = 'duplicate_risk';
        }
        $blockers = array_values(array_unique(array_merge($dataBlockers, [
            'pl_visibility_risk', 'independent_fr_stock_required', 'jarek_fr_markup_resolver_required',
        ])));

        return [
            'jarek_gearbox_id' => $product->id, 'allegro_offer_id' => $offerId ?: null,
            'title' => $product->title, 'sku' => $sku, 'has_description' => $hasDescription,
            'has_images' => $images !== [], 'images_count' => count($images),
            'image_verification' => 'local_paths_or_stored_urls_only; no HTTP checks',
            'base_price' => $price, 'source_price_raw' => $product->price, 'base_currency' => $currency, 'base_price_source' => 'jarek_gearboxes.price (imported Allegro offer price)',
            'markup_percent' => 50, 'applied_margin_steps' => [25, 20], 'price_after_markup_pln' => $marked,
            'nbp_eur_rate' => ($rate['ok'] ?? false) ? $rate['rate'] : null,
            'nbp_effective_date' => $rate['effective_date'] ?? null, 'nbp_table_no' => $rate['table_no'] ?? null,
            'price_eur' => $eur, 'historical_ebay_de_price' => $this->historicalPrice($product, $existingListings),
            'source_quantity' => $product->quantity, 'source_allegro_status' => $product->allegro_status,
            'source_stock_updated_at' => $product->updated_from_allegro_at?->toIso8601String(),
            'fr_quantity_decision' => 1, 'source_stock_decrement' => false, 'marketplace_stock_sync' => false,
            'stock_behavior_required' => true,
            'source_category' => ['id' => $product->category_id, 'name' => $product->category_name, 'path' => $product->category_path],
            'proposed_part_category' => $category ? ['id' => $category->id, 'name' => $category->name, 'source' => $categorySource] : null,
            'proposed_fr_delivery' => $delivery + ['amount_eur' => $delivery['available'] ? $delivery['amount_cents'] / 100 : null],
            'existing_part' => $matches->isNotEmpty(), 'existing_part_ids' => $matches->modelKeys(),
            'needs_part_creation' => $matches->isEmpty(), 'source_system' => 'jarek',
            'proposed_visibility' => ['gpswiss_fr' => true, 'gpswiss_pl' => false],
            'currently_visible_via_shared_catalog' => array_values(array_intersect($matches->modelKeys(), $visibleIds)),
            'would_be_visible_on_pl_if_prepared_with_current_code' => true,
            'visibility_risk' => true, 'linked_listing_count' => $existingListings->count(),
            'source_ebay_status' => $product->ebay_status, 'source_ebay_listing_id' => $product->ebay_listing_id,
            'data_ready' => $dataBlockers === [], 'data_blockers' => array_values(array_unique($dataBlockers)),
            'blockers' => $blockers, 'status' => $blockers === [] ? 'eligible' : 'blocked',
            'warnings' => ['tax_basis_not_inferred', 'confirm_core_return_terms_for_fr', 'stored_image_urls_not_remotely_verified'],
        ];
    }

    private function category(JarekGearbox $product, ?Part $part, Collection $categories, Collection $mappings): array
    {
        if ($part?->category_id) {
            $category = $categories->firstWhere('id', $part->category_id);

            return [$category, 'existing_part.category_id', $category ? null : 'part_category_missing'];
        }
        $sourceMappings = $mappings->filter(fn ($m) => (string) $m->external_category_id === (string) $product->category_id);
        if ($sourceMappings->isNotEmpty() && $sourceMappings->every(fn ($m) => $m->is_blocked)) {
            return [null, null, 'part_category_mapping_blocked'];
        }
        $ids = $mappings->filter(fn ($m) => ! $m->is_blocked && (string) $m->external_category_id === (string) $product->category_id
            && $m->local_category_id)->pluck('local_category_id')->unique()->values();
        if ($ids->count() > 1) {
            return [null, null, 'part_category_ambiguous'];
        }
        if ($ids->count() === 1) {
            $category = $categories->firstWhere('id', $ids->first());

            return [$category, 'allegro_category_mapping', $category ? null : 'part_category_missing'];
        }
        $matches = $categories->filter(fn ($c) => trim((string) $product->category_name) !== '' && $c->name === $product->category_name);
        if ($matches->count() === 1) {
            return [$matches->first(), 'exact_category_name', null];
        }

        return [null, null, $matches->count() > 1 ? 'part_category_ambiguous' : 'part_category_missing'];
    }

    private function hasDescription(JarekGearbox $product): bool
    {
        if (trim(strip_tags((string) $product->plain_description)) !== '') {
            return true;
        }
        $description = (string) $product->description;
        $decoded = json_decode($description, true);
        if (is_array($decoded)) {
            $description = collect($decoded['sections'] ?? [])->flatMap(fn ($section) => $section['items'] ?? [])
                ->pluck('content')->filter('is_string')->implode(' ');
        }

        return trim(strip_tags($description)) !== '';
    }

    private function historicalPrice(JarekGearbox $product, Collection $listings): ?array
    {
        $snapshot = (array) $product->ebay_payload_snapshot;
        $market = strtoupper((string) data_get($snapshot, '_jarek_price_fetch.marketplaceId', data_get($snapshot, 'marketplaceId', 'EBAY_DE')));
        $value = data_get($snapshot, '_jarek_price_fetch.pricingSummary.price.value');
        if ($market === 'EBAY_DE' && is_numeric($value)) {
            return ['value' => (float) $value, 'currency' => data_get($snapshot, '_jarek_price_fetch.pricingSummary.price.currency'),
                'source' => 'ebay_payload_snapshot._jarek_price_fetch', 'observed_at' => data_get($snapshot, '_jarek_price_fetch.fetched_at'),
                'is_current_verified' => false];
        }
        $listing = $listings->filter(fn ($l) => $l->marketplace === 'ebay_de'
            || ($l->marketplace === 'ebay' && ($l->account?->code === 'ebay_de' || data_get($l->raw_payload, 'marketplaceId') === 'EBAY_DE')))->sortByDesc('last_synced_at')->first();

        return $listing && is_numeric($listing->price) ? ['value' => (float) $listing->price, 'currency' => $listing->currency,
            'source' => 'marketplace_listings:'.$listing->id, 'observed_at' => $listing->last_synced_at?->toIso8601String(), 'is_current_verified' => false] : null;
    }
}
