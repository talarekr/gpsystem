<?php

namespace App\Services\Marketplace\PriceSync;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PartPriceSyncService
{
    public function __construct(private readonly PriceNormalizer $normalizer, private readonly AllegroPriceSyncAdapter $allegro, private readonly OvokoPriceSyncAdapter $ovoko, private readonly EbayDePriceSyncAdapter $ebay) {}

    public function syncAfterPartSave(Part $part, array $old, array $new): array
    {
        try { return $this->sync($part->fresh(['marketplaceListings.account']) ?: $part, $old, $new); }
        catch (\Throwable $e) { return ['ok'=>false,'error'=>'price_sync_orchestrator_exception','message'=>$e->getMessage()]; }
    }

    public function sync(Part $part, array $old, array $new): array
    {
        $enabled = (bool) config('marketplace.price_sync.on_part_save_enabled', false);
        $channels = ['allegro','ovoko','ebay_de']; $out = [];
        foreach ($channels as $channel) {
            $ctx = $this->context($part, $channel, $old[$channel] ?? [], $new[$channel] ?? []);
            if (! $enabled) { if ($ctx['changed']) $out[$channel] = $this->result($ctx, ['status'=>'disabled','blocker'=>'price_sync_disabled','final_success'=>false], null); continue; }
            $pre = $this->preflight($ctx);
            if ($pre['blockers'] !== []) { $res = ['status'=>'skipped','blocker'=>$pre['blockers'][0] ?? 'preflight_blocked','blockers'=>$pre['blockers'],'final_success'=>false]; $logId = $ctx['changed'] ? $this->writeLog($ctx, $res) : null; if ($ctx['changed']) $out[$channel] = $this->result($ctx, $res, $logId); continue; }
            $lock = Cache::lock($ctx['lock_key'], 60);
            if (! $lock->get()) { $res = ['status'=>'skipped','blocker'=>'operation_in_progress','blockers'=>['operation_in_progress'],'final_success'=>false]; $logId = $ctx['changed'] ? $this->writeLog($ctx, $res) : null; if ($ctx['changed']) $out[$channel] = $this->result($ctx, $res, $logId); continue; }
            try {
                if ($this->confirmedAlready($ctx)) { $res = ['status'=>'skipped','blocker'=>'idempotent_price_already_confirmed','blockers'=>['idempotent_price_already_confirmed'],'final_success'=>true,'remote_confirmed_price'=>$ctx['new']['marketplace_price'] ?? null]; $out[$channel] = $this->result($ctx, $res, null); continue; }
                $res = $this->adapter($channel)->sync($ctx['listing'], $ctx['new']);
                $logId = $this->writeLog($ctx, $res);
                $out[$channel] = $this->result($ctx, $res, $logId);
                if (($res['final_success'] ?? false) === true) $ctx['listing']->forceFill(['price'=>$ctx['new']['marketplace_price'],'currency'=>$ctx['new']['marketplace_currency'],'last_synced_at'=>now()])->save();
            } finally { optional($lock)->release(); }
        }
        return ['ok'=>true,'channels'=>$out];
    }

    public function context(Part $part, string $channel, array $oldPrice, array $newPrice): array
    {
        $listing = $this->activeListing($part, $channel);
        $external = $listing ? $this->externalId($listing, $channel) : null;
        $changed = $this->normalizer->normalize($oldPrice['marketplace_price'] ?? null) !== $this->normalizer->normalize($newPrice['marketplace_price'] ?? null);
        return ['part'=>$part,'channel'=>$channel,'old'=>$oldPrice,'new'=>$newPrice,'changed'=>$changed,'enabled'=>(bool)config('marketplace.price_sync.on_part_save_enabled',false),'channel_allowed'=>in_array($channel, (array) config('marketplace.price_sync.channels', []), true),'listing'=>$listing,'listing_id'=>$listing?->id,'marketplace_account_id'=>$listing?->marketplace_account_id,'external_id'=>$external,'sku'=>$listing?->sku,'listing_type'=>$channel==='ebay_de' ? $this->ebay->classify($listing ?: new MarketplaceListing())['type'] : null,'lock_key'=>'marketplace-price-sync:'.($part->id ?: 'new').':'.$channel.':'.($listing?->marketplace_account_id ?: 'none').':'.($external ?: $listing?->sku ?: 'none').':'.($newPrice['marketplace_price'] ?? 'null').':'.($newPrice['marketplace_currency'] ?? 'null')];
    }


