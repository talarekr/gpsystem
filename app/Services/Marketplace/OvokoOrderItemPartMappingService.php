<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class OvokoOrderItemPartMappingService
{
    public function __construct(private readonly PartAvailabilityEventService $availabilityEvents) {}

    /** @return array<string, mixed> */
    public function preview(?string $ovokoOrderId = null, ?int $partId = null): array
    {
        return $this->map($ovokoOrderId, $partId, false, false);
    }

    /** @return array<string, mixed> */
    public function apply(?string $ovokoOrderId = null, ?int $partId = null, bool $runSoldFlow = false): array
    {
        return $this->map($ovokoOrderId, $partId, true, $runSoldFlow);
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
    private function map(?string $ovokoOrderId, ?int $partId, bool $write, bool $runSoldFlow): array
    {
        $query = OrderItem::query()->with('order')->where('marketplace', 'ovoko')->whereNull('part_id');
        if ($ovokoOrderId) $query->where('marketplace_order_id', $ovokoOrderId);
        if ($partId) {
            $ids = MarketplaceListing::query()->where('marketplace', 'ovoko')->where('part_id', $partId)
                ->get(['external_offer_id', 'external_listing_id'])->flatMap(fn ($l) => [$l->external_offer_id, $l->external_listing_id])->filter()->map(fn ($v) => (string) $v)->unique()->values()->all();
            $query->whereIn('marketplace_item_id', $ids ?: ['__no_match__']);
        }

        $items = $query->limit(100)->get();
        $rows = [];
        $mapped = 0;
        foreach ($items as $item) {
            $listing = $this->resolveListingForItem($item);
            $before = ['order_item_id' => $item->id, 'part_id' => $item->part_id];
            $after = $before;
            $sold = null;
            if ($listing && $write) {
                DB::transaction(function () use ($item, $listing): void { $item->forceFill(['part_id' => $listing->part_id])->save(); });
                $item->refresh();
                $after['part_id'] = $item->part_id;
                $mapped++;
                if ($runSoldFlow && $item->order) $sold = $this->dispatchSoldFlow($item->order, $item, (int) $listing->part_id);
            }
            $rows[] = ['ovoko_order_id' => $item->marketplace_order_id, 'marketplace_item_id' => $item->marketplace_item_id, 'before' => $before, 'match' => $listing ? ['marketplace_listing_id' => $listing->id, 'part_id' => $listing->part_id, 'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id] : null, 'after' => $after, 'sold_flow' => $sold];
        }

        return ['ok' => true, 'dry_run' => ! $write, 'local_update' => $write, 'sold_flow_requested' => $runSoldFlow, 'matched_count' => collect($rows)->whereNotNull('match')->count(), 'mapped_count' => $mapped, 'items' => $rows, 'safety_flags' => ['ovoko_write' => false, 'allegro_write' => $write && $runSoldFlow, 'ebay_write' => $write && $runSoldFlow, 'store_deactivate_triggered' => $write && $runSoldFlow]];
    }

    /** @return array<string, mixed> */
    private function dispatchSoldFlow(Order $order, OrderItem $item, int $partId): array
    {
        return $this->availabilityEvents->sold(['source_channel' => 'ovoko', 'part_id' => $partId, 'source_order_id' => (string) $order->marketplace_order_id, 'source_order_item_id' => (string) $item->marketplace_item_id, 'source_marketplace_item_id' => (string) $item->marketplace_item_id, 'external_listing_id' => (string) $item->marketplace_item_id, 'offer_id' => $item->offer_id, 'sku' => $item->sku, 'reason' => 'ovoko_order_item_import_mapping']);
    }
}
