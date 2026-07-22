<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AllegroCompatibilitySuggestionsService
{
    public const META_PATH = 'marketplace_prepare_results.allegro.compatibility';
    public const LIMIT = 10000;

    public function fetchAndStoreForPreparedPayload(Part $part, array $preparedPayload): array
    {
        $ctx = $this->context($part, $preparedPayload);
        if ($ctx['product_id'] === '') return $this->store($part, $this->metadata('no_product_id', 'none', $ctx));

        $account = $this->account();
        if (! $account) return $this->store($part, $this->metadata('fetch_error', 'none', $ctx, [], 'missing_allegro_account'));

        $supported = $this->categorySupports($account, $ctx['category_id']);
        if ($supported === false) return $this->store($part, $this->metadata('category_unsupported', 'none', $ctx));

        $client = new AllegroApiClient('allegro_main', $account);
        try {
            $result = $client->compatibilitySuggestionsByProductId($ctx['product_id']);
        } catch (\Throwable $e) {
            return $this->store($part, $this->metadata('fetch_error', 'none', $ctx, [], $e->getMessage()), 'error');
        }

        if (($result['ok'] ?? false) !== true) {
            return $this->store($part, $this->metadata('fetch_error', 'none', $ctx, [], (string) ($result['error'] ?? 'Allegro compatibility suggestions failed'), $result), 'error');
        }

        $items = $this->extractItems((array) ($result['json'] ?? []));
        $returned = count($items);
        $list = $this->normalizeList($items);
        $status = count($list['items']) > 0 ? 'suggestions_found' : 'no_suggestions';
        return $this->store($part, $this->metadata($status, $status === 'suggestions_found' ? 'product_suggestions' : 'none', $ctx, $list, null, $result, $returned), $status === 'suggestions_found' ? 'success' : 'no_suggestions');
    }

    public function audit(Part $part, array $preparedPayload): array
    {
        $ctx = $this->context($part, $preparedPayload); $meta = (array) data_get((array) $part->review_metadata, self::META_PATH, []);
        return ['part_id'=>$part->id,'main_part_code'=>$part->part_number ?: $part->sku,'additional_part_codes'=>$part->additional_part_codes ?? [],'category_id'=>$ctx['category_id'] ?: null,'product_id'=>$ctx['product_id'] ?: null,'local_metadata'=>$meta,'status'=>$meta['status'] ?? 'not_checked','source'=>$meta['source'] ?? 'none','item_count'=>count((array) data_get($meta,'compatibilityList.items',[])),'canonical_hash'=>$meta['canonical_hash'] ?? null,'fetched_at'=>$meta['fetched_at'] ?? null,'product_category_still_match'=>$this->matches($meta,$ctx),'builder_will_include_compatibilityList'=>$this->publishableCompatibilityList($part,$preparedPayload) !== null,'read_only'=>true,'no_mutation'=>true,'marketplace_write'=>false,'external_requests'=>false,'fallback_behavior'=>'publish_without_compatibility'];
    }

    public function preview(Part $part, array $preparedPayload): array
    {
        $ctx = $this->context($part, $preparedPayload); $base = ['part_id'=>$part->id,'product_id'=>$ctx['product_id'] ?: null,'category_id'=>$ctx['category_id'] ?: null,'endpoint'=>'GET /sale/compatibility-list-suggestions?product.id={productId}','read_only'=>true,'no_mutation'=>true,'marketplace_write'=>false,'external_requests'=>true,'fallback_behavior'=>'publish_without_compatibility'];
        if ($ctx['product_id'] === '') return $base + ['result_status'=>'no_product_id','returned_items'=>[],'returned_count'=>0,'deduplicated_count'=>0,'planned_stored_count'=>0,'planned_canonical_hash'=>null,'planned_compatibilityList'=>['type'=>'MANUAL','items'=>[]]];
        $account = $this->account(); if (! $account) return $base + ['result_status'=>'fetch_error','last_error'=>'missing_allegro_account'];
        try { $r = (new AllegroApiClient('allegro_main', $account))->compatibilitySuggestionsByProductId($ctx['product_id']); } catch (\Throwable $e) { return $base + ['result_status'=>'fetch_error','last_error'=>$e->getMessage()]; }
        $items = $this->extractItems((array) ($r['json'] ?? [])); $list = $this->normalizeList($items);
        return $base + ['http_status'=>$r['http_status'] ?? null,'request_id'=>$r['request_id'] ?? null,'returned_items'=>$items,'returned_count'=>count($items),'deduplicated_count'=>count($list['items']),'planned_stored_count'=>count($list['items']),'planned_canonical_hash'=>$this->hash($list),'planned_compatibilityList'=>$list,'result_status'=>count($list['items']) ? 'suggestions_found' : 'no_suggestions'];
    }

    public function publishableCompatibilityList(Part $part, array $payload): ?array
    { $ctx=$this->context($part,$payload); $meta=(array)data_get((array)$part->review_metadata,self::META_PATH,[]); $list=(array)($meta['compatibilityList']??[]); return ($this->matches($meta,$ctx) && in_array($meta['status']??null,['suggestions_found','included_in_publish','confirmed','applied_unverified'],true) && count((array)($list['items']??[]))) ? ['type'=>(string)($list['type']??'MANUAL'),'items'=>array_values((array)$list['items'])] : null; }

    public function normalizeList(array $items): array { $out=[]; $seen=[]; foreach($items as $item){ if(!is_array($item)) continue; $n=$this->canonicalize($item); if($n===[]) continue; $key=json_encode($n, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); if(isset($seen[$key])) continue; $seen[$key]=true; $out[]=$n; if(count($out)>=self::LIMIT) break; } return ['type'=>'MANUAL','items'=>$out]; }
    public function hash(array $list): string { return hash('sha256', json_encode($list, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
    private function canonicalize(array $item): array { $type=strtoupper((string)($item['type']??'')); if($type==='ID' && filled($item['id']??null)) return array_filter(['type'=>'ID','id'=>(string)$item['id'],'additionalInfo'=>array_values((array)($item['additionalInfo']??[]))],fn($v)=>$v!==null); if($type!=='' || filled($item['text']??$item['name']??null)){ ksort($item); return $item; } return []; }
    private function extractItems(array $json): array { $items=data_get($json,'compatibilityList.items',data_get($json,'items',data_get($json,'compatibleProducts',[]))); return array_values(array_filter(is_array($items)?$items:[], 'is_array')); }
    private function context(Part $part, array $payload): array { $productId=(string)data_get($payload,'productSet.0.product.id',''); $category=(string)($payload['category_id']??data_get($payload,'category.id','')); return ['product_id'=>trim($productId),'category_id'=>trim($category),'offer_id'=>(string)data_get($payload,'id','')]; }
    private function metadata(string $status,string $source,array $ctx,array $list=[],?string $err=null,array $api=[],?int $returned=null): array { $list=$list?:['type'=>'MANUAL','items'=>[]]; $stored=count($list['items']); return ['status'=>$status,'source'=>$source,'product_id'=>$ctx['product_id']?:null,'category_id'=>$ctx['category_id']?:null,'offer_id'=>$ctx['offer_id']?:null,'compatibilityList'=>$list,'returned_count'=>$returned ?? $stored,'stored_count'=>$stored,'canonical_hash'=>$this->hash($list),'fetched_at'=>now()->toISOString(),'last_error'=>$err,'request_id'=>$api['request_id']??null,'http_status'=>$api['http_status']??null,'warning'=>($returned!==null && $returned>$stored)?'allegro_compatibility_items_truncated_to_limit':null]; }
    private function store(Part $part,array $meta,string $logStatus='skipped'): array { $current=(array)data_get((array)$part->review_metadata,self::META_PATH,[]); $metadata=is_array($part->review_metadata)?$part->review_metadata:[]; data_set($metadata,self::META_PATH,$meta); $part->forceFill(['review_metadata'=>$metadata])->save(); if(($current['canonical_hash']??null)!==($meta['canonical_hash']??null) || ($current['status']??null)!==($meta['status']??null)) MarketplaceSyncLog::query()->create(['marketplace'=>'allegro','part_id'=>$part->id,'action'=>'allegro_compatibility_suggestions','status'=>$logStatus,'http_status'=>$meta['http_status']??null,'request_id'=>$meta['request_id']??null,'message'=>'Allegro compatibility suggestions '.$meta['status'],'payload'=>Arr::only($meta,['product_id','category_id','source','returned_count','stored_count','canonical_hash','http_status','request_id','warning','last_error']),'created_at'=>now()]); return ['ok'=>true,'compatibility'=>$meta,'message'=>$this->message($meta)]; }
    private function message(array $m): string { return match($m['status']??'') {'suggestions_found'=>'Allegro znalazło kompatybilność dla '.(int)($m['stored_count']??0).' pojazdów.','no_product_id'=>'Nie udało się pobrać kompatybilności bez product.id. Oferta może zostać wystawiona bez sekcji Pasuje do.','no_suggestions'=>'Allegro nie zwróciło kompatybilnych pojazdów. Oferta może zostać wystawiona bez sekcji Pasuje do.', default=>'Nie udało się pobrać kompatybilności Allegro. Oferta może zostać wystawiona bez tej sekcji.'}; }
    private function account(): ?MarketplaceAccount { return Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code','allegro_main')->first() : null; }
    private function categorySupports(MarketplaceAccount $a,string $id): ?bool { if($id==='') return null; $r=Cache::remember('allegro_compatibility_supported_categories', now()->addHours(12), fn()=>(new AllegroApiClient('allegro_main',$a))->supportedCompatibilityCategories()); if(($r['ok']??false)!==true) return null; $rows=(array)(($r['json']['categories']??$r['json']['supportedCategories']??(array_is_list($r['json']??[])?$r['json']:[]))); foreach($rows as $row){ if((string)data_get($row,'id',data_get($row,'category.id'))===$id) return true; } return false; }
    private function matches(array $meta,array $ctx): bool { return ($meta['product_id']??null)===$ctx['product_id'] && ($meta['category_id']??null)===$ctx['category_id']; }
}
