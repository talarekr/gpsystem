<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AllegroCompatibilitySuggestionsService
{
    public const META_PATH = 'marketplace_prepare_results.allegro.compatibility';

    public function fetchAndStoreForPreparedPayload(Part $part, array $preparedPayload): array
    {
        $ctx = $this->context($part, $preparedPayload);
        if ($ctx['product_id'] === '') return $this->store($part, $this->metadata('no_product_id', 'none', $ctx));

        $account = $this->account();
        if (! $account) return $this->store($part, $this->metadata('fetch_error', 'none', $ctx, [], 'missing_allegro_account'));

        $config = $this->categoryConfig($account, $ctx['category_id']);
        if (($config['supported'] ?? null) === false) return $this->store($part, $this->metadata('category_unsupported', 'none', $ctx, [], null, ['config' => $config]));

        $client = new AllegroApiClient('allegro_main', $account);
        try {
            $result = $client->compatibilitySuggestionsByProductId($ctx['product_id']);
        } catch (\Throwable $e) {
            return $this->store($part, $this->metadata('fetch_error', 'none', $ctx, [], $e->getMessage(), ['config'=>$config,'external_requests'=>true,'external_request_methods'=>['GET']]), 'error');
        }

        if (($result['ok'] ?? false) !== true) {
            return $this->store($part, $this->metadata('fetch_error', 'none', $ctx, [], (string) ($result['error'] ?? 'Allegro compatibility suggestions failed'), $result + ['config'=>$config,'external_requests'=>true,'external_request_methods'=>['GET']]), 'error');
        }

        $items = $this->extractItems((array) ($result['json'] ?? []));
        $returned = count($items);
        $list = $this->normalizeList($items, $config);
        $status = count($list['items']) > 0 ? 'suggestions_found' : 'no_suggestions';
        return $this->store($part, $this->metadata($status, $status === 'suggestions_found' ? 'product_suggestions' : 'none', $ctx, $list, null, $result + ['config'=>$config,'external_requests'=>true,'external_request_methods'=>['GET']], $returned), $status === 'suggestions_found' ? 'success' : 'no_suggestions');
    }

    public function audit(Part $part, array $preparedPayload): array
    {
        $ctx = $this->context($part, $preparedPayload); $meta = (array) data_get((array) $part->review_metadata, self::META_PATH, []);
        return ['part_id'=>$part->id,'main_part_code'=>$part->part_number ?: $part->sku,'additional_part_codes'=>$part->additional_part_codes ?? [],'category_id'=>$ctx['category_id'] ?: null,'product_id'=>$ctx['product_id'] ?: null,'local_metadata'=>$meta,'status'=>$meta['status'] ?? 'not_checked','source'=>$meta['source'] ?? 'none','item_count'=>count((array) data_get($meta,'compatibilityList.items',[])),'canonical_hash'=>$meta['canonical_hash'] ?? null,'fetched_at'=>$meta['fetched_at'] ?? null,'product_category_still_match'=>$this->matches($meta,$ctx),'builder_will_include_compatibilityList'=>$this->publishableCompatibilityList($part,$preparedPayload) !== null,'read_only'=>true,'no_mutation'=>true,'no_marketplace_mutation'=>true,'marketplace_write'=>false,'publish'=>false,'external_requests'=>false,'external_request_methods'=>[],'fallback_behavior'=>'publish_without_compatibility'];
    }

    public function preview(Part $part, array $preparedPayload): array
    {
        $ctx = $this->context($part, $preparedPayload); $base = ['part_id'=>$part->id,'product_id'=>$ctx['product_id'] ?: null,'category_id'=>$ctx['category_id'] ?: null,'endpoint'=>'GET /sale/compatibility-list-suggestions?product.id={productId}','read_only'=>true,'no_mutation'=>true,'no_marketplace_mutation'=>true,'marketplace_write'=>false,'publish'=>false,'external_requests'=>false,'external_request_methods'=>[],'fallback_behavior'=>'publish_without_compatibility'];
        if ($ctx['product_id'] === '') return $base + ['result_status'=>'no_product_id','returned_items'=>[],'returned_count'=>0,'deduplicated_count'=>0,'planned_stored_count'=>0,'planned_canonical_hash'=>null,'planned_compatibilityList'=>['type'=>'MANUAL','items'=>[]]];
        $account = $this->account(); if (! $account) return $base + ['result_status'=>'fetch_error','last_error'=>'missing_allegro_account'];
        $config = $this->categoryConfig($account, $ctx['category_id']);
        $base['external_requests'] = true; $base['external_request_methods'] = ['GET'];
        if (($config['supported'] ?? null) === false) return $base + ['result_status'=>'category_unsupported','category_config'=>$config];
        try { $r = (new AllegroApiClient('allegro_main', $account))->compatibilitySuggestionsByProductId($ctx['product_id']); } catch (\Throwable $e) { return $base + ['result_status'=>'fetch_error','last_error'=>$e->getMessage()]; }
        $items = $this->extractItems((array) ($r['json'] ?? [])); $list = $this->normalizeList($items, $config);
        return $base + ['http_status'=>$r['http_status'] ?? null,'request_id'=>$r['request_id'] ?? null,'returned_items'=>$items,'returned_count'=>count($items),'deduplicated_count'=>count($list['items']),'planned_stored_count'=>count($list['items']),'planned_canonical_hash'=>$this->hash($list),'planned_compatibilityList'=>$list,'max_rows'=>$config['max_rows']??null,'input_type'=>$config['input_type']??null,'items_type'=>$config['items_type']??null,'truncated'=>count($items)>count($list['items']),'warning'=>count($items)>count($list['items'])?'allegro_compatibility_items_truncated_to_category_max_rows':($config['warning']??null),'result_status'=>count($list['items']) ? 'suggestions_found' : 'no_suggestions'];
    }

    public function publishableCompatibilityList(Part $part, array $payload): ?array
    { $ctx=$this->context($part,$payload); $meta=(array)data_get((array)$part->review_metadata,self::META_PATH,[]); $list=(array)($meta['compatibilityList']??[]); return ($this->matches($meta,$ctx) && in_array($meta['status']??null,['suggestions_found','included_in_publish','confirmed','applied_unverified'],true) && count((array)($list['items']??[]))) ? ['type'=>(string)($list['type']??'MANUAL'),'items'=>array_values((array)$list['items'])] : null; }

    public function normalizeList(array $items, array $config = []): array { $out=[]; $seen=[]; $maxRows=$this->maxRows($config, count($items)); foreach($items as $item){ if(!is_array($item)) continue; $n=$this->canonicalize($item, $config); if($n===[]) continue; $key=json_encode($n, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); if(isset($seen[$key])) continue; $seen[$key]=true; $out[]=$n; if($maxRows !== null && count($out)>=$maxRows) break; } return ['type'=>'MANUAL','items'=>$out]; }
    public function hash(array $list): string { return hash('sha256', json_encode($list, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
    private function canonicalize(array $item, array $config = []): array { $type=strtoupper((string)($item['type']??'')); if($type==='ID' && filled($item['id']??null)) return array_filter(['type'=>'ID','id'=>(string)$item['id'],'additionalInfo'=>array_values((array)($item['additionalInfo']??[]))],fn($v)=>$v!==null); if($type==='TEXT' && filled($item['text']??$item['name']??null)){ $limit=$this->maxCharactersPerLine($config); if($limit!==null){ foreach(['text','name'] as $k) if(isset($item[$k]) && is_string($item[$k])) $item[$k]=mb_substr($item[$k],0,$limit); } ksort($item); return $item; } if($type!=='' || filled($item['text']??$item['name']??null)){ ksort($item); return $item; } return []; }
    private function extractItems(array $json): array { $items=data_get($json,'compatibilityList.items',data_get($json,'items',data_get($json,'compatibleProducts',[]))); return array_values(array_filter(is_array($items)?$items:[], 'is_array')); }
    private function context(Part $part, array $payload): array { $productId=(string)data_get($payload,'productSet.0.product.id',''); $category=(string)($payload['category_id']??data_get($payload,'category.id','')); return ['product_id'=>trim($productId),'category_id'=>trim($category),'offer_id'=>(string)data_get($payload,'id','')]; }
    private function metadata(string $status,string $source,array $ctx,array $list=[],?string $err=null,array $api=[],?int $returned=null): array { $list=$list?:['type'=>'MANUAL','items'=>[]]; $stored=count($list['items']); $config=(array)($api['config']??[]); $truncated=$returned!==null && $returned>$stored; return ['status'=>$status,'source'=>$source,'product_id'=>$ctx['product_id']?:null,'category_id'=>$ctx['category_id']?:null,'offer_id'=>$ctx['offer_id']?:null,'compatibilityList'=>$list,'returned_count'=>$returned ?? $stored,'stored_count'=>$stored,'max_rows'=>$config['max_rows']??null,'input_type'=>$config['input_type']??null,'items_type'=>$config['items_type']??null,'truncated'=>$truncated,'external_requests'=>(bool)($api['external_requests']??($ctx['product_id']!=='')),'external_request_methods'=>($api['external_request_methods']??($ctx['product_id']!==''?['GET']:[])),'marketplace_write'=>false,'publish'=>false,'no_marketplace_mutation'=>true,'canonical_hash'=>$this->hash($list),'fetched_at'=>now()->toISOString(),'last_error'=>$err,'request_id'=>$api['request_id']??null,'http_status'=>$api['http_status']??null,'warning'=>$truncated?'allegro_compatibility_items_truncated_to_category_max_rows':(($config['warning']??null) ?: null)]; }
    private function store(Part $part,array $meta,string $logStatus='skipped'): array { $current=(array)data_get((array)$part->review_metadata,self::META_PATH,[]); $metadata=is_array($part->review_metadata)?$part->review_metadata:[]; data_set($metadata,self::META_PATH,$meta); $part->forceFill(['review_metadata'=>$metadata])->save(); if(($current['canonical_hash']??null)!==($meta['canonical_hash']??null) || ($current['status']??null)!==($meta['status']??null)) MarketplaceSyncLog::query()->create(['marketplace'=>'allegro','part_id'=>$part->id,'action'=>'allegro_compatibility_suggestions','status'=>$logStatus,'http_status'=>$meta['http_status']??null,'request_id'=>$meta['request_id']??null,'message'=>'Allegro compatibility suggestions '.$meta['status'],'payload'=>Arr::only($meta,['product_id','category_id','source','returned_count','stored_count','max_rows','input_type','items_type','truncated','canonical_hash','http_status','request_id','warning','last_error']),'created_at'=>now()]); return ['ok'=>true,'compatibility'=>$meta,'message'=>$this->message($meta)]; }
    private function message(array $m): string { return match($m['status']??'') {'suggestions_found'=>'Allegro znalazło kompatybilność dla '.(int)($m['stored_count']??0).' pojazdów.','no_product_id'=>'Nie udało się pobrać kompatybilności bez product.id. Oferta może zostać wystawiona bez sekcji Pasuje do.','no_suggestions'=>'Allegro nie zwróciło kompatybilnych pojazdów. Oferta może zostać wystawiona bez sekcji Pasuje do.', default=>'Nie udało się pobrać kompatybilności Allegro. Oferta może zostać wystawiona bez tej sekcji.'}; }
    private function account(): ?MarketplaceAccount { return Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code','allegro_main')->first() : null; }

    private function categoryConfig(MarketplaceAccount $a,string $id): array { if($id==='') return ['supported'=>null,'warning'=>'missing_category_id']; $r=Cache::remember('allegro_compatibility_supported_categories', now()->addHours(12), fn()=>(new AllegroApiClient('allegro_main',$a))->supportedCompatibilityCategories()); if(($r['ok']??false)!==true) return ['supported'=>null,'warning'=>'supported_categories_fetch_failed']; $rows=(array)(($r['json']['categories']??$r['json']['supportedCategories']??$r['json']['supported_categories']??(array_is_list($r['json']??[])?$r['json']:[]))); $ids=$this->categoryAndAncestors($id); foreach($ids as $candidate){ foreach($rows as $row){ if(!is_array($row)) continue; $rowId=(string)data_get($row,'id',data_get($row,'category.id',data_get($row,'categoryId',''))); if($rowId===$candidate) return ['supported'=>true,'matched_category_id'=>$rowId,'exact_match'=>$candidate===$id,'input_type'=>data_get($row,'inputType'),'items_type'=>data_get($row,'itemsType'),'max_rows'=>is_numeric(data_get($row,'validationRules.maxRows'))?(int)data_get($row,'validationRules.maxRows'):null,'max_characters_per_line'=>is_numeric(data_get($row,'validationRules.maxCharactersPerLine'))?(int)data_get($row,'validationRules.maxCharactersPerLine'):null,'raw'=>$row]; } } return ['supported'=>false,'warning'=>'category_not_supported']; }
    private function categoryAndAncestors(string $id): array { $ids=[$id]; if(!Schema::hasTable('marketplace_categories')) return $ids; $cat=MarketplaceCategory::query()->where('channel','allegro_main')->where('external_category_id',$id)->first(); $guard=0; while($cat && filled($cat->parent_external_category_id) && $guard++<20){ $parent=(string)$cat->parent_external_category_id; $ids[]=$parent; $cat=MarketplaceCategory::query()->where('channel','allegro_main')->where('external_category_id',$parent)->first(); } return array_values(array_unique($ids)); }
    private function maxRows(array $config, int $available): ?int { return isset($config['max_rows']) && is_numeric($config['max_rows']) ? max(0,(int)$config['max_rows']) : $available; }
    private function maxCharactersPerLine(array $config): ?int { return isset($config['max_characters_per_line']) && is_numeric($config['max_characters_per_line']) ? max(0,(int)$config['max_characters_per_line']) : null; }
    private function matches(array $meta,array $ctx): bool { return ($meta['product_id']??null)===$ctx['product_id'] && ($meta['category_id']??null)===$ctx['category_id']; }
}
