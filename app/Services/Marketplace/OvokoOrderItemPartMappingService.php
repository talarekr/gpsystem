<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Part;
use Illuminate\Support\Facades\DB;

class OvokoOrderItemPartMappingService
{
    public function __construct(private readonly PartAvailabilityEventService $availabilityEvents) {}

    /** @return array<string, mixed> */
    public function preview(?string $ovokoOrderId = null, ?int $partId = null, ?string $marketplaceItemId = null): array
    {
        return $this->map($ovokoOrderId, $partId, $marketplaceItemId, false, true);
    }

    /** @return array<string, mixed> */
    public function apply(?string $ovokoOrderId = null, ?int $partId = null, ?string $marketplaceItemId = null, bool $runSoldFlow = true): array
    {
        return $this->map($ovokoOrderId, $partId, $marketplaceItemId, true, $runSoldFlow);
    }

    public function resolveListingForItem(OrderItem $item): ?MarketplaceListing
    {
        if ($item->marketplace !== 'ovoko') return null;
        $id = trim((string) $item->marketplace_item_id);
        if ($id === '') return null;

        return MarketplaceListing::query()
            ->where('marketplace', 'ovoko')
            ->where(function ($query) use ($id): void {
                $query->where('external_offer_id', $id)->orWhere('external_listing_id', $id);
            })
            ->whereNotNull('part_id')
            ->first();
    }

    /** @return array<string, mixed> */
    public function mapImportedItem(Order $order, OrderItem $item, bool $runSoldFlow): array
    {
        if ($order->marketplace !== 'ovoko' || $item->part_id) {
            return ['mapped' => false, 'reason' => 'not_needed', 'part_id' => $item->part_id];
        }

        $listing = $this->resolveListingForItem($item);
        if (! $listing) return ['mapped' => false, 'reason' => 'missing_listing', 'part_id' => null];

        $item->forceFill(['part_id' => $listing->part_id])->save();
        $sold = $runSoldFlow ? $this->dispatchSoldFlow($order, $item, (int) $listing->part_id) : null;

        return ['mapped' => true, 'reason' => 'mapped_by_marketplace_item_id', 'part_id' => (int) $listing->part_id, 'marketplace_listing_id' => $listing->id, 'sold_flow' => $sold];
    }

