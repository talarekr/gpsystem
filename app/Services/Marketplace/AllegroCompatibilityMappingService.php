<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Support\Facades\Schema;

class AllegroCompatibilityMappingService
{
    public function dryRun(Part $part, ?array $publishPayloadPreview = null): array
    {
        $part->loadMissing(['category', 'images', 'marketplaceListings', 'car']);
        $mapping = $this->categoryMapping($part);
        $categoryId = (string) ($mapping?->external_category_id ?? '');
        $account = $this->account();
        $identifiers = $this->identifiers($part);

        $result = $this->baseResult($part, $categoryId, $identifiers, $publishPayloadPreview);

        if (! $account) return $this->logAndReturn($this->blocked($result, 'missing_allegro_account'));
        if ($categoryId === '') return $this->logAndReturn($this->blocked($result, 'missing_allegro_category_mapping'));
        $client = new AllegroApiClient('allegro_main', $account);
        $supported = $client->compatibilitySupportedCategories();
        $result['api_diagnostics']['supported_categories'] = $this->summary($supported);
        $support = $this->categorySupportMatch($supported['json'] ?? [], $categoryId);
        $result = array_merge($result, $support);
        if (! $result['category_supports_compatibility']) return $this->logAndReturn($this->blocked($result, 'allegro_category_does_not_support_compatibility_list'));

        $candidates = [];
        if ($identifiers === []) {
            $result['product_catalog_blocked_reason'] = 'missing_part_number_oe_manufacturer_number_ean_or_sku';
        }
        foreach ($identifiers as $identifier) {
            $search = $client->searchProducts($categoryId, (string) $identifier['value']);
            $result['api_diagnostics']['product_searches'][] = $this->summary($search) + ['identifier' => $identifier];
            foreach (($search['products'] ?? []) as $product) {
                $id = (string) ($product['id'] ?? '');
                if ($id === '') continue;
                $candidates[$id] = $this->candidateSummary($product, $identifier);
            }
        }
        $result['product_candidates'] = array_values($candidates);
        if (count($candidates) === 0) {
            $result['product_catalog_blocked_reason'] ??= 'no_allegro_catalog_product_candidate_found';
            return $this->manualCompatibilityDiagnostics($client, $part, $result);
        }
        if (count($candidates) > 1) {
            $result['product_catalog_blocked_reason'] = 'multiple_allegro_catalog_product_candidates_manual_selection_required';
            return $this->manualCompatibilityDiagnostics($client, $part, $result);
        }

        $selected = array_key_first($candidates);
        $result['selected_product_id'] = $selected;
        $result['selection_confidence'] = 'single_catalog_candidate_from_local_identifier';
        $product = $client->product($selected);
        $result['api_diagnostics']['selected_product'] = $this->summary($product);
        $json = $product['json'] ?? [];
        $compatibility = data_get($json, 'compatibilityList.items', data_get($json, 'compatibilityList', []));
        $tecdoc = $json['tecdocSpecification'] ?? data_get($json, 'product.tecdocSpecification');
        $offerRequirements = $json['offerRequirements'] ?? data_get($json, 'product.offerRequirements', []);
        $result['product_compatibility_list_present'] = is_array($compatibility) && count($compatibility) > 0;
        $result['product_tecdoc_specification_present'] = is_array($tecdoc) ? $tecdoc !== [] : filled($tecdoc);
        $result['compatibility_items_count'] = is_array($compatibility) ? count($compatibility) : 0;
        $result['compatibility_sample'] = is_array($compatibility) ? array_slice(array_values($compatibility), 0, 5) : [];
        $result['offer_requirements'] = $offerRequirements;
        $result['offer_requirements_satisfied'] = $this->offerRequirementsSatisfied($offerRequirements);
        if (! $result['product_compatibility_list_present']) {
            $result['product_catalog_blocked_reason'] = 'selected_product_has_no_compatibility_list';
            return $this->manualCompatibilityDiagnostics($client, $part, $result);
        }
        if (! $result['offer_requirements_satisfied']) {
            $result['product_catalog_blocked_reason'] = 'selected_product_offer_requirements_not_satisfied';
            return $this->manualCompatibilityDiagnostics($client, $part, $result);
        }

        $preview = $publishPayloadPreview ?? [];
        data_set($preview, 'productSet.0.product.id', $selected);
        $preview['compatibilityList'] = ['type' => 'PRODUCT_BASED'];
        if ($result['product_tecdoc_specification_present']) $preview['tecdocSpecification'] = $tecdoc;
        $result['publish_payload_preview'] = $preview;
        $result['would_attach_product_based_compatibility'] = true;
        $result['would_attach_tecdoc_specification'] = $result['product_tecdoc_specification_present'];
        return $this->logAndReturn($result);
    }

