<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AllegroCompatibilityApplyService
{
    public const PART_ID = 8051;
    public const OFFER_ID = '18777047863';
    public const CATEGORY_ID = '256282';
    public const CONFIRM = 'apply-8051-allegro-compatibility';
    public const EXPECTED_COUNT = 6;

    public function dryRun(Part $part): array
    {
        return $this->preflight($part) + [
            'dry_run' => true,
            'read_only' => true,
            'no_mutation' => true,
            'marketplace_write' => false,
            'external_requests' => true,
            'external_request_methods' => ['GET'],
            'patch_executed' => false,
            'database_changed' => false,
            'operational_logs_changed' => false,
            'apply_url' => url('/admin/tools/marketplace/parts/'.$part->id.'/allegro-compatibility-apply'),
            'apply_confirm' => self::CONFIRM,
        ];
    }

    public function apply(Part $part, string $confirm, string $dryRunHash): array
    {
        if (! hash_equals(self::CONFIRM, $confirm)) {
            return ['ok'=>false,'can_apply'=>false,'blockers'=>['missing_or_invalid_confirm'],'message'=>'Nie udało się dodać sekcji Pasuje do. Oferta pozostała bez zmian.','patch_executed'=>false];
        }
        if ($dryRunHash === '') {
            return ['ok'=>false,'can_apply'=>false,'blockers'=>['missing_canonical_hash_from_dry_run'],'message'=>'Nie udało się dodać sekcji Pasuje do. Oferta pozostała bez zmian.','patch_executed'=>false];
        }

        return Cache::lock('allegro-compatibility-apply:'.self::PART_ID.':'.self::OFFER_ID, 120)->block(0, function () use ($part, $dryRunHash): array {
            $preflight = $this->preflight($part);
            if (! hash_equals($dryRunHash, (string) ($preflight['canonical_hash'] ?? ''))) {
                return $preflight + ['ok'=>false,'can_apply'=>false,'blockers'=>array_merge($preflight['blockers'] ?? [], ['canonical_hash_does_not_match_dry_run']),'patch_executed'=>false,'message'=>'Nie udało się dodać sekcji Pasuje do. Oferta pozostała bez zmian.'];
            }
            if (! ($preflight['can_apply'] ?? false)) return $preflight + ['patch_executed'=>false,'message'=>'Nie udało się dodać sekcji Pasuje do. Oferta pozostała bez zmian.'];

            if (($preflight['current_canonical_hash'] ?? null) === $preflight['canonical_hash'] && ($preflight['current_item_count'] ?? 0) === self::EXPECTED_COUNT) {
                $this->log('allegro_compatibility_confirm', 'success', $preflight + ['idempotent'=>true]);
                return $preflight + ['ok'=>true,'idempotent'=>true,'patch_executed'=>false,'message'=>'Sekcja Pasuje do została dodana do oferty Allegro dla 6 pojazdów.'];
            }

            $this->guardPayload($preflight['exact_patch_payload']);
            $client = $this->client();
            $patch = $client->updateCompatibilityListOnly(self::OFFER_ID, $preflight['planned_compatibilityList']);
            $after = $client->getProductOffer(self::OFFER_ID, 'allegro_compatibility_confirm');
            $confirmedList = $this->normalizeRemoteList((array) data_get($after, 'body.compatibilityList', []));
            $confirmedHash = $this->hash($confirmedList);
            $confirmedCount = count($confirmedList['items']);
            $finalSuccess = ($patch['ok'] ?? false) && $confirmedCount === self::EXPECTED_COUNT && $confirmedHash === $preflight['canonical_hash'];

            $result = $preflight + ['patch_executed'=>true,'patch_http_status'=>$patch['http_status'] ?? null,'patch_request_id'=>$patch['request_id'] ?? null,'patch_response_summary'=>Arr::only($patch, ['ok','http_status','request_id']),'read_after_write'=>$after['response_summary'] ?? [],'confirmed_count'=>$confirmedCount,'confirmed_hash'=>$confirmedHash,'final_success'=>$finalSuccess,'message'=>$finalSuccess ? 'Sekcja Pasuje do została dodana do oferty Allegro dla 6 pojazdów.' : (($patch['ok'] ?? false) ? 'Allegro przyjęło listę Pasuje do, ale oczekuje ona na potwierdzenie.' : 'Nie udało się dodać sekcji Pasuje do. Oferta pozostała bez zmian.')];
            $this->log('allegro_compatibility_apply', $finalSuccess ? 'success' : 'pending_or_failed', $result, $patch);
            $this->log('allegro_compatibility_confirm', $finalSuccess ? 'success' : 'pending_or_failed', $result);
            return $result;
        });
    }

    private function preflight(Part $part): array
    {
        $blockers = [];
        if ((int) $part->id !== self::PART_ID) $blockers[] = 'unexpected_part_id';
        $client = $this->client();
        $offer = $client->getProductOffer(self::OFFER_ID, 'allegro_compatibility_offer_preflight');
        $suggestions = $client->compatibilitySuggestionsByOfferId(self::OFFER_ID);
        $items = app(AllegroCompatibilitySuggestionsService::class)->normalizeList($this->extractItems((array) ($suggestions['json'] ?? [])), ['max_rows'=>2000]);
        $hash = $this->hash($items);
        if (count($items['items']) !== self::EXPECTED_COUNT) $blockers[] = 'planned_count_mismatch';
        $payload = ['compatibilityList' => $items];
        if (array_keys($payload) !== ['compatibilityList']) $blockers[] = 'patch_payload_top_level_keys_not_allowed';
        $remoteList = $this->normalizeRemoteList((array) data_get($offer, 'body.compatibilityList', []));
        return ['ok'=>($offer['ok'] ?? false) && ($suggestions['ok'] ?? false) && $blockers===[],'part_id'=>$part->id,'offer_id'=>self::OFFER_ID,'category_id'=>self::CATEGORY_ID,'endpoint'=>'PATCH /sale/product-offers/'.self::OFFER_ID,'current_remote_compatibilityList'=>$remoteList,'current_item_count'=>count($remoteList['items']),'current_canonical_hash'=>$this->hash($remoteList),'planned_compatibilityList'=>$items,'planned_item_count'=>count($items['items']),'canonical_hash'=>$hash,'exact_patch_payload'=>$payload,'top_level_keys'=>array_keys($payload),'current_price'=>data_get($offer,'body.sellingMode.price'),'current_stock_quantity'=>['stock'=>data_get($offer,'body.stock'),'quantity'=>data_get($offer,'body.stock.available')],'current_publication_status'=>data_get($offer,'body.publication.status'),'fields_not_sent'=>['price','stock','quantity','publication','product.id','productSet','category','parameters','description','images','name','policies','delivery','payments','taxSettings'],'blockers'=>$blockers,'can_apply'=>$blockers===[],'suggestions_http_status'=>$suggestions['http_status'] ?? null,'suggestions_request_id'=>$suggestions['request_id'] ?? null,'offer_http_status'=>$offer['http_status'] ?? null,'offer_request_id'=>data_get($offer,'response_summary.request_id')];
    }

    private function extractItems(array $json): array { $items=data_get($json,'compatibilityList.items',data_get($json,'items',data_get($json,'compatibleProducts',[]))); return array_values(array_filter(is_array($items)?$items:[], 'is_array')); }
    private function normalizeRemoteList(array $list): array { return app(AllegroCompatibilitySuggestionsService::class)->normalizeList((array)($list['items'] ?? []), ['max_rows'=>2000]); }
    private function hash(array $list): string { return app(AllegroCompatibilitySuggestionsService::class)->hash($list); }
    private function account(): ?MarketplaceAccount { return Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code','allegro_main')->firstOrFail() : null; }
    private function client(): AllegroApiClient { return new AllegroApiClient('allegro_main', $this->account()); }
    private function guardPayload(array $payload): void { if (array_keys($payload) !== ['compatibilityList']) throw new \RuntimeException('compatibility_patch_not_minimal'); }
    private function log(string $action, string $status, array $result, array $patch = []): void { MarketplaceSyncLog::query()->create(['marketplace'=>'allegro','part_id'=>self::PART_ID,'action'=>$action,'status'=>$status,'http_status'=>$patch['http_status'] ?? ($result['patch_http_status'] ?? null),'request_id'=>$patch['request_id'] ?? ($result['patch_request_id'] ?? null),'external_id'=>self::OFFER_ID,'message'=>'Controlled Allegro compatibility apply for part 8051.','payload'=>Arr::only($result,['part_id','offer_id','category_id','planned_item_count','canonical_hash','endpoint','top_level_keys','patch_http_status','patch_request_id','read_after_write','confirmed_count','confirmed_hash','final_success','fields_not_sent']),'created_at'=>now()]); }
}
