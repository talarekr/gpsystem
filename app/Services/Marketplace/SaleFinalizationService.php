<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\Api\EbayApiClient;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaleFinalizationService
{
    private const ACTION = 'sale_finalization_end_listing';
    private const ENDED_STATUSES = ['ended', 'inactive', 'sold', 'archived', 'deleted', 'completed', 'closed', 'fulfilled'];

    public function dryRun(Order $order): array
    {
        return $this->buildOrderSummary($order->loadMissing('items'), false);
    }

    public function applyForOrder(Order $order): array
    {
        $summary = DB::transaction(function () use ($order): array {
            /** @var Order $locked */
            $locked = Order::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $summary = $this->buildOrderSummary($locked, true);

            foreach ($summary['parts'] as $partItem) {
                if (! empty($partItem['blockers'])) {
                    continue;
                }

                /** @var Part $part */
                $part = Part::query()->whereKey($partItem['part_id'])->lockForUpdate()->firstOrFail();
                $this->markPartSold($part, $summary['sale_source'], $locked->ordered_at ?: $locked->created_at ?: now());
                $part->save();

                foreach ($partItem['source_local_listing_ids'] as $listingId) {
                    MarketplaceListing::query()->whereKey($listingId)->update([
                        'status' => 'sold',
                        'quantity' => 0,
                        'sync_status' => 'sold',
                        'last_api_status' => 'sold',
                        'last_synced_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return $summary;
        });

        foreach ($summary['blockers'] as $blocked) {
            $exists = MarketplaceSyncLog::query()
                ->where('action', 'sale_finalization_blocked')
                ->where('order_id', $summary['order_id'])
                ->where('message', 'missing_part_mapping')
                ->exists();
            if (! $exists) {
                MarketplaceSyncLog::query()->create([
                    'marketplace' => $summary['sale_source'] ?: 'unknown',
                    'order_id' => $summary['order_id'],
                    'action' => 'sale_finalization_blocked',
                    'status' => 'blocked',
                    'message' => implode(',', $blocked['blockers'] ?? ['missing_part_mapping']),
                    'payload' => ['triggered_by_order' => true, 'sale_source' => $summary['sale_source'], 'order_id' => $summary['order_id'], 'external_order_id' => $summary['external_order_id'], 'blocker' => $blocked],
                    'created_at' => now(),
                ]);
            }
        }

        foreach ($summary['parts'] as $partItem) {
            foreach ($partItem['marketplace_listings'] as $item) {
                if (($item['action'] ?? null) !== 'would_end') {
                    continue;
                }

                $listing = MarketplaceListing::query()->find($item['marketplace_listing_id']);
                if (! $listing || $this->isEnded($listing)) {
                    continue;
                }

                $alreadyLogged = MarketplaceSyncLog::query()
                    ->where('action', self::ACTION)
                    ->where('marketplace_listing_id', $listing->id)
                    ->where('order_id', $summary['order_id'])
                    ->exists();
                if ($alreadyLogged) {
                    continue;
                }

                $result = $this->endListing($listing, (string) $item['external_id']);
                $this->writeLog($listing, (string) $item['external_id'], $result, $summary);

                if ($result['ok']) {
                    $listing->forceFill(['status' => 'ended', 'quantity' => 0, 'sync_status' => 'ended', 'last_api_status' => 'ended', 'last_error' => null, 'last_synced_at' => now()])->save();
                    $summary['ended_marketplaces'][] = $item + ['response_summary' => $result['response_summary'] ?? []];
                } else {
                    $listing->forceFill(['last_error' => $result['message'] ?? 'Marketplace end listing failed', 'last_synced_at' => now()])->save();
                    $summary['failed_marketplaces'][] = $item + ['response_summary' => $result['response_summary'] ?? [], 'message' => $result['message'] ?? null];
                }
            }
        }

        $summary['dry_run'] = false;
        $summary['marketplace_write'] = true;
        $summary['storefront_available'] = false;

        return $summary;
    }

    public function dryRunPart(Part $part, string $saleSource = 'local_sale'): array
    {
        $order = $this->syntheticPartOrder($part, $saleSource);
        $summary = $this->buildOrderSummary($order, false);
        $summary['part_id'] = $part->id;
        $summary['storefront_available'] = Part::query()->whereKey($part->id)->storefrontVisible()->exists();
        return $summary;
    }

    public function applyForPart(Part $part, string $saleSource = 'local_sale'): array
    {
        $order = $this->syntheticPartOrder($part, $saleSource);
        $summary = $this->buildOrderSummary($order, true);

        DB::transaction(function () use ($part, $saleSource): void {
            $locked = Part::query()->whereKey($part->id)->lockForUpdate()->firstOrFail();
            $this->markPartSold($locked, $saleSource, now());
            $locked->save();
        });

        foreach ($summary['parts'][0]['marketplace_listings'] ?? [] as $item) {
            if (($item['action'] ?? null) !== 'would_end') continue;
            $listing = MarketplaceListing::query()->find($item['marketplace_listing_id']);
            if (! $listing || $this->isEnded($listing)) continue;
            $result = $this->endListing($listing, (string) $item['external_id']);
            $this->writeLog($listing, (string) $item['external_id'], $result, $summary);
            if ($result['ok']) { $listing->forceFill(['status'=>'ended','quantity'=>0,'sync_status'=>'ended','last_api_status'=>'ended','last_error'=>null,'last_synced_at'=>now()])->save(); $summary['ended_marketplaces'][] = $item; }
            else { $listing->forceFill(['last_error'=>$result['message'] ?? 'Marketplace end listing failed','last_synced_at'=>now()])->save(); $summary['failed_marketplaces'][] = $item; }
        }

        $summary['dry_run'] = false; $summary['marketplace_write'] = true; $summary['local_product_sold'] = true; $summary['storefront_available'] = false;
        return $summary;
    }


    private function syntheticPartOrder(Part $part, string $saleSource): Order
    {
        $order = new Order(['marketplace' => $saleSource, 'order_number' => 'LOCAL-PART-'.$part->id]);
        $order->exists = false;
        $item = new OrderItem(['part_id' => $part->id, 'quantity' => 1]);
        $order->setRelation('items', collect([$item]));

        return $order;
    }

    private function buildOrderSummary(Order $order, bool $apply): array
    {
        $source = $this->saleSource($order);
        $parts = [];
        foreach ($order->items as $item) {
            $resolution = $this->resolvePart($item, $source);
            if (! $resolution['part']) {
                $parts[] = ['order_item_id' => $item->id, 'part_id' => null, 'sale_source' => $source, 'blockers' => ['missing_part_mapping'], 'marketplace_listings' => []];
                continue;
            }
            $part = $resolution['part'];
            $parts[$part->id] = [
                'order_item_id' => $item->id,
                'part_id' => $part->id,
                'sale_source' => $source,
                'would_set_local_product_sold' => $part->status !== 'sold',
                'would_set_quantity_zero' => (int) $part->quantity !== 0,
                'storefront_available_after_apply' => false,
                'source_local_listing_ids' => $resolution['source_listing_ids'],
                'marketplace_listings' => $this->listingItems($part, $source, $resolution['source_listing_ids']),
                'blockers' => [],
            ];
        }
        $items = collect($parts)->flatMap(fn ($p) => $p['marketplace_listings'])->values()->all();
        return ['ok' => true, 'order_id' => $order->exists ? $order->id : null, 'external_order_id' => $order->marketplace_order_id, 'order_number' => $order->order_number, 'source_channel' => $order->marketplace ?: Arr::get($order->meta ?: [], 'source', 'storefront'), 'sale_source' => $source, 'dry_run' => ! $apply, 'marketplace_write' => false, 'storefront_available' => false, 'parts' => array_values($parts), 'marketplace_listings' => $items, 'ended_marketplaces' => [], 'failed_marketplaces' => [], 'blockers' => array_values(array_filter($parts, fn ($p) => ! empty($p['blockers']))), 'local_product_sold' => $apply];
    }

    private function saleSource(Order $order): string { $m = strtolower((string) ($order->marketplace ?: Arr::get($order->meta ?: [], 'source', 'storefront'))); return str_starts_with($m, 'ebay') ? 'ebay' : (in_array($m, ['allegro','ovoko','local_sale'], true) ? $m : 'storefront'); }
    private function markPartSold(Part $part, string $source, \DateTimeInterface $soldAt): void { $part->forceFill(['status'=>'sold','quantity'=>0,'is_visible_storefront'=>false,'needs_listing'=>false,'sale_source'=>$part->status === 'sold' && filled($part->sale_source) ? $part->sale_source : $source,'sold_at'=>$part->sold_at ?: $soldAt]); }

    private function resolvePart(OrderItem $item, string $source): array
    {
        if ($item->part_id && ($part = Part::query()->find($item->part_id))) return ['part' => $part, 'source_listing_ids' => $this->sourceListingIds($part, $source, $item)];
        $listing = MarketplaceListing::query()->where(function ($q) use ($item) { $q->where('external_offer_id', $item->offer_id)->orWhere('external_listing_id', $item->offer_id)->orWhere('sku', $item->sku); })->first();
        if ($listing?->part) return ['part' => $listing->part, 'source_listing_ids' => [$listing->id]];
        $part = filled($item->sku) ? Part::query()->where('sku', $item->sku)->first() : null;
        return ['part' => $part, 'source_listing_ids' => $part ? $this->sourceListingIds($part, $source, $item) : []];
    }

    private function sourceListingIds(Part $part, string $source, OrderItem $item): array { return MarketplaceListing::query()->where('part_id', $part->id)->where(fn($q) => $q->where('marketplace', $source)->orWhere('marketplace', 'like', $source.'%'))->where(fn($q) => $q->where('external_offer_id', $item->offer_id)->orWhere('external_listing_id', $item->offer_id)->orWhere('sku', $item->sku))->pluck('id')->all(); }
    private function listingItems(Part $part, string $source, array $sourceListingIds): array { return MarketplaceListing::query()->where('part_id', $part->id)->get()->map(function (MarketplaceListing $l) use ($source, $sourceListingIds): array { $externalId = $this->externalId($l); $ended = $this->isEnded($l); $isSource = in_array($l->id, $sourceListingIds, true) || ($source !== 'storefront' && ($l->marketplace === $source || str_starts_with((string) $l->marketplace, $source))); $blocker = blank($externalId) && ! $isSource ? 'missing_external_id' : null; return ['marketplace'=>$l->marketplace,'marketplace_listing_id'=>$l->id,'external_id'=>$externalId,'mapping_ready'=>filled($externalId),'status'=>$l->status,'blocker'=>$blocker,'action'=>$ended ? 'skip' : ($isSource ? 'mark_source_sold_local_only' : ($blocker ? 'blocked' : 'would_end')),'reason'=>$ended ? 'already_ended_or_inactive' : ($isSource ? 'source_marketplace_order_no_api_end' : $blocker)]; })->all(); }
    private function isEnded(MarketplaceListing $l): bool { $status = strtolower((string) ($l->status ?? $l->last_api_status ?? '')); return in_array($status, self::ENDED_STATUSES, true) || str_contains($status, 'end'); }
    private function externalId(MarketplaceListing $l): ?string { return match (true) { $l->marketplace === 'allegro' => $l->external_offer_id ?: $l->external_listing_id, $l->marketplace === 'ovoko' => $l->external_listing_id ?: $l->external_offer_id ?: Arr::get($l->raw_payload ?: [], 'metadata.ovoko_part_id'), str_starts_with((string) $l->marketplace, 'ebay') => $l->external_offer_id ?: $l->external_listing_id ?: $l->sku, default => $l->external_offer_id ?: $l->external_listing_id, } ?: null; }
    private function endListing(MarketplaceListing $l, string $externalId): array { $account = $l->account ?: MarketplaceAccount::query()->where('marketplace', $l->marketplace)->first(); return match (true) { $l->marketplace === 'allegro' => (new AllegroApiClient('allegro_main', $account))->endOffer($externalId), $l->marketplace === 'ovoko' => (new OvokoApiClient('ovoko', $account))->deactivatePart($externalId), str_starts_with((string) $l->marketplace, 'ebay') => (new EbayApiClient($l->marketplace, $account))->endOffer($externalId, $l->sku), default => ['ok'=>false,'message'=>'Unsupported marketplace','response_summary'=>['reason'=>'unsupported_marketplace']], }; }
    private function writeLog(MarketplaceListing $l, string $externalId, array $result, array $summary): void { MarketplaceSyncLog::query()->create(['marketplace'=>$l->marketplace,'marketplace_listing_id'=>$l->id,'part_id'=>$l->part_id,'order_id'=>$summary['order_id'] ?? null,'action'=>self::ACTION,'status'=>($result['ok'] ?? false) ? 'success' : 'error','http_status'=>$result['http_status'] ?? null,'message'=>$result['message'] ?? null,'external_id'=>$externalId,'payload'=>['marketplace_write'=>true,'triggered_by_order'=>true,'sale_source'=>$summary['sale_source'] ?? null,'order_id'=>$summary['order_id'] ?? null,'external_order_id'=>$summary['external_order_id'] ?? null,'request_summary'=>$result['request_summary'] ?? [],'response_summary'=>$result['response_summary'] ?? []],'created_at'=>now()]); }
}