    /** @return array<string, mixed> */
    private function map(?string $ovokoOrderId, ?int $partId, ?string $marketplaceItemId, bool $write, bool $runSoldFlow): array
    {
        $errors = [];
        if ($write && (! $ovokoOrderId || ! $partId || ! $marketplaceItemId)) {
            $errors[] = 'Apply requires ovoko_order_id, marketplace_item_id and part_id so only one order item/part is mutated.';
        }

        $query = OrderItem::query()->with('order')->where('marketplace', 'ovoko');
        if ($ovokoOrderId) $query->where('marketplace_order_id', $ovokoOrderId);
        if ($marketplaceItemId) $query->where('marketplace_item_id', $marketplaceItemId);
        if ($partId) {
            $ids = MarketplaceListing::query()->where('marketplace', 'ovoko')->where('part_id', $partId)
                ->get(['external_offer_id', 'external_listing_id'])->flatMap(fn ($l) => [$l->external_offer_id, $l->external_listing_id])->filter()->map(fn ($v) => (string) $v)->unique()->values()->all();
            $query->whereIn('marketplace_item_id', $ids ?: ['__no_match__']);
        }

        $items = $query->limit($ovokoOrderId && $marketplaceItemId ? 2 : 100)->get();
        if ($write && $items->count() !== 1) $errors[] = 'Apply must match exactly one Ovoko order item.';

        $rows = [];
        $mapped = 0;
        foreach ($items as $item) {
            $listing = $this->resolveListingForItem($item);
            $matchedPartId = $listing?->part_id ? (int) $listing->part_id : null;
            $part = $matchedPartId ? Part::query()->find($matchedPartId) : null;
            $before = ['order_item' => $this->orderItemSnapshot($item), 'part' => $this->partSnapshot($part), 'listings' => $this->listingSnapshots($matchedPartId)];
            $after = $before;
            $sold = null;
            $logsBeforeId = (int) (MarketplaceSyncLog::query()->max('id') ?? 0);
            $canWriteItem = $listing && $write && empty($errors) && (! $partId || $matchedPartId === $partId);
            if ($canWriteItem) {
                DB::transaction(function () use ($item, $listing): void { $item->forceFill(['part_id' => $listing->part_id])->save(); });
                $item->refresh();
                $mapped++;
                if ($runSoldFlow && $item->order) $sold = $this->dispatchSoldFlow($item->order, $item, (int) $listing->part_id);
                $part?->refresh();
                $after = ['order_item' => $this->orderItemSnapshot($item), 'part' => $this->partSnapshot($part), 'listings' => $this->listingSnapshots($matchedPartId)];
            }
            $rows[] = [
                'ovoko_order_id' => $item->marketplace_order_id,
                'marketplace_item_id' => $item->marketplace_item_id,
                'before' => $before,
                'match' => $listing ? ['marketplace_item_id' => $item->marketplace_item_id, 'marketplace_listing_id' => $listing->id, 'part_id' => $listing->part_id, 'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id] : null,
                'would_dispatch_sold' => (bool) ($listing && $runSoldFlow),
                'sold_flow_service' => 'App\\Services\\Marketplace\\PartAvailabilityEventService::sold',
                'after' => $after,
                'sold_flow' => $sold,
                'marketplace_results' => $write ? $this->syncLogsAfter($logsBeforeId, $matchedPartId) : [],
            ];
        }

        return ['ok' => empty($errors), 'errors' => $errors, 'dry_run' => ! $write, 'local_update' => $write, 'sold_flow_requested' => $runSoldFlow, 'matched_count' => collect($rows)->whereNotNull('match')->count(), 'mapped_count' => $mapped, 'items' => $rows, 'safety_flags' => ['get_mutates' => false, 'requires_exact_order_item_for_apply' => true, 'ovoko_write' => false, 'allegro_write' => $write && $runSoldFlow, 'ebay_write' => $write && $runSoldFlow, 'ebay_relist' => false, 'store_deactivate_triggered' => $write && $runSoldFlow]];
    }

    /** @return array<string, mixed> */
    private function orderItemSnapshot(OrderItem $item): array { return ['id' => $item->id, 'part_id' => $item->part_id, 'marketplace_order_id' => $item->marketplace_order_id, 'marketplace_item_id' => $item->marketplace_item_id]; }
    /** @return array<string, mixed>|null */
    private function partSnapshot(?Part $part): ?array { return $part ? ['id' => $part->id, 'quantity' => $part->quantity, 'status' => $part->status, 'is_visible_storefront' => $part->is_visible_storefront, 'sale_source' => $part->sale_source, 'sold_at' => optional($part->sold_at)->toISOString()] : null; }
    /** @return array<int, array<string, mixed>> */
    private function listingSnapshots(?int $partId): array { if (! $partId) return []; return MarketplaceListing::query()->where('part_id', $partId)->orderBy('marketplace')->get()->map(fn (MarketplaceListing $l): array => ['id' => $l->id, 'marketplace' => $l->marketplace, 'status' => $l->status, 'quantity' => $l->quantity, 'external_offer_id' => $l->external_offer_id, 'external_listing_id' => $l->external_listing_id, 'url' => $l->url])->all(); }
    /** @return array<int, array<string, mixed>> */
    private function syncLogsAfter(int $id, ?int $partId): array { return MarketplaceSyncLog::query()->where('id', '>', $id)->when($partId, fn ($q) => $q->where(fn ($qq) => $qq->where('part_id', $partId)->orWhereNull('part_id')))->orderBy('id')->get()->map(fn (MarketplaceSyncLog $log): array => ['marketplace' => $log->marketplace, 'action' => $log->action, 'status' => $log->status, 'message' => $log->message, 'external_id' => $log->external_id])->all(); }

    /** @return array<string, mixed> */
    private function dispatchSoldFlow(Order $order, OrderItem $item, int $partId): array
    {
        return $this->availabilityEvents->sold(['source_channel' => 'ovoko', 'part_id' => $partId, 'source_order_id' => (string) $order->marketplace_order_id, 'source_order_item_id' => (string) $item->marketplace_item_id, 'source_marketplace_item_id' => (string) $item->marketplace_item_id, 'external_listing_id' => (string) $item->marketplace_item_id, 'offer_id' => $item->offer_id, 'sku' => $item->sku, 'reason' => 'ovoko_order_item_import_mapping']);
    }
}
