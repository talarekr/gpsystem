<?php
namespace App\Services\Marketplace\PriceSync;
use App\Models\MarketplaceListing;
use Illuminate\Support\Facades\Http;

class EbayDePriceSyncAdapter implements MarketplacePriceSyncAdapter
{
    public function sync(MarketplaceListing $listing, array $price): array
    {
        $class = $this->classify($listing); if ($class['type'] === 'legacy') return ['status'=>'skipped','blocker'=>'ebay_legacy_price_sync_not_supported','final_success'=>false];
        $offerId=(string)$listing->external_offer_id; $sku=(string)$class['sku']; $base=rtrim((string)$listing->account?->api_base_url,'/'); $token=(string)(((array)$listing->account?->api_credentials)['access_token']??''); $headers=['X-EBAY-C-MARKETPLACE-ID'=>'EBAY_DE'];
        $before=Http::withToken($token)->withHeaders($headers)->acceptJson()->get($base.'/sell/inventory/v1/offer/'.rawurlencode($offerId)); $bj=is_array($before->json())?$before->json():[];
        if (!$before->successful()) return $this->err('ebay_offer_pre_read_failed',$before->status(),null,$bj,[],null,null,null,null);
        $qty=$this->qty($bj); $pub=$bj['status']??$bj['listing']['status']??$bj['publicationStatus']??null; $old=app(PriceNormalizer::class)->normalize(data_get($bj,'pricingSummary.price.value')); $cur=data_get($bj,'pricingSummary.price.currency');
        if ($qty===null) return $this->err('ebay_remote_quantity_missing',$before->status(),null,$bj,[],null,null,$pub,$pub);
        $payload=['requests'=>[['sku'=>$sku,'pricingSummary'=>['price'=>['value'=>$price['marketplace_price'],'currency'=>'EUR']],'shipToLocationAvailability'=>['quantity'=>$qty]]]];
        $write=Http::withToken($token)->withHeaders($headers)->acceptJson()->asJson()->post($base.'/sell/inventory/v1/bulk_update_price_quantity',$payload); $wj=is_array($write->json())?$write->json():[];
        if (!$write->successful() || isset($wj['errors'])) return $this->err('ebay_bulk_update_price_quantity_error',$write->status(),$write->header('x-ebay-c-request-id')?:$write->header('x-ebay-correlation-id'),$wj,$payload,$old,$qty,$pub,$pub);
        $after=Http::withToken($token)->withHeaders($headers)->acceptJson()->get($base.'/sell/inventory/v1/offer/'.rawurlencode($offerId)); $aj=is_array($after->json())?$after->json():[]; $afterQty=$this->qty($aj); $afterPub=$aj['status']??$aj['listing']['status']??$aj['publicationStatus']??null; $new=app(PriceNormalizer::class)->normalize(data_get($aj,'pricingSummary.price.value')); $currency=data_get($aj,'pricingSummary.price.currency');
        $ok=$after->successful() && $new===app(PriceNormalizer::class)->normalize($price['marketplace_price']) && $currency==='EUR' && $afterQty===$qty && $afterPub===$pub;
        return ['status'=>$ok?'success':'error','http_status'=>$write->status(),'endpoint'=>'POST /sell/inventory/v1/bulk_update_price_quantity','read_after_write_endpoint'=>'GET /sell/inventory/v1/offer/{offerId}','request_summary'=>['sku'=>$sku,'offer_id'=>$offerId,'price'=>$price['marketplace_price'],'currency'=>'EUR','quantity'=>$qty],'response_summary'=>$this->safe($wj),'read_after_write'=>['price'=>$new,'currency'=>$currency,'remote_quantity_after'=>$afterQty,'publication_status_after'=>$afterPub],'remote_confirmed_price'=>$ok?$new:null,'final_success'=>$ok,'blocker'=>$ok?null:'ebay_read_after_write_guard_mismatch','remote_quantity_before'=>$qty,'remote_quantity_after'=>$afterQty,'publication_status_before'=>$pub,'publication_status_after'=>$afterPub,'quantity_unchanged'=>$afterQty===$qty,'publication_unchanged'=>$afterPub===$pub,'old_remote_price'=>$old,'new_requested_price'=>$price['marketplace_price']];
    }
    public function classify(MarketplaceListing $l): array { $sku=$l->sku?:data_get($l->raw_payload,'sku'); $inv=$l->external_inventory_id?:data_get($l->raw_payload,'inventory_item_group_key'); return ($l->marketplace==='ebay_de'&&filled($l->external_offer_id)&&filled($sku)&&filled($inv))?['type'=>'inventory_api','sku'=>$sku,'inventory_id'=>$inv,'price_only_write_supported'=>true,'quantity_mutation_risk'=>false]:['type'=>'legacy','sku'=>$sku,'inventory_id'=>$inv,'price_only_write_supported'=>false,'quantity_mutation_risk'=>true]; }
    private function qty(array $j): ?int { $q=data_get($j,'availableQuantity')??data_get($j,'quantityLimitPerBuyer')??data_get($j,'shipToLocationAvailability.quantity'); return is_numeric($q)?(int)$q:null; }
    private function safe(array $j): array { return ['errors'=>array_map(fn($e)=>array_intersect_key((array)$e,array_flip(['errorId','domain','category','message','longMessage','parameters','inputRefIds','outputRefIds'])),(array)($j['errors']??[])),'warnings'=>array_map(fn($e)=>array_intersect_key((array)$e,array_flip(['errorId','domain','category','message','longMessage','parameters','inputRefIds','outputRefIds'])),(array)($j['warnings']??[])),'top_level_keys'=>array_slice(array_keys($j),0,20)]; }
    private function err($b,$http,$rid,$body,$payload,$old,$qty,$pb,$pa): array { return ['status'=>'error','http_status'=>$http,'request_id'=>$rid,'endpoint'=>'POST /sell/inventory/v1/bulk_update_price_quantity','request_summary'=>$payload,'response_summary'=>$this->safe($body),'final_success'=>false,'blocker'=>$b,'old_remote_price'=>$old,'remote_quantity_before'=>$qty,'remote_quantity_after'=>$qty,'publication_status_before'=>$pb,'publication_status_after'=>$pa,'quantity_unchanged'=>true,'publication_unchanged'=>true]; }
}
