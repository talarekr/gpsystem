<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Support\Facades\Schema;

class AllegroCompatibilityMappingService
{
    public function dryRun(Part $part, ?array $publishPayloadPreview = null): array
    {
        $part->loadMissing(['category', 'images', 'marketplaceListings']);
        $mapping = $this->categoryMapping($part);
        $categoryId = (string) ($mapping?->external_category_id ?? '');
        $account = $this->account();
        $identifiers = $this->identifiers($part);

        $result = $this->baseResult($part, $categoryId, $identifiers, $publishPayloadPreview);

        if (! $account) return $this->logAndReturn($this->blocked($result, 'missing_allegro_account'));
        if ($categoryId === '') return $this->logAndReturn($this->blocked($result, 'missing_allegro_category_mapping'));
        if ($identifiers === []) return $this->logAndReturn($this->blocked($result, 'missing_part_number_oe_manufacturer_number_ean_or_sku'));

        $client = new AllegroApiClient('allegro_main', $account);
        $supported = $client->compatibilitySupportedCategories();
        $result['api_diagnostics']['supported_categories'] = $this->summary($supported);
        $result['category_supports_compatibility'] = $this->categorySupports($supported['json'] ?? [], $categoryId);
        if (! $result['category_supports_compatibility']) return $this->logAndReturn($this->blocked($result, 'allegro_category_does_not_support_compatibility_list'));

        $candidates = [];
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
        if (count($candidates) === 0) return $this->logAndReturn($this->blocked($result, 'no_allegro_catalog_product_candidate_found'));
        if (count($candidates) > 1) return $this->logAndReturn($this->blocked($result, 'multiple_allegro_catalog_product_candidates_manual_selection_required'));

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
        if (! $result['product_compatibility_list_present']) return $this->logAndReturn($this->blocked($result, 'selected_product_has_no_compatibility_list'));
        if (! $result['offer_requirements_satisfied']) return $this->logAndReturn($this->blocked($result, 'selected_product_offer_requirements_not_satisfied'));

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
        return ['ok' => true, 'part_id' => $part->id, 'local_part_number' => $part->part_number, 'local_oe_numbers' => $this->split((string) $part->oem_number), 'manufacturer_number' => $part->manufacturer_code, 'ean' => data_get($part->legacy_payload, 'ean'), 'allegro_category_id' => $categoryId, 'category_supports_compatibility' => false, 'searched_identifiers' => $identifiers, 'product_candidates' => [], 'selected_product_id' => null, 'selection_confidence' => 'none', 'blocked_reason' => null, 'product_compatibility_list_present' => false, 'product_tecdoc_specification_present' => false, 'compatibility_items_count' => 0, 'compatibility_sample' => [], 'offer_requirements' => [], 'offer_requirements_satisfied' => false, 'publish_payload_preview' => $preview, 'would_attach_product_based_compatibility' => false, 'would_attach_tecdoc_specification' => false, 'our_offer_data_preserved' => ['images'=>true,'description'=>true,'price'=>true,'sku'=>true,'quantity'=>true,'condition'=>true], 'safety_flags' => ['dry_run'=>true,'no_marketplace_write'=>true,'no_stock'=>true,'no_price'=>true,'no_orders'=>true,'no_shipments'=>true,'no_ebay'=>true,'no_dhl'=>true], 'api_diagnostics' => ['endpoints' => ['/sale/compatibility-list/supported-categories','/sale/products','/sale/products/{product.id}']]];
    }
    private function blocked(array $r, string $reason): array { $r['blocked_reason'] = $reason; return $r; }
    private function identifiers(Part $part): array { $rows=[]; foreach ([['part_number',$part->part_number],['manufacturer_number',$part->manufacturer_code],['sku',$part->sku],['ean',data_get($part->legacy_payload,'ean')]] as [$t,$v]) if (filled($v)) $rows[]=['type'=>$t,'value'=>(string)$v]; foreach ($this->split((string)$part->oem_number) as $v) $rows[]=['type'=>'oe_number','value'=>$v]; return array_values(array_unique($rows, SORT_REGULAR)); }
    private function split(string $v): array { return array_values(array_filter(array_map('trim', preg_split('/[,;|\n]+/', $v) ?: []))); }
    private function account(): ?MarketplaceAccount { return Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code','allegro_main')->first() : null; }
    private function categoryMapping(Part $part): ?MarketplaceCategoryMapping { return Schema::hasTable('marketplace_category_mappings') ? MarketplaceCategoryMapping::query()->where('local_category_id',$part->category_id)->whereIn('channel',['allegro_main','allegro'])->first() : null; }
    private function categorySupports(array $payload, string $id): bool { $rows = $payload['categories'] ?? $payload['supportedCategories'] ?? (array_is_list($payload) ? $payload : []); foreach ($rows as $row) if ((string) data_get($row, 'id', data_get($row, 'category.id')) === $id) return true; return false; }
    private function candidateSummary(array $p, array $i): array { return ['id'=>$p['id'] ?? null, 'name'=>$p['name'] ?? null, 'category_id'=>data_get($p,'category.id'), 'producer'=>$p['producer'] ?? data_get($p,'parameters.producer'), 'matched_identifier'=>$i, 'status'=>$p['status'] ?? null]; }
    private function offerRequirementsSatisfied(mixed $r): bool { if (! is_array($r) || $r === []) return true; foreach (['errors','missing','requiredParameters','requirements'] as $k) if (is_array($r[$k] ?? null) && count($r[$k]) > 0) return false; return true; }
    private function summary(array $r): array { return ['ok'=>$r['ok'] ?? false, 'http_status'=>$r['http_status'] ?? null, 'request_id'=>$r['request_id'] ?? null, 'error'=>$r['error'] ?? null]; }
    private function logAndReturn(array $r): array { MarketplaceSyncLog::query()->create(['marketplace'=>'allegro','part_id'=>$r['part_id'],'action'=>'allegro_compatibility_dry_run','status'=>$r['blocked_reason'] ? 'blocked' : 'ok','message'=>'Allegro compatibility dry-run only; no marketplace write.','payload'=>$r,'created_at'=>now()]); return $r; }
}
