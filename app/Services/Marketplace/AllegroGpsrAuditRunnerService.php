<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\AllegroApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AllegroGpsrAuditRunnerService
{
    public const CACHE_KEY = 'admin_tools:allegro_gpsr_audit_runner:v1';
    public const MARKER = 'allegro_gpsr_audit_runner_v1';
    public const MODES = ['diagnose-basic', 'diagnose-with-catalog'];
    public const CSV_COLUMNS = ['part_id','listing_id','offer_id','local_status','remote_publication_status','product_id','safety_type','description_present','description_length','attachments_count','responsible_producer_present','responsible_person_present','marketed_before_gpsr_obligation','overall_classification','repair_required','activation_risk','api_http_status','error_code','error_message_sanitized'];
    public const CLASSES = ['valid_text','valid_attachments','no_safety_information','missing_safety_information','invalid_text','missing_responsible_producer','missing_responsible_person','mixed_product_set','unknown_type','api_error'];

    public function start(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'start-allegro-gpsr-audit') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $existing = $this->rawState();
        if (($existing['status'] ?? null) === 'running') return array_merge($this->publicStatus($existing), ['ok' => false, 'reason' => 'already_running']);
        $mode = in_array(($input['mode'] ?? 'diagnose-basic'), self::MODES, true) ? $input['mode'] : 'diagnose-basic';
        $batch = max(1, min((int) ($input['batch_size'] ?? 10), 50));
        $delay = max(1, (int) ($input['delay_seconds'] ?? 2));
        $candidates = $this->candidateRows();
        $ids = array_column($candidates['eligible_rows'], 'listing_id');
        $state = $this->baseState('running') + [];
        $state = array_merge($state, [
            'mode' => $mode,
            'batch_size' => $batch,
            'delay_seconds' => $delay,
            'candidate_diagnostics' => $candidates['diagnostics'],
            'total' => count($ids), 'processed' => 0, 'remaining' => count($ids),
            'remaining_ids' => $ids, 'processed_ids' => [], 'results' => [],
            'started_at' => now()->toISOString(), 'finished_at' => null, 'last_batch_at' => null,
        ]);
        Cache::put(self::CACHE_KEY, $state, now()->addHours(24));
        return array_merge($this->publicStatus($state), ['ok' => true]);
    }

    public function runNextBatch(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'run-allegro-gpsr-audit-batch') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $state = $this->state();
        if (($state['status'] ?? null) !== 'running') return array_merge($this->publicStatus($state), ['ok' => false, 'reason' => 'not_running', 'batch_executed' => false]);
        if (empty($state['remaining_ids'])) return array_merge($this->complete($state), ['ok' => true, 'batch_executed' => false]);
        $delay = $this->delayDiagnostics($state);
        if ($delay['retry_after_seconds'] > 0) return array_merge($this->publicStatus($state), ['ok' => true, 'batch_executed' => false, 'should_wait' => true], $delay);
        $batchIds = array_slice($state['remaining_ids'], 0, (int) $state['batch_size']);
        foreach (MarketplaceListing::query()->whereIn('id', $batchIds)->orderBy('id')->get() as $listing) {
            $result = $this->auditListing($listing, $state['mode']);
            $state = $this->recordResult($state, $result);
        }
        $state['remaining_ids'] = array_values(array_diff($state['remaining_ids'], $batchIds));
        $state['processed_ids'] = array_values(array_unique(array_merge($state['processed_ids'], $batchIds)));
        $state['processed'] = count($state['processed_ids']);
        $state['remaining'] = count($state['remaining_ids']);
        $state['last_batch_at'] = now()->toISOString();
        if (empty($state['remaining_ids'])) $state = $this->complete($state, false);
        Cache::put(self::CACHE_KEY, $state, now()->addHours(24));
        return array_merge($this->publicStatus($state), ['ok' => true, 'batch_executed' => true]);
    }

    public function autoRun(array $input): array
    {
        $limit = max(1, min((int) ($input['max_batches'] ?? 10), 100)); $runs = 0; $last = [];
        while ($runs < $limit) { $last = $this->runNextBatch(['confirm' => 'run-allegro-gpsr-audit-batch']); $runs++; if (($last['status'] ?? null) !== 'running' || ($last['should_wait'] ?? false)) break; }
        return array_merge($last, ['auto_batches' => $runs]);
    }

    public function stop(array $input): array
    {
        if (($input['confirm'] ?? null) !== 'stop-allegro-gpsr-audit') return ['ok' => false, 'reason' => 'missing_confirm_token'];
        $state = $this->state(); if ($state['status'] === 'running') $state['status'] = 'stopped'; $state['finished_at'] = $state['finished_at'] ?? now()->toISOString();
        Cache::put(self::CACHE_KEY, $state, now()->addHours(24)); return array_merge($this->publicStatus($state), ['ok' => true]);
    }
    public function status(): array { return array_merge($this->publicStatus($this->state()), ['ok' => true]); }
    public function jsonExport(): array { $s=$this->state(); return ['run'=>$this->runBlock($s), 'candidate_diagnostics'=>$s['candidate_diagnostics'], 'summary'=>$s['summary'], 'samples'=>$s['samples'], 'results'=>array_values($s['results']), 'would_write_database'=>false, 'would_call_allegro_write_api'=>false]; }
    public function csvExport(): string { $rows=[self::CSV_COLUMNS]; foreach ($this->state()['results'] as $r) foreach (($r['products'] ?: [[]]) as $p) $rows[] = [$r['part_id'],$r['listing_id'],$r['offer_id'],$r['local_status'],$r['remote_publication_status'],$p['product_id']??null,$p['safety_information']['type']??null,($p['safety_information']['description_present']??false)?'true':'false',$p['safety_information']['description_length']??0,$p['safety_information']['attachments_count']??0,($p['responsible_producer_present']??false)?'true':'false',($p['responsible_person_present']??false)?'true':'false',var_export($p['marketed_before_gpsr_obligation']??null,true),$r['overall_classification'],$r['repair_required']?'true':'false',$r['activation_risk'],$r['remote_http_status'],$r['error_code']??null,$r['error_message_sanitized']??null]; return implode("\n", array_map(fn($row)=>implode(',', array_map(fn($v)=>'"'.str_replace('"','""',str_replace(["\r","\n"],' ',(string)$v)).'"',$row)), $rows))."\n"; }

    public function candidateRows(): array
    {
        $rows = MarketplaceListing::query()->whereIn('marketplace', ['allegro','allegro_main'])->orderBy('id')->get();
        $eligible=[]; $seen=[]; $dupes=0; $amb=0;
        foreach ($rows as $l) { $offer=$this->offerId($l); if ($offer==='') continue; if(isset($seen[$offer])) { $dupes++; $amb++; continue; } $seen[$offer]=true; $eligible[]=['listing_id'=>$l->id,'offer_id'=>$offer]; }
        return ['diagnostics'=>['total_allegro_listings'=>$rows->count(),'with_offer_id'=>$rows->filter(fn($l)=>$this->offerId($l)!=='')->count(),'without_offer_id'=>$rows->filter(fn($l)=>$this->offerId($l)==='')->count(),'duplicate_offer_ids'=>$dupes,'ambiguous_mappings'=>$amb,'eligible_for_api_check'=>count($eligible)], 'eligible_rows'=>$eligible];
    }

    public function classifyOfferPayload(array $payload, ?MarketplaceListing $listing = null, int|string|null $http = 200): array
    {
        $products=[]; foreach (array_values(Arr::get($payload,'productSet',[])) as $i=>$item) if (is_array($item)) $products[]=$this->classifyProduct($item,$i);
        if ($products===[]) $products[]=$this->classifyProduct([],0);
        $classes = array_values(array_unique(array_column($products,'classification'))); $bad = array_values(array_filter($classes, fn($c)=>!in_array($c,['valid_text','valid_attachments'],true)));
        $overall = count($products)>1 && $bad!==[] && count($bad)<count($products) ? 'mixed_product_set' : ($bad[0] ?? $classes[0] ?? 'missing_safety_information');
        return ['part_id'=>$listing?->part_id,'listing_id'=>$listing?->id,'offer_id'=>$listing?$this->offerId($listing):($payload['id']??null),'local_status'=>$listing?->status,'local_sync_status'=>$listing?->sync_status,'local_last_api_status'=>$listing?->last_api_status,'remote_http_status'=>$http,'remote_publication_status'=>Arr::get($payload,'publication.status'),'product_set_count'=>count($products),'products'=>$products,'overall_classification'=>$overall,'repair_required'=>!in_array($overall,['valid_text','valid_attachments'],true),'activation_risk'=>in_array($overall,['no_safety_information','missing_safety_information','invalid_text','missing_responsible_producer','mixed_product_set'],true)?'high':($overall==='missing_responsible_person'?'low':'none'),'would_write_database'=>false,'would_call_allegro_write_api'=>false];
    }

    private function auditListing(MarketplaceListing $listing, string $mode): array
    {
        $offer=$this->offerId($listing); $api=$this->getOfferWithRetry($listing,$offer); if (!($api['ok']??false)) return $this->apiError($listing,$offer,$api);
        $result=$this->classifyOfferPayload($api['json']??[], $listing, $api['http_status']??200);
        if ($mode==='diagnose-with-catalog' && $result['repair_required']) $result['catalog_product_safety']=$this->catalogSafety($listing,$result);
        return $result;
    }
    private function classifyProduct(array $item, int $i): array
    {
        $si=Arr::get($item,'safetyInformation'); $type=is_array($si)?($si['type']??null):null; $desc=is_array($si)?trim((string)($si['description']??'')):''; $atts=is_array($si)?array_values(array_filter($si['attachments']??[], fn($a)=>is_array($a)?filled($a['id']??null):filled($a))):[];
        $rp=Arr::get($item,'responsibleProducer'); $rper=Arr::get($item,'responsiblePerson'); $block=[]; $warn=[];
        $class = match($type){ 'TEXT' => ($desc!=='' && mb_strlen($desc)<=5000 && !$this->placeholder($desc))?'valid_text':'invalid_text', 'ATTACHMENTS' => count($atts)>0?'valid_attachments':'missing_safety_information', 'NO_SAFETY_INFORMATION'=>'no_safety_information', null=>'missing_safety_information', default=>'unknown_type'};
        if ($class==='valid_text') $warn[]='verify_text_is_product_specific';
        if (!is_array($rp) || blank($rp['id']??$rp['name']??null)) { $block[]='missing_responsible_producer'; if(in_array($class,['valid_text','valid_attachments'],true)) $class='missing_responsible_producer'; }
        if (!is_array($rper) || blank($rper['id']??$rper['name']??null)) { $warn[]='missing_responsible_person'; if(in_array($class,['valid_text','valid_attachments'],true)) $class='missing_responsible_person'; }
        return ['index'=>$i,'product_id'=>Arr::get($item,'product.id'),'quantity'=>$item['quantity']??null,'product_publication_status'=>Arr::get($item,'product.publication.status'),'safety_information'=>['type'=>$type,'description_present'=>$desc!=='','description_length'=>mb_strlen($desc),'description_hash'=>$desc!==''?hash('sha256',$desc):null,'description_sample'=>$desc!==''?mb_substr($desc,0,200):null,'attachments_count'=>count($atts)],'responsible_producer_present'=>is_array($rp)&&filled($rp['id']??$rp['name']??null),'responsible_producer_id'=>is_array($rp)?($rp['id']??null):null,'responsible_person_present'=>is_array($rper)&&filled($rper['id']??$rper['name']??null),'responsible_person_id'=>is_array($rper)?($rper['id']??null):null,'marketed_before_gpsr_obligation'=>$item['marketedBeforeGPSRObligation']??null,'classification'=>$class,'blockers'=>$block,'warnings'=>$warn];
    }
    private function getOfferWithRetry(MarketplaceListing $l,string $offer): array { return $this->api($l)->productOffer($offer); }
    private function catalogSafety(MarketplaceListing $l,array $result): array { $pid=collect($result['products'])->pluck('product_id')->filter()->first(); if(!$pid)return ['checked'=>false,'available'=>false,'warnings'=>['missing_product_id']]; $api=$this->api($l)->productCatalogReadOnly((string)$pid); $ps=$api['json']['productSafety']??[]; return ['checked'=>true,'available'=>($api['ok']??false)&&is_array($ps),'http_status'=>$api['http_status']??null,'safety_information_type'=>Arr::get($ps,'safetyInformation.type'),'responsible_producers_count'=>count(Arr::get($ps,'responsibleProducers',[])),'could_be_repair_source'=>filled(Arr::get($ps,'safetyInformation.type'))||count(Arr::get($ps,'responsibleProducers',[]))>0,'warnings'=>($api['ok']??false)?[]:['catalog_get_failed']]; }
    private function api(MarketplaceListing $l): AllegroApiClient { $account=$l->account ?: MarketplaceAccount::query()->whereIn('code',['allegro_main','allegro'])->first(); return new AllegroApiClient('allegro_main',$account); }
    private function apiError(MarketplaceListing $l,string $offer,array $api): array { return ['part_id'=>$l->part_id,'listing_id'=>$l->id,'offer_id'=>$offer,'local_status'=>$l->status,'local_sync_status'=>$l->sync_status,'local_last_api_status'=>$l->last_api_status,'remote_http_status'=>$api['http_status']??null,'remote_publication_status'=>null,'product_set_count'=>0,'products'=>[],'overall_classification'=>'api_error','repair_required'=>false,'activation_risk'=>'unknown','error_code'=>$api['error_code']??null,'error_message_sanitized'=>Str::limit((string)($api['error']??'Allegro GET failed'),200),'would_write_database'=>false,'would_call_allegro_write_api'=>false]; }
    private function recordResult(array $s,array $r): array { $c=$r['overall_classification']; $key=$c==='api_error'?'api_errors':$c; if(isset($s['summary'][$key]))$s['summary'][$key]++; $s['summary']['checked']++; if($r['activation_risk']==='high')$s['summary']['activation_high_risk']++; foreach(['no_safety_information','missing_safety_information','api_error'] as $sample) if($c===$sample && count($s['samples'][$sample==='api_error'?'api_errors':$sample])<10)$s['samples'][$sample==='api_error'?'api_errors':$sample][]=['listing_id'=>$r['listing_id'],'part_id'=>$r['part_id'],'offer_id'=>$r['offer_id'],'http_status'=>$r['remote_http_status']]; $s['results'][(string)$r['listing_id']]=$r; $s['last_listing_id']=$r['listing_id']; return $s; }
    private function offerId(MarketplaceListing $l): string { foreach ([$l->external_offer_id,$l->external_listing_id] as $v) if (is_string($v)&&preg_match('/\d{6,}/',$v,$m)) return $m[0]; return ''; }
    private function placeholder(string $s): bool { return in_array(mb_strtolower(trim($s)), ['test','brak','n/a','none','placeholder','TODO'], true); }
    private function baseState(string $status): array { return ['marker'=>self::MARKER,'mode'=>'diagnose-basic','status'=>$status,'started_at'=>null,'finished_at'=>null,'batch_size'=>10,'delay_seconds'=>2,'total'=>0,'processed'=>0,'remaining'=>0,'last_listing_id'=>null,'last_batch_at'=>null,'candidate_diagnostics'=>['total_allegro_listings'=>0,'with_offer_id'=>0,'without_offer_id'=>0,'duplicate_offer_ids'=>0,'ambiguous_mappings'=>0,'eligible_for_api_check'=>0],'summary'=>['checked'=>0,'valid_text'=>0,'valid_attachments'=>0,'no_safety_information'=>0,'missing_safety_information'=>0,'invalid_text'=>0,'missing_responsible_producer'=>0,'missing_responsible_person'=>0,'mixed_product_set'=>0,'unknown_type'=>0,'api_errors'=>0,'activation_high_risk'=>0],'samples'=>['no_safety_information'=>[],'missing_safety_information'=>[],'api_errors'=>[]],'results'=>[],'remaining_ids'=>[],'processed_ids'=>[],'would_write_database'=>false,'would_call_allegro_write_api'=>false]; }
    private function rawState(): array { $s=Cache::get(self::CACHE_KEY); return is_array($s)?$s:[]; }
    private function state(): array { return array_merge($this->baseState('idle'), $this->rawState()); }
    private function complete(array $s,bool $put=true): array { $s['status']='completed'; $s['finished_at']=$s['finished_at']??now()->toISOString(); if($put)Cache::put(self::CACHE_KEY,$s,now()->addHours(24)); return $s; }
    private function publicStatus(array $s): array { unset($s['remaining_ids'],$s['processed_ids']); return array_merge($s, ['run'=>$this->runBlock($s)], $this->delayDiagnostics($s)); }
    private function runBlock(array $s): array { return ['mode'=>$s['mode']??'diagnose-basic','status'=>$s['status']??'idle','started_at'=>$s['started_at']??null,'finished_at'=>$s['finished_at']??null,'batch_size'=>$s['batch_size']??0,'processed'=>$s['processed']??0,'remaining'=>$s['remaining']??0]; }
    private function delayDiagnostics(array $s): array { $last=filled($s['last_batch_at']??null)?CarbonImmutable::parse($s['last_batch_at'])->utc():null; $next=$last?->addSeconds((int)($s['delay_seconds']??0)); $retry=$next?max(0,(int)ceil($next->floatDiffInSeconds(CarbonImmutable::now('UTC'), false)*-1)):0; return ['next_batch_allowed_at'=>$next?->toISOString(),'retry_after_seconds'=>$retry]; }
}