    public function preview(Part $part, array $old, array $new): array
    {
        return collect(['allegro','ovoko','ebay_de'])->mapWithKeys(function (string $channel) use ($part, $old, $new): array {
            $ctx = $this->context($part->fresh(['marketplaceListings.account']) ?: $part, $channel, $old[$channel] ?? [], $new[$channel] ?? []);
            $price = $ctx['new']['marketplace_price'] ?? null;
            $currency = $ctx['new']['marketplace_currency'] ?? null;
            $payload = match ($channel) {
                'allegro' => ['sellingMode' => ['price' => ['amount' => $price, 'currency' => 'PLN']]],
                'ovoko' => ['part_id' => $ctx['external_id'], 'price' => $price, 'original_currency' => 'PLN'],
                'ebay_de' => ['requests' => [[
                    'sku' => $ctx['sku'],
                    'pricingSummary' => ['price' => ['value' => $price, 'currency' => 'EUR']],
                    'shipToLocationAvailability' => ['quantity' => '<remote_quantity_before>'],
                ]]],
                default => [],
            };
            return [$channel => $ctx + ['read_only_preview' => true, 'planned_endpoint' => match ($channel) { 'allegro' => 'PATCH /sale/product-offers/{offerId}', 'ovoko' => 'POST /crm/updatePart', 'ebay_de' => 'POST /sell/inventory/v1/bulk_update_price_quantity', default => null }, 'planned_payload' => $payload, 'marketplace_write_performed' => false, 'requires_live_confirmation_before_apply' => true, 'currency' => $currency]];
        })->all();
    }

    public function preflight(array $c): array
    {
        $b=[]; $p=$c['part']; $n=$c['new']; $l=$c['listing'];
        if (! $this->snapshotMatchesModel($c)) $b[]='price_snapshot_mismatch';
        $checks = [
            [!$c['channel_allowed'], 'channel_not_allowed'],
            [!config('marketplace.external_api_writes_enabled',false), 'external_api_writes_disabled'],
            [!$this->channelWriteEnabled($c['channel']), 'channel_write_disabled'],
            [!$c['changed'], 'price_not_changed'],
            [in_array($p->status,['sold','archived'],true), 'part_sold_or_archived'],
            [(int)$p->quantity<=0, 'quantity_not_positive'],
            [!$this->normalizer->positive($n['marketplace_price']??null), 'price_not_positive'],
            [!$l, 'missing_active_listing'],
            [!$c['external_id'], 'missing_external_id'],
            [!$l?->account || !$l->account->api_enabled || $l->account->status !== 'active', 'marketplace_account_inactive'],
            [blank($l?->account?->api_credentials), 'missing_credentials'],
        ];
        foreach ($checks as [$bad, $code]) if($bad) $b[]=$code;
        if ($c['channel']==='ebay_de') { if (($l?->marketplace)!=='ebay_de') $b[]='ebay_de_marketplace_required'; if (($c['listing_type']??null)==='legacy') $b[]='ebay_legacy_price_sync_not_supported'; if (($n['marketplace_currency']??null)!=='EUR') $b[]='ebay_de_currency_must_be_eur'; }
        return ['blockers'=>array_values(array_unique($b))];
    }