    private function baseResult(Part $part, string $categoryId, array $identifiers, ?array $preview): array
    {
        return ['ok' => true, 'part_id' => $part->id, 'local_part_number' => $part->part_number, 'local_oe_numbers' => $this->split((string) $part->oem_number), 'manufacturer_number' => $part->manufacturer_code, 'ean' => data_get($part->legacy_payload, 'ean'), 'allegro_category_id' => $categoryId, 'category_supports_compatibility' => false, 'current_category_path' => null, 'current_category_ancestors' => [], 'supported_category_exact_match' => false, 'supported_category_parent_match' => false, 'matched_supported_category_id' => null, 'matched_supported_category_name' => null, 'matched_supported_category_input_type' => null, 'matched_supported_category_items_type' => null, 'searched_identifiers' => $identifiers, 'product_candidates' => [], 'selected_product_id' => null, 'selection_confidence' => 'none', 'blocked_reason' => null, 'product_catalog_blocked_reason' => null, 'compatible_products_phrase' => null, 'compatible_products_candidates_count' => 0, 'compatible_products_sample' => [], 'would_attach_manual_id_compatibility' => false, 'manual_compatibility_blocked_reason' => null, 'product_compatibility_list_present' => false, 'product_tecdoc_specification_present' => false, 'compatibility_items_count' => 0, 'compatibility_sample' => [], 'offer_requirements' => [], 'offer_requirements_satisfied' => false, 'publish_payload_preview' => $preview, 'would_attach_product_based_compatibility' => false, 'would_attach_tecdoc_specification' => false, 'our_offer_data_preserved' => ['images'=>true,'description'=>true,'price'=>true,'sku'=>true,'quantity'=>true,'condition'=>true], 'safety_flags' => ['dry_run'=>true,'no_marketplace_write'=>true,'no_stock'=>true,'no_price'=>true,'no_orders'=>true,'no_shipments'=>true,'no_ebay'=>true,'no_dhl'=>true], 'api_diagnostics' => ['endpoints' => ['/sale/compatibility-list/supported-categories','/sale/products','/sale/products/{product.id}','/sale/compatible-products?type=CAR&phrase={phrase}']]];
    }
    private function blocked(array $r, string $reason): array { $r['blocked_reason'] = $reason; return $r; }
    private function identifiers(Part $part): array { $rows=[]; foreach ([['part_number',$part->part_number],['manufacturer_number',$part->manufacturer_code],['sku',$part->sku],['ean',data_get($part->legacy_payload,'ean')]] as [$t,$v]) if (filled($v)) $rows[]=['type'=>$t,'value'=>(string)$v]; foreach ($this->split((string)$part->oem_number) as $v) $rows[]=['type'=>'oe_number','value'=>$v]; return array_values(array_unique($rows, SORT_REGULAR)); }
    private function split(string $v): array { return array_values(array_filter(array_map('trim', preg_split('/[,;|\n]+/', $v) ?: []))); }
    private function account(): ?MarketplaceAccount { return Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code','allegro_main')->first() : null; }
    private function categoryMapping(Part $part): ?MarketplaceCategoryMapping { return Schema::hasTable('marketplace_category_mappings') ? MarketplaceCategoryMapping::query()->where('local_category_id',$part->category_id)->whereIn('channel',['allegro_main','allegro'])->first() : null; }
    private function categorySupportMatch(array $payload, string $id): array
    {
        $rows = $payload['categories'] ?? $payload['supportedCategories'] ?? (array_is_list($payload) ? $payload : []);
        $supported = [];
        foreach (array_filter($rows, 'is_array') as $row) {
            $rowId = (string) data_get($row, 'id', data_get($row, 'category.id'));
            if ($rowId !== '') $supported[$rowId] = $row;
        }
        $path = $this->allegroCategoryPath($id);
        $ids = array_column($path, 'id');
        $exact = isset($supported[$id]);
        $matchedId = $exact ? $id : null;
        foreach ($ids as $ancestorId) if (! $exact && $ancestorId !== $id && isset($supported[$ancestorId])) { $matchedId = $ancestorId; break; }
        $row = $matchedId ? $supported[$matchedId] : [];
        return ['category_supports_compatibility' => (bool) $matchedId, 'current_category_path' => implode(' > ', array_filter(array_column($path, 'name'))) ?: null, 'current_category_ancestors' => array_values(array_filter($path, fn ($x) => $x['id'] !== $id)), 'supported_category_exact_match' => $exact, 'supported_category_parent_match' => ! $exact && (bool) $matchedId, 'matched_supported_category_id' => $matchedId, 'matched_supported_category_name' => data_get($row, 'name', data_get($row, 'category.name')), 'matched_supported_category_input_type' => data_get($row, 'inputType', data_get($row, 'input.type')), 'matched_supported_category_items_type' => data_get($row, 'itemsType', data_get($row, 'items.type'))];
    }
    private function allegroCategoryPath(string $id): array { if (! Schema::hasTable('marketplace_categories')) return [['id'=>$id,'name'=>$id]]; $byId = MarketplaceCategory::query()->whereIn('channel',['allegro_main','allegro'])->get(['external_category_id','parent_external_category_id','name','full_path'])->keyBy(fn($c)=>(string)$c->external_category_id); $path=[]; $cur=$byId[$id] ?? null; if (! $cur) return [['id'=>$id,'name'=>$id]]; for ($i=0; $cur && $i<20; $i++) { array_unshift($path, ['id'=>(string)$cur->external_category_id,'name'=>(string)($cur->name ?: $cur->external_category_id)]); $cur = filled($cur->parent_external_category_id) ? ($byId[(string)$cur->parent_external_category_id] ?? null) : null; } return $path; }
    private function manualCompatibilityDiagnostics(AllegroApiClient $client, Part $part, array $result): array { $phrase = $this->compatibleProductsPhrase($part); $result['compatible_products_phrase'] = $phrase; if ($phrase === null) return $this->logAndReturn($this->blocked($result, $result['product_catalog_blocked_reason'] ?: 'missing_vehicle_data_for_manual_compatibility_lookup')); $lookup = $client->compatibleProducts($phrase); $result['api_diagnostics']['compatible_products'] = $this->summary($lookup); $items = $lookup['items'] ?? []; $result['compatible_products_candidates_count'] = count($items); $result['compatible_products_sample'] = array_slice($items, 0, 10); if (count($items) === 1) { $result['would_attach_manual_id_compatibility'] = true; $result['manual_compatibility_blocked_reason'] = null; } else { $result['would_attach_manual_id_compatibility'] = false; $result['manual_compatibility_blocked_reason'] = count($items) === 0 ? 'no_compatible_products_candidate_found' : 'multiple_or_ambiguous_compatible_products_candidates'; } return $this->logAndReturn($this->blocked($result, $result['manual_compatibility_blocked_reason'] ?: ($result['product_catalog_blocked_reason'] ?? 'manual_id_compatibility_candidate_available_dry_run_only'))); }
    private function compatibleProductsPhrase(Part $part): ?string { $v = $part->car_id && $part->car ? $part->car->only(['make','model','model_variant','production_year','fuel_type','engine_capacity_cm3','engine_code']) : (is_array($part->vehicle_snapshot) ? $part->vehicle_snapshot : []); $capacity = isset($v['engine_capacity_cm3']) && is_numeric($v['engine_capacity_cm3']) ? number_format(((float)$v['engine_capacity_cm3'])/1000, 1, '.', '') : null; $parts = [$v['make'] ?? null, $v['model'] ?? null, $v['model_variant'] ?? null, $v['production_year'] ?? null, $capacity, $v['fuel_type'] ?? null, $v['engine_code'] ?? null]; $phrase = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, fn($x)=>filled($x))))); return $phrase !== '' ? $phrase : null; }
    private function candidateSummary(array $p, array $i): array { return ['id'=>$p['id'] ?? null, 'name'=>$p['name'] ?? null, 'category_id'=>data_get($p,'category.id'), 'producer'=>$p['producer'] ?? data_get($p,'parameters.producer'), 'matched_identifier'=>$i, 'status'=>$p['status'] ?? null]; }
    private function offerRequirementsSatisfied(mixed $r): bool { if (! is_array($r) || $r === []) return true; foreach (['errors','missing','requiredParameters','requirements'] as $k) if (is_array($r[$k] ?? null) && count($r[$k]) > 0) return false; return true; }
    private function summary(array $r): array { return ['ok'=>$r['ok'] ?? false, 'http_status'=>$r['http_status'] ?? null, 'request_id'=>$r['request_id'] ?? null, 'error'=>$r['error'] ?? null]; }
    private function logAndReturn(array $r): array { MarketplaceSyncLog::query()->create(['marketplace'=>'allegro','part_id'=>$r['part_id'],'action'=>'allegro_compatibility_dry_run','status'=>$r['blocked_reason'] ? 'blocked' : 'ok','message'=>'Allegro compatibility dry-run only; no marketplace write.','payload'=>$r,'created_at'=>now()]); return $r; }
}
