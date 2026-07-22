<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\PriceSync\EbayDePriceSyncAdapter;
use App\Services\Marketplace\PriceSync\PriceNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class EbayPriceSyncDiagnoseController extends Controller
{
    public function __invoke(Request $request, Part $part, EbayDePriceSyncAdapter $adapter, PriceNormalizer $normalizer): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole([UserRole::OwnerAdmin->value]), 403);
        abort_unless((int) $part->id === 8212, 404);

        $listing = MarketplaceListing::query()->with('account')->where('part_id', $part->id)->where('marketplace', 'ebay_de')->first();
        if (! $listing) {
            return response()->json(['read_only'=>true,'no_mutation'=>true,'marketplace_write'=>false,'blockers'=>['missing_ebay_de_listing']]);
        }

        $sku = (string) ($listing->sku ?: data_get($listing->raw_payload, 'sku'));
        $offerId = (string) $listing->external_offer_id;
        $base = rtrim((string) $listing->account?->api_base_url, '/');
        $token = (string) (((array) $listing->account?->api_credentials)['access_token'] ?? '');
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => 'EBAY_DE'];

        $offer = Http::withToken($token)->withHeaders($headers)->acceptJson()->get($base.'/sell/inventory/v1/offer/'.rawurlencode($offerId));
        $offerBySku = Http::withToken($token)->withHeaders($headers)->acceptJson()->get($base.'/sell/inventory/v1/offer', ['sku'=>$sku, 'marketplace_id'=>'EBAY_DE']);
        $item = Http::withToken($token)->withHeaders($headers)->acceptJson()->get($base.'/sell/inventory/v1/inventory_item/'.rawurlencode($sku));

        $oj = is_array($offer->json()) ? $offer->json() : [];
        $sj = is_array($offerBySku->json()) ? $offerBySku->json() : [];
        $ij = is_array($item->json()) ? $item->json() : [];
        $offers = (array) ($sj['offers'] ?? $sj['offer'] ?? []);
        $offerIds = array_values(array_filter(array_map(fn ($o) => (string) data_get($o, 'offerId'), $offers)));
        $qty = $this->qty($oj);
        $planned = $adapter->plannedBulkPayload($sku, $offerId, '35.80', 'EUR', (int) ($qty ?? 1));
        $legacy = $adapter->currentLegacyBulkPayloadShape();
        $required = $adapter->bulkPayloadShape();

        return response()->json([
            'read_only'=>true,'no_mutation'=>true,'marketplace_write'=>false,'external_requests'=>true,'external_methods_used'=>['GET'],
            'local_listing'=>$listing->only(['id','part_id','marketplace','marketplace_account_id','external_offer_id','external_inventory_id','sku','price','currency','status']),
            'offer_id'=>$offerId,'sku'=>$sku,'marketplace'=>'EBAY_DE',
            'get_offer_by_id'=>$this->summarize($offer->status(), $oj, $normalizer),
            'get_offers_by_sku'=>['http_status'=>$offerBySku->status(),'offers'=>array_map(fn($o)=>$this->offerSummary((array)$o,$normalizer), $offers),'raw_top_level_keys'=>array_slice(array_keys($sj),0,30)],
            'inventory_item'=>['http_status'=>$item->status(),'sku'=>data_get($ij,'sku'),'quantity'=>$this->qty($ij),'raw_top_level_keys'=>array_slice(array_keys($ij),0,30),'sanitized'=>$ij],
            'found_offer_ids'=>$offerIds,
            'mapping'=>['offer_id_belongs_to_sku'=>data_get($oj,'sku')===$sku,'offer_marketplace_is_ebay_de'=>data_get($oj,'marketplaceId')==='EBAY_DE','offer_is_published'=>in_array(data_get($oj,'status'), ['PUBLISHED'], true),'unique_sku_offer_mapping'=>count($offerIds)===1 && in_array($offerId,$offerIds,true)],
            'planned_bulk_payload'=>$planned,'exact_json_shape'=>$required,'previous_payload_shape'=>$legacy,'current_payload_differs_from_required'=>$legacy !== $required,
            'recommended_write_method'=>'A: corrected bulk_update_price_quantity with offers[].offerId price/availableQuantity, followed by read-after-write by offerId and by SKU',
            'write_options'=>['A_corrected_bulk'=>['risk'=>'low; targets specific offerId under SKU','quantity_guard'=>'reuse pre-read availableQuantity','publication_guard'=>'no publication field is sent','read_after_write'=>['GET offer by ID','GET offers by SKU']], 'B_guarded_offer_put'=>['risk'=>'medium; PUT may require full offer payload and can accidentally revise unrelated fields','quantity_guard'=>'pre-read full offer and diff guard availableQuantity','publication_guard'=>'pre-read full offer and never call publish/withdraw','read_after_write'=>['GET offer by ID']]],
            'blockers'=>count($offerIds)>1?['multiple_offers_for_sku_require_offerId_targeting']:[],
        ]);
    }
    private function summarize(int $status, array $j, PriceNormalizer $n): array { return ['http_status'=>$status]+$this->offerSummary($j,$n)+['sanitized'=>$j]; }
    private function offerSummary(array $j, PriceNormalizer $n): array { return ['offerId'=>data_get($j,'offerId'),'sku'=>data_get($j,'sku'),'marketplaceId'=>data_get($j,'marketplaceId'),'price'=>$n->normalize(data_get($j,'pricingSummary.price.value')),'currency'=>data_get($j,'pricingSummary.price.currency'),'quantity'=>$this->qty($j),'publication_status'=>data_get($j,'status') ?? data_get($j,'listing.status') ?? data_get($j,'publicationStatus')]; }
    private function qty(array $j): ?int { $q=data_get($j,'availableQuantity') ?? data_get($j,'shipToLocationAvailability.quantity') ?? data_get($j,'quantityLimitPerBuyer'); return is_numeric($q) ? (int) $q : null; }
}
