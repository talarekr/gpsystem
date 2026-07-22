<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\PriceSync\PriceNormalizer;
use App\Support\Marketplace\AllegroUserAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class Part8212PriceSyncRemoteCheckController extends Controller
{
    public function __invoke(Part $part, PriceNormalizer $normalizer): JsonResponse
    {
        abort_unless((int) $part->id === 8212, 404);
        $listings = MarketplaceListing::query()->with('account')->where('part_id', $part->id)->get()->keyBy('marketplace');
        return response()->json(['part_id'=>$part->id,'external_requests'=>true,'read_only'=>true,'no_mutation'=>true,'marketplace_write'=>false,'checks'=>[
            'allegro'=>$this->allegro($listings->get('allegro') ?: $listings->get('allegro_main'), $normalizer),
            'ovoko'=>$this->ovoko($listings->get('ovoko'), $normalizer),
            'ebay_de'=>$this->ebay($listings->get('ebay_de'), $normalizer),
        ]]);
    }
    private function allegro(?MarketplaceListing $l, PriceNormalizer $n): array { if(!$l) return ['ok'=>false,'blocker'=>'missing_listing']; $url=rtrim((string)$l->account?->api_base_url,'/').'/sale/product-offers/'.rawurlencode((string)$l->external_offer_id); $r=AllegroUserAgent::request()->withToken((string)(((array)$l->account?->api_credentials)['access_token']??''))->accept('application/vnd.allegro.public.v1+json')->get($url); $j=is_array($r->json())?$r->json():[]; return ['endpoint'=>'GET /sale/product-offers/{offerId}','http_status'=>$r->status(),'current_remote_price'=>$n->normalize(data_get($j,'sellingMode.price.amount')),'currency'=>data_get($j,'sellingMode.price.currency'),'request_id'=>$r->header('trace-id')?:$r->header('x-request-id')]; }
    private function ovoko(?MarketplaceListing $l, PriceNormalizer $n): array { if(!$l) return ['ok'=>false,'blocker'=>'missing_listing']; $auth=Arr::only((array)$l->account?->api_credentials,['username','password','user_token']); $id=(string)($l->external_offer_id?:$l->external_listing_id); $r=Http::asForm()->acceptJson()->post(rtrim((string)$l->account?->api_base_url,'/').'/get/part/'.rawurlencode($id),$auth); $j=is_array($r->json())?$r->json():[]; $row=(array)data_get($j,'list.0.0',(array)data_get($j,'data',[])); return ['endpoint'=>'POST /get/part/{id}','http_status'=>$r->status(),'current_remote_price'=>strtoupper((string)($row['original_currency']??''))==='PLN'?$n->normalize($row['original_price']??null):null,'currency'=>$row['original_currency']??null,'price_matches'=>$n->normalize($row['original_price']??null)==='140.00']; }
    private function ebay(?MarketplaceListing $l, PriceNormalizer $n): array { if(!$l) return ['ok'=>false,'blocker'=>'missing_listing']; $url=rtrim((string)$l->account?->api_base_url,'/').'/sell/inventory/v1/offer/'.rawurlencode((string)$l->external_offer_id); $r=Http::withToken((string)(((array)$l->account?->api_credentials)['access_token']??''))->withHeaders(['X-EBAY-C-MARKETPLACE-ID'=>'EBAY_DE'])->acceptJson()->get($url); $j=is_array($r->json())?$r->json():[]; $price=$n->normalize(data_get($j,'pricingSummary.price.value')); $qty=data_get($j,'availableQuantity')??data_get($j,'shipToLocationAvailability.quantity'); $pub=$j['status']??$j['listing']['status']??$j['publicationStatus']??null; return ['endpoint'=>'GET /sell/inventory/v1/offer/{offerId}','offer_id'=>'211951318011','http_status'=>$r->status(),'current_remote_price'=>$price,'currency'=>data_get($j,'pricingSummary.price.currency'),'quantity'=>$qty,'publication_status'=>$pub,'expected_price'=>'36.09','price_matches'=>$price==='36.09','quantity_matches_before'=>(int)$qty===1,'publication_matches_before'=>$pub==='PUBLISHED','request_id'=>$r->header('x-ebay-c-request-id')?:$r->header('x-ebay-correlation-id')]; }
}
