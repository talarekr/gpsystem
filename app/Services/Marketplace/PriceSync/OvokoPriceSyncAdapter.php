<?php
namespace App\Services\Marketplace\PriceSync;
use App\Models\MarketplaceListing;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class OvokoPriceSyncAdapter implements MarketplacePriceSyncAdapter
{
    public function sync(MarketplaceListing $listing, array $price): array
    {
        $partId = (string) ($listing->external_offer_id ?: $listing->external_listing_id);
        $base = rtrim((string) $listing->account?->api_base_url, '/');
        $auth = Arr::only((array) $listing->account?->api_credentials, ['username','password','user_token']);
        $payload = $auth + ['part_id' => $partId, 'price' => $price['marketplace_price'], 'original_currency' => 'PLN'];
        $write = Http::asForm()->acceptJson()->post($base.'/crm/updatePart', $payload);
        $body = is_array($write->json()) ? $write->json() : [];
        $api = $body['status_code'] ?? null;
        $accepted = $write->successful() && ($api === 'R200' || $api === 200);
        $safeReq = ['part_id'=>$partId,'price'=>$price['marketplace_price'],'original_currency'=>'PLN'];
        if (! $accepted) return ['status'=>'error','http_status'=>$write->status(),'api_business_status'=>$api,'endpoint'=>'POST /crm/updatePart','request_summary'=>$safeReq,'response_summary'=>$this->safe($body),'final_success'=>false,'blocker'=>'ovoko_business_status_error'];
        $read = Http::asForm()->acceptJson()->post($base.'/get/part/'.rawurlencode($partId), $auth);
        $raw = is_array($read->json()) ? $read->json() : [];
        $row = $this->partRow($raw);
        $confirmed = $this->confirmedPlnPrice($row);
        if ($confirmed === null) return ['status'=>'write_accepted_unverified','http_status'=>$write->status(),'api_business_status'=>$api,'endpoint'=>'POST /crm/updatePart','read_after_write_endpoint'=>'POST /get/part/{id}','request_summary'=>$safeReq,'response_summary'=>$this->safe($body),'read_after_write'=>$this->safe($raw),'final_success'=>false,'blocker'=>'write_accepted_unverified'];
        $ok = $confirmed === app(PriceNormalizer::class)->normalize($price['marketplace_price']);
        return ['status'=>$ok?'success':'error','http_status'=>$write->status(),'api_business_status'=>$api,'endpoint'=>'POST /crm/updatePart','read_after_write_endpoint'=>'POST /get/part/{id}','request_summary'=>$safeReq,'response_summary'=>$this->safe($body),'read_after_write'=>$this->safe($raw)+['confirmed_pln_price'=>$confirmed],'remote_confirmed_price'=>$ok?$confirmed:null,'final_success'=>$ok,'blocker'=>$ok?null:'ovoko_read_after_write_price_mismatch'];
    }
    private function partRow(array $raw): array { $d=$raw['data']??$raw['part']??$raw; return is_array($d) && array_is_list($d) ? (array)($d[0]??[]) : (array)$d; }
    private function confirmedPlnPrice(array $row): ?string { $n=app(PriceNormalizer::class); if (($row['original_currency']??null)==='PLN' && isset($row['original_price'])) return $n->normalize($row['original_price']); if (($row['currency']??null)==='PLN' && isset($row['price'])) return $n->normalize($row['price']); return null; }
    private function safe(array $payload): array { return collect($payload)->except(['username','password','user_token','token','authorization'])->map(fn($v)=>is_array($v)?$this->safe($v):$v)->take(30)->all(); }
}
