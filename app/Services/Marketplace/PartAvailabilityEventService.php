<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\Api\EbayApiClient;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PartAvailabilityEventService
{
    public const EVENT_SOLD = 'sold';
    public const EVENT_RESTORED = 'restored';

    /** @var array<int, string> */
    private const TARGETS = ['allegro', 'ebay', 'ovoko'];

    /**
     * @param array{source_channel:string,part_id?:int|string|null,source_order_id?:string|null,source_order_item_id?:string|null,source_local_sale_id?:int|string|null,source_marketplace_item_id?:string|null,offer_id?:string|null,sku?:string|null,item_id?:string|null,external_listing_id?:string|null,reason?:string|null} $event
     */
    public function sold(array $event): array
    {
        return $this->handle(self::EVENT_SOLD, $event);
    }

    /** @param array<string, mixed> $event */
    public function restored(array $event): array
    {
        return $this->handle(self::EVENT_RESTORED, $event);
    }

    /** @param array<string, mixed> $event */
    public function handle(string $eventType, array $event): array
    {
        $source = $this->source((string) ($event['source_channel'] ?? 'manual_stock_change'));
        $part = $this->resolvePart($source, $event);
        $eventKey = $this->eventKey($eventType, $source, $event, $part?->id);

        if (! $this->isManualStockChangeWithoutExternalEventId($source, $event) && $this->alreadyProcessed($eventKey)) {
            $this->logEvent('local', null, $part?->id, $eventType, $source, 'availability_event', 'skipped', 'already_processed', $eventKey, $event, null);
            return ['ok' => true, 'status' => 'already_processed', 'part_id' => $part?->id, 'event_key' => $eventKey];
        }

        if (! $part) {
            $this->logEvent('local', null, null, $eventType, $source, 'resolve_part', 'skipped', 'missing_mapping', $eventKey, $event, null);
            return ['ok' => false, 'status' => 'missing_mapping', 'part_id' => null, 'event_key' => $eventKey];
        }

        DB::transaction(function () use ($part, $eventType, $source): void {
            /** @var Part $locked */
            $locked = Part::query()->whereKey($part->id)->lockForUpdate()->firstOrFail();
            if ($eventType === self::EVENT_SOLD) {
                $locked->forceFill([
                    'status' => 'sold',
                    'quantity' => 0,
                    'is_visible_storefront' => false,
                    'needs_listing' => false,
                    'sale_source' => $source,
                    'sold_at' => $locked->sold_at ?: now(),
                ])->save();
            } else {
                $locked->forceFill([
                    'status' => 'ready',
                    'quantity' => 1,
                    'is_visible_storefront' => true,
                    'needs_listing' => false,
                    'sale_source' => null,
                    'sold_at' => null,
                ])->save();
            }
        });

        $results = [];
        foreach (self::TARGETS as $target) {
            if ($this->matchesSourceTarget($source, $target) && ! ($eventType === self::EVENT_RESTORED && $target === 'allegro')) {
                if ($eventType === self::EVENT_SOLD && $source === 'allegro' && $target === 'allegro') {
                    $result = $this->reconcileAllegroSourceSale($part, $eventKey, $event, $source);
                    $results[$target] = ['status' => ($result['ok'] ?? false) ? 'success' : 'error', 'reason' => 'source_sync', 'message' => $result['message'] ?? null];
                    continue;
                }

                $this->logEvent($target, null, $part->id, $eventType, $source, 'skip_source_channel', 'skipped', 'source_channel', $eventKey, $event, null);
                $results[$target] = ['status' => 'skipped', 'reason' => 'source_channel'];
                continue;
            }

            $listing = $this->listingForTarget($part, $target);
            if (! $listing) {
                $this->logEvent($target, null, $part->id, $eventType, $source, 'availability_update', 'skipped', 'missing_mapping', $eventKey, $event, null);
                $results[$target] = ['status' => 'skipped', 'reason' => 'missing_mapping'];
                continue;
            }

            if (! ($eventType === self::EVENT_RESTORED && $target === 'allegro') && $this->targetAlreadyApplied($listing, $eventType)) {
                $this->logEvent($target, $listing, $part->id, $eventType, $source, 'availability_update', 'skipped', 'already_target_state', $eventKey, $event, null);
                $results[$target] = ['status' => 'skipped', 'reason' => 'already_target_state'];
                continue;
            }

            $externalId = $this->externalId($listing, $target);
            if (blank($externalId)) {
                $this->logEvent($target, $listing, $part->id, $eventType, $source, 'availability_update', 'skipped', 'unsupported_or_missing_mapping', $eventKey, $event, null);
                $results[$target] = ['status' => 'skipped', 'reason' => 'unsupported_or_missing_mapping'];
                continue;
            }

            $result = $this->sendTargetUpdate($listing, $target, $eventType, (string) $externalId);
            $status = ($result['ok'] ?? false) ? 'success' : 'error';
            $this->logEvent($target, $listing, $part->id, $eventType, $source, $result['action'] ?? 'availability_update', $status, null, $eventKey, $event, $result);
            $listingUpdate = [
                'sync_status' => $status === 'success' ? 'synced' : $listing->sync_status,
                'last_api_status' => $status,
                'last_error' => $status === 'success' ? null : ($result['message'] ?? 'Availability API failed'),
                'last_synced_at' => now(),
            ];
            if ($status === 'success') {
                $listingUpdate['quantity'] = $eventType === self::EVENT_SOLD ? 0 : 1;
                $listingUpdate['status'] = $eventType === self::EVENT_SOLD ? 'ended' : 'active';
            }
            $listing->forceFill($listingUpdate)->save();
            $results[$target] = ['status' => $status, 'message' => $result['message'] ?? null];
        }

        $this->logEvent('local', null, $part->id, $eventType, $source, 'availability_event', 'success', null, $eventKey, $event, ['results' => $results]);
        return ['ok' => true, 'status' => 'processed', 'part_id' => $part->id, 'event_key' => $eventKey, 'targets' => $results];
    }

    /** @param array<string, mixed> $event */
    private function resolvePart(string $source, array $event): ?Part
    {
        if (filled($event['part_id'] ?? null)) return Part::query()->find((int) $event['part_id']);
        $query = MarketplaceListing::query();
        if ($source === 'allegro' && filled($event['offer_id'] ?? null)) $query->where('marketplace', 'allegro')->where('external_offer_id', (string) $event['offer_id']);
        elseif ($source === 'ovoko' && filled($event['external_listing_id'] ?? $event['offer_id'] ?? $event['source_marketplace_item_id'] ?? null)) {
            $id = (string) ($event['external_listing_id'] ?? $event['offer_id'] ?? $event['source_marketplace_item_id']);
            $query->where('marketplace', 'ovoko')->where(fn ($q) => $q->where('external_listing_id', $id)->orWhere('external_offer_id', $id));
        }
        elseif (str_starts_with($source, 'ebay')) $query->where('marketplace', 'like', 'ebay%')->where(function ($q) use ($event): void { $q->when(filled($event['sku'] ?? null), fn ($qq) => $qq->orWhere('sku', (string) $event['sku']))->when(filled($event['offer_id'] ?? null), fn ($qq) => $qq->orWhere('external_offer_id', (string) $event['offer_id']))->when(filled($event['item_id'] ?? null), fn ($qq) => $qq->orWhere('external_listing_id', (string) $event['item_id'])); });
        else return null;
        return $query->with('part')->first()?->part;
    }

    private function listingForTarget(Part $part, string $target): ?MarketplaceListing
    { return MarketplaceListing::query()->where('part_id', $part->id)->when($target === 'ebay', fn ($q) => $q->where('marketplace', 'like', 'ebay%'), fn ($q) => $q->where('marketplace', $target))->first(); }

    private function sendTargetUpdate(MarketplaceListing $listing, string $target, string $eventType, string $externalId): array
    {
        $account = $listing->account ?: MarketplaceAccount::query()->where('marketplace', $listing->marketplace)->first();
        $qty = $eventType === self::EVENT_SOLD ? 0 : 1;
        return match ($target) {
            'allegro' => $eventType === self::EVENT_SOLD ? (new AllegroApiClient('allegro_main', $account))->endOffer($externalId) : $this->sendAllegroRestoreUpdate($listing, $externalId),
            'ovoko' => $eventType === self::EVENT_SOLD ? (new OvokoApiClient('ovoko', $account))->deactivatePart($externalId) : (new OvokoApiClient('ovoko', $account))->restorePart($externalId),
            'ebay' => (new EbayApiClient($listing->marketplace, $account))->setInventoryQuantity((string) ($listing->sku ?: $externalId), $qty, $listing->external_offer_id),
            default => ['ok' => false, 'action' => 'availability_update', 'message' => 'Unsupported marketplace.', 'response_summary' => ['reason' => 'unsupported_or_missing_mapping']],
        };
    }


    /** @param array<string, mixed> $event */
    private function reconcileAllegroSourceSale(Part $part, string $eventKey, array $event, string $source): array
    {
        $offerId = (string) ($event['offer_id'] ?? $event['source_marketplace_item_id'] ?? '');
        $listing = MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', 'allegro')->when($offerId !== '', fn ($q) => $q->where('external_offer_id', $offerId))->first();
        if (! $listing || blank($offerId)) {
            $this->logEvent('allegro', $listing, $part->id, self::EVENT_SOLD, $source, 'allegro_source_sale_reconcile', 'error', null, $eventKey, $event, ['ok' => false, 'message' => 'Missing local Allegro listing or offer id.', 'response_summary' => ['offer_id' => $offerId ?: null]]);
            return ['ok' => false, 'message' => 'Missing local Allegro listing or offer id.'];
        }

        $result = $this->allegroClient($listing)->getProductOffer($offerId, 'allegro_source_sale_reconcile');
        $this->applyAllegroRemoteState($listing, $result, false);
        $this->logEvent('allegro', $listing, $part->id, self::EVENT_SOLD, $source, 'allegro_source_sale_reconcile', ($result['ok'] ?? false) ? 'success' : 'error', null, $eventKey, $event, $result);

        return $result;
    }

    private function sendAllegroRestoreUpdate(MarketplaceListing $listing, string $externalId): array
    {
        $client = $this->allegroClient($listing);
        $steps = [];
        $preflight = $client->getProductOffer($externalId, 'allegro_restore_preflight');
        $steps[] = $preflight;
        if (! ($preflight['ok'] ?? false)) return $preflight + ['action' => 'allegro_restore_preflight', 'steps' => $steps];

        $body = $preflight['body'] ?? [];
        if (($body['archived'] ?? false) || ! in_array((string) ($body['sellingMode']['format'] ?? ''), ['', 'BUY_NOW', 'ADVERTISEMENT'], true)) {
            return ['ok' => false, 'action' => 'allegro_restore_preflight', 'message' => 'Allegro offer is archived or uses unsupported selling mode.', 'steps' => $steps, 'response_summary' => $preflight['response_summary'] ?? []];
        }

        if ((int) ($body['stock']['available'] ?? 0) < 1) {
            $stock = $client->updateProductOfferStock($externalId, 1);
            $steps[] = $stock;
            if (! ($stock['ok'] ?? false)) return $stock + ['steps' => $steps];
        }

        if ((string) ($body['publication']['status'] ?? '') !== 'ACTIVE') {
            $activate = $client->activateOffer($externalId);
            $activate['action'] = 'allegro_restore_activate';
            $steps[] = $activate;
            if (! ($activate['ok'] ?? false)) return $activate + ['steps' => $steps];
        }

        $confirm = $client->getProductOffer($externalId, 'allegro_restore_confirm');
        $steps[] = $confirm;
        $ok = ($confirm['ok'] ?? false) && (string) (($confirm['body']['publication']['status'] ?? '')) === 'ACTIVE' && (int) ($confirm['body']['stock']['available'] ?? 0) >= 1;

        return $confirm + ['ok' => $ok, 'action' => 'allegro_restore_confirm', 'message' => $ok ? 'Allegro restore confirmed.' : 'Allegro restore was not confirmed by final GET.', 'steps' => $steps];
    }

    private function allegroClient(MarketplaceListing $listing): AllegroApiClient
    {
        $account = $listing->account ?: MarketplaceAccount::query()->where('marketplace', $listing->marketplace)->first();
        return new AllegroApiClient('allegro_main', $account);
    }

    private function applyAllegroRemoteState(MarketplaceListing $listing, array $result, bool $forceActiveOnSuccess): void
    {
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $ok = (bool) ($result['ok'] ?? false);
        $remoteStatus = (string) ($body['publication']['status'] ?? '');
        $quantity = array_key_exists('available', $body['stock'] ?? []) ? (int) $body['stock']['available'] : $listing->quantity;
        $update = [
            'sync_status' => $ok ? 'synced' : $listing->sync_status,
            'last_api_status' => $ok ? 'success' : 'error',
            'last_error' => $ok ? null : ($result['message'] ?? 'Allegro read reconciliation failed'),
            'last_synced_at' => now(),
        ];
        if ($ok) {
            $update['quantity'] = $forceActiveOnSuccess ? 1 : $quantity;
            $update['status'] = $forceActiveOnSuccess ? 'active' : $this->mapAllegroStatus($remoteStatus, $quantity);
            $update['raw_payload'] = $body !== [] ? array_replace($listing->raw_payload ?: [], ['allegro_remote_state' => Arr::only($body, ['publication', 'stock', 'sellingMode', 'archived'])]) : $listing->raw_payload;
        }
        $listing->forceFill($update)->save();
    }

    private function mapAllegroStatus(string $remoteStatus, ?int $quantity): string
    {
        return match ($remoteStatus) {
            'ACTIVE' => (int) $quantity <= 0 ? 'active' : 'active',
            'ENDED' => 'ended',
            default => strtolower($remoteStatus) ?: 'unknown',
        };
    }

    private function externalId(MarketplaceListing $listing, string $target): ?string
    { return match ($target) { 'allegro' => $listing->external_offer_id ?: $listing->external_listing_id, 'ovoko' => $listing->external_listing_id ?: $listing->external_offer_id ?: Arr::get($listing->raw_payload ?: [], 'metadata.ovoko_part_id'), 'ebay' => $listing->sku ?: $listing->external_offer_id ?: $listing->external_listing_id, default => null } ?: null; }
    private function targetAlreadyApplied(MarketplaceListing $listing, string $eventType): bool { return $eventType === self::EVENT_SOLD ? (int) $listing->quantity === 0 : (int) $listing->quantity === 1 && str_contains(strtolower((string) $listing->status), 'active'); }
    private function alreadyProcessed(string $eventKey): bool { return MarketplaceSyncLog::query()->where('action', 'availability_event')->where('status', 'success')->where('payload->event_key', $eventKey)->exists(); }
    private function isManualStockChangeWithoutExternalEventId(string $source, array $event): bool { return $source === 'manual_stock_change' && blank($event['source_order_item_id'] ?? null) && blank($event['source_order_id'] ?? null) && blank($event['source_local_sale_id'] ?? null) && blank($event['source_marketplace_item_id'] ?? null); }
    private function eventKey(string $eventType, string $source, array $event, ?int $partId): string { return implode(':', array_filter([$eventType, $source, $event['source_order_item_id'] ?? null, $event['source_order_id'] ?? null, $event['source_local_sale_id'] ?? null, $event['source_marketplace_item_id'] ?? null, $partId])); }
    private function source(string $source): string { return match ($source) { 'sklep', 'store' => 'storefront', 'manual' => 'manual_stock_change', default => $source }; }
    private function matchesSourceTarget(string $source, string $target): bool { return $target === 'ebay' ? str_starts_with($source, 'ebay') : $source === $target; }

    private function logEvent(string $marketplace, ?MarketplaceListing $listing, ?int $partId, string $eventType, string $source, string $action, string $status, ?string $skippedReason, string $eventKey, array $event, ?array $result): void
    {
        MarketplaceSyncLog::query()->create(['marketplace' => $marketplace, 'marketplace_listing_id' => $listing?->id, 'part_id' => $partId, 'action' => $action, 'status' => $status, 'http_status' => $result['http_status'] ?? null, 'message' => $result['message'] ?? $skippedReason, 'external_id' => $this->externalIdForLog($listing), 'payload' => ['event_key' => $eventKey, 'event_type' => $eventType, 'source_channel' => $source, 'source_order_id' => $event['source_order_id'] ?? null, 'source_order_item_id' => $event['source_order_item_id'] ?? null, 'source_local_sale_id' => $event['source_local_sale_id'] ?? null, 'target_marketplace' => $marketplace, 'action' => $action, 'status' => $status, 'skipped_reason' => $skippedReason, 'request_summary' => $result['request_summary'] ?? [], 'response_summary' => $result['response_summary'] ?? [], 'input' => Arr::except($event, ['raw_payload'])], 'created_at' => now()]);
    }
    private function externalIdForLog(?MarketplaceListing $listing): ?string { return $listing ? ($listing->external_offer_id ?: $listing->external_listing_id ?: $listing->sku) : null; }
}