    private function snapshotMatchesModel(array $c): bool
    {
        $current = app(PartPriceResolver::class)->resolve($c['part']->fresh() ?: $c['part'], $c['channel']);
        return ($current['marketplace_price'] ?? null) === ($c['new']['marketplace_price'] ?? null)
            && ($current['marketplace_currency'] ?? null) === ($c['new']['marketplace_currency'] ?? null);
    }
    private function channelWriteEnabled(string $channel): bool { return match ($channel) { 'allegro' => (bool) config('marketplace.allegro_publishing_enabled', false), 'ovoko' => (bool) config('marketplace.ovoko_publishing_enabled', false), 'ebay_de' => (bool) config('marketplace.ebay_publishing_enabled', false), default => false }; }
    private function activeListing(Part $part,string $channel): ?MarketplaceListing { return $part->marketplaceListings->filter(fn($l)=>$l->marketplace===$channel || ($channel==='allegro'&&$l->marketplace==='allegro_main'))->filter(fn($l)=>in_array(strtolower((string)$l->status),['active','published','live','in_stock','for_sale'],true) && !in_array(strtolower((string)$l->sync_status),['stale','ignored','sold','unlinked','error','failed'],true))->sortByDesc('id')->first(); }
    private function externalId(MarketplaceListing $l,string $c): ?string { return $c==='ebay_de' ? ($l->external_offer_id ?: null) : ($l->external_offer_id ?: $l->external_listing_id ?: null); }
    private function adapter(string $c): MarketplacePriceSyncAdapter { return ['allegro'=>$this->allegro,'ovoko'=>$this->ovoko,'ebay_de'=>$this->ebay][$c]; }
    private function confirmedAlready(array $c): bool { return MarketplaceSyncLog::query()->where('action','part_price_sync')->where('status','success')->where('part_id',$c['part']->id)->where('marketplace',$c['channel'])->where('marketplace_listing_id',$c['listing_id'])->where('external_id',$c['external_id'])->where('payload->new_price',$c['new']['marketplace_price']??null)->where('payload->marketplace_currency',$c['new']['marketplace_currency']??null)->exists(); }
    private function result(array $c, array $r, ?int $logId): array { return $c + $r + ['ok'=>($r['final_success'] ?? false) === true || ($r['status'] ?? null) === 'write_accepted_unverified','message'=>$this->message($c['channel'], $r),'blocker'=>$r['blocker'] ?? ($r['blockers'][0] ?? null),'marketplace_listing_id'=>$c['listing_id'],'old_price'=>$c['old']['marketplace_price']??null,'new_price'=>$c['new']['marketplace_price']??null,'confirmed_remote_price'=>$r['confirmed_remote_price']??($r['remote_confirmed_price']??null),'log_id'=>$logId]; }
    private function message(string $channel, array $r): string { $status=$r['status']??'error'; $blocker=$r['blocker']??($r['blockers'][0]??null); return match (true) { $status==='success' => 'Cena '.$this->label($channel).' została zaktualizowana i potwierdzona.', $blocker==='idempotent_price_already_confirmed' => 'Cena '.$this->label($channel).' została już wcześniej potwierdzona zdalnie.', $status==='write_accepted_unverified' => ($channel==='ebay_de' ? 'eBay DE przyjęło zmianę ceny, ale aktualizacja oczekuje na potwierdzenie.' : 'Ovoko przyjęło zmianę ceny, ale nie udało się jej jeszcze potwierdzić odczytem.'), $status==='skipped' => 'Pominięto synchronizację ceny na '.$this->label($channel).': '.$blocker.'.', default => 'Nie udało się zaktualizować ceny na '.$this->label($channel).'. Sprawdź log synchronizacji.', }; }
    private function label(string $channel): string { return match($channel) { 'allegro'=>'Allegro', 'ovoko'=>'Ovoko', 'ebay_de'=>'eBay DE', default=>$channel }; }
    private function writeLog(array $c,array $r): int { return MarketplaceSyncLog::query()->create(['marketplace'=>$c['channel'],'marketplace_listing_id'=>$c['listing_id'],'part_id'=>$c['part']->id,'action'=>'part_price_sync','status'=>$r['status'] ?? 'error','http_status'=>$r['http_status'] ?? null,'request_id'=>(string)Str::uuid(),'external_id'=>$c['external_id'],'message'=>$r['blocker'] ?? 'Marketplace price sync result.','payload'=>['old_price'=>$c['old']['marketplace_price']??null,'new_price'=>$c['new']['marketplace_price']??null,'source_currency'=>$c['new']['source_currency']??null,'marketplace_currency'=>$c['new']['marketplace_currency']??null,'source_field'=>$c['new']['source_field']??null,'conversion_rate'=>data_get($c,'new.conversion.rate'),'marketplace_account_id'=>$c['marketplace_account_id'],'listing_id'=>$c['listing_id'],'external_id'=>$c['external_id'],'sku'=>$c['sku'],'endpoint'=>$r['endpoint']??null,'request_summary'=>$r['request_summary']??null,'response_summary'=>$r['response_summary']??null,'read_after_write'=>$r['read_after_write']??null,'remote_confirmed_price'=>$r['remote_confirmed_price']??null,'final_success'=>$r['final_success']??false,'blocker'=>$r['blocker']??null,'api_business_status'=>$r['api_business_status']??null,'old_remote_price'=>$r['old_remote_price']??null,'new_requested_price'=>$r['new_requested_price']??($c['new']['marketplace_price']??null),'confirmed_remote_price'=>$r['remote_confirmed_price']??null,'correlation_id'=>$r['correlation_id']??($r['request_id']??null),'remote_quantity_before'=>$r['remote_quantity_before']??null,'remote_quantity_after'=>$r['remote_quantity_after']??null,'publication_status_before'=>$r['publication_status_before']??null,'publication_status_after'=>$r['publication_status_after']??null,'quantity_unchanged'=>$r['quantity_unchanged']??null,'publication_unchanged'=>$r['publication_unchanged']??null],'created_at'=>now()])->id; }
}
