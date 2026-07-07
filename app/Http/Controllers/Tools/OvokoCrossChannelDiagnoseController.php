<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class OvokoCrossChannelDiagnoseController extends Controller
{
    public function __invoke(Request $request): JsonResponse|View
    {
        $partId = (int) $request->query('part_id');
        $payload = $partId > 0 ? $this->diagnose($partId) : [
            'ok' => false,
            'read_only' => true,
            'marketplace_write' => false,
            'error' => 'Missing required positive part_id query parameter.',
        ];

        if ($request->expectsJson() || $request->query('format') === 'json') {
            return response()->json($payload);
        }

        return view('admin.tools.orders.ovoko-cross-channel-diagnose', ['payload' => $payload]);
    }

    private function diagnose(int $partId): array
    {
        $part = Part::query()->find($partId);
        $listings = MarketplaceListing::query()->where('part_id', $partId)->orderBy('marketplace')->orderBy('id')->get();
        $ovokoListings = $listings->where('marketplace', 'ovoko')->values();
        $ovokoExternalIds = $ovokoListings->flatMap(fn (MarketplaceListing $listing): array => array_values(array_filter([
            $listing->external_offer_id,
            $listing->external_listing_id,
            Arr::get($listing->raw_payload ?: [], 'metadata.ovoko_part_id'),
            Arr::get($listing->raw_payload ?: [], 'ovoko_part_id'),
        ], fn ($value): bool => filled($value)) ))->map(fn ($value): string => (string) $value)->unique()->values()->all();

        $orders = $this->ovokoOrders($partId, $ovokoExternalIds);
        $orderItems = $orders->flatMap(fn (Order $order) => $order->items)->values();
        $logs = $this->logs($partId, $orders->pluck('marketplace_order_id')->filter()->map(fn ($id): string => (string) $id)->all(), $orderItems->pluck('marketplace_item_id')->filter()->map(fn ($id): string => (string) $id)->all());
        $availabilityLogs = $logs->where('action', 'availability_event')->values();

        $summary = [
            'ovoko_order_seen' => $orders->isNotEmpty(),
            'ovoko_order_mapped_to_part' => $orderItems->contains(fn (OrderItem $item): bool => (int) $item->part_id === $partId),
            'local_part_marked_sold' => $part ? ($part->status === 'sold' || (int) $part->quantity === 0 || $part->adminLocalAvailability() === 'sold') : false,
            'cross_channel_dispatch_triggered' => $availabilityLogs->contains(fn (MarketplaceSyncLog $log): bool => data_get($log->payload, 'event_type') === 'sold' && data_get($log->payload, 'source_channel') === 'ovoko'),
            'allegro_end_triggered' => $this->targetTriggered($logs, 'allegro'),
            'ebay_end_triggered' => $this->targetTriggered($logs, 'ebay'),
            'store_deactivate_triggered' => $part ? ((bool) $part->is_visible_storefront === false && ($part->status === 'sold' || (int) $part->quantity === 0)) : false,
        ];

        return [
            'ok' => true,
            'read_only' => true,
            'marketplace_write' => false,
            'route' => '/admin/tools/orders/ovoko-cross-channel-diagnose',
            'part_id' => $partId,
            'summary' => $summary + ['likely_missing_step' => $this->likelyMissingStep($summary, $part, $ovokoListings->isNotEmpty())],
            'part' => $this->partPayload($part),
            'ovoko' => [
                'local_mapping_exists' => $ovokoListings->isNotEmpty(),
                'ovoko_part_ids' => $ovokoExternalIds,
                'listings' => $ovokoListings->map(fn (MarketplaceListing $listing): array => $this->listingPayload($listing))->all(),
                'orders' => $orders->map(fn (Order $order): array => $this->orderPayload($order, $partId))->all(),
            ],
            'flow_comparison' => $this->flowComparison($logs),
            'marketplace_listings' => $listings->map(fn (MarketplaceListing $listing): array => $this->listingPayload($listing))->all(),
            'jobs_events' => $this->jobsEvents($logs),
            'ovoko_scheduler' => $this->schedulerPayload(),
            'blockers' => $this->blockers($summary, $part, $ovokoListings->isNotEmpty()),
        ];
    }

    private function partPayload(?Part $part): ?array
    {
        if (! $part) return null;
        return ['part_id' => $part->id, 'status' => $part->status, 'quantity' => $part->quantity, 'adminLocalAvailability' => $part->adminLocalAvailability(), 'store_visibility' => (bool) $part->is_visible_storefront, 'needs_listing' => (bool) $part->needs_listing, 'sale_source' => $part->sale_source, 'sold_at' => optional($part->sold_at)->toISOString(), 'updated_at' => optional($part->updated_at)->toISOString()];
    }

    private function listingPayload(MarketplaceListing $listing): array
    {
        return ['id' => $listing->id, 'marketplace' => $listing->marketplace, 'status' => $listing->status, 'url' => $listing->url, 'last_api_status' => $listing->last_api_status, 'last_error' => $listing->last_error, 'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id, 'sku' => $listing->sku, 'quantity' => $listing->quantity, 'sync_status' => $listing->sync_status, 'match_status' => $listing->match_status, 'last_synced_at' => optional($listing->last_synced_at)->toISOString(), 'last_seen_at' => optional($listing->last_seen_at)->toISOString()];
    }

    private function orderPayload(Order $order, int $partId): array
    {
        return ['id' => $order->id, 'marketplace' => $order->marketplace, 'ovoko_order_id' => $order->marketplace_order_id, 'ordered_at' => optional($order->ordered_at)->toISOString(), 'imported_at' => optional($order->imported_at)->toISOString(), 'status' => $order->status, 'marketplace_status' => $order->marketplace_status, 'items' => $order->items->map(fn (OrderItem $item): array => ['id' => $item->id, 'marketplace_item_id' => $item->marketplace_item_id, 'offer_id' => $item->offer_id, 'sku' => $item->sku, 'part_id' => $item->part_id, 'mapped_to_requested_part' => (int) $item->part_id === $partId, 'quantity' => $item->quantity, 'product_name' => $item->product_name])->all()];
    }

    private function ovokoOrders(int $partId, array $externalIds)
    {
        return Order::query()->with('items')->where('marketplace', 'ovoko')->where(function ($query) use ($partId, $externalIds): void {
            $query->whereHas('items', fn ($items) => $items->where('part_id', $partId));
            foreach ($externalIds as $id) {
                $query->orWhereHas('items', fn ($items) => $items->where('offer_id', $id)->orWhere('marketplace_item_id', $id)->orWhere('sku', $id));
            }
        })->latest('ordered_at')->limit(20)->get();
    }

    private function logs(int $partId, array $orderIds, array $itemIds)
    {
        return MarketplaceSyncLog::query()->where(function ($query) use ($partId, $orderIds, $itemIds): void {
            $query->where('part_id', $partId);
            foreach ($orderIds as $id) $query->orWhere('payload->source_order_id', $id);
            foreach ($itemIds as $id) $query->orWhere('payload->source_order_item_id', $id)->orWhere('payload->source_marketplace_item_id', $id);
        })->latest('created_at')->limit(100)->get();
    }

    private function targetTriggered($logs, string $target): bool
    {
        return $logs->contains(fn (MarketplaceSyncLog $log): bool => ($target === 'ebay' ? str_starts_with((string) $log->marketplace, 'ebay') : $log->marketplace === $target) && in_array($log->action, ['allegro_end_offer', 'ebay_set_inventory_quantity', 'availability_update', 'local_sale_end_listing'], true));
    }

    private function flowComparison($logs): array
    {
        return ['common_service' => 'App\\Services\\Marketplace\\PartAvailabilityEventService::sold', 'ovoko_uses_common_service_when_order_item_imported_live' => true, 'evidence_logs' => $logs->whereIn('action', ['availability_event', 'availability_update', 'allegro_end_offer', 'ebay_set_inventory_quantity', 'crm/changePartStatus'])->map(fn (MarketplaceSyncLog $log): array => ['id' => $log->id, 'marketplace' => $log->marketplace, 'action' => $log->action, 'status' => $log->status, 'source_channel' => data_get($log->payload, 'source_channel'), 'event_type' => data_get($log->payload, 'event_type'), 'message' => $log->message, 'created_at' => optional($log->created_at)->toISOString()])->values()->all()];
    }

    private function jobsEvents($logs): array
    {
        return ['allegro_end' => $this->jobStatus($logs, 'allegro'), 'ebay_end' => $this->jobStatus($logs, 'ebay'), 'store_deactivate' => $this->jobStatus($logs, 'local'), 'recent_logs' => $logs->map(fn (MarketplaceSyncLog $log): array => ['id' => $log->id, 'marketplace' => $log->marketplace, 'action' => $log->action, 'status' => $log->status, 'message' => $log->message, 'created_at' => optional($log->created_at)->toISOString()])->all()];
    }

    private function jobStatus($logs, string $marketplace): array
    {
        $matched = $logs->filter(fn (MarketplaceSyncLog $log): bool => $marketplace === 'ebay' ? str_starts_with((string) $log->marketplace, 'ebay') : $log->marketplace === $marketplace)->values();
        $latest = $matched->first();
        return ['status' => $latest ? ($latest->status ?: 'created') : 'not_created', 'log_id' => $latest?->id, 'action' => $latest?->action, 'message' => $latest?->message, 'timestamp' => optional($latest?->created_at)->toISOString()];
    }

    private function schedulerPayload(): array
    {
        $latest = MarketplaceSyncLog::query()->where(function ($query): void { $query->where('marketplace', 'ovoko')->orWhere('marketplace', 'marketplace_orders')->orWhere('action', 'scheduled_order_sync'); })->whereIn('action', ['GET orders', 'marketplace_orders_import', 'scheduled_order_sync'])->latest('created_at')->first();
        return ['configured_enabled' => (bool) config('marketplace_order_sync.enabled', false), 'configured_channels' => config('marketplace_order_sync.channels'), 'handles_ovoko_orders' => collect(explode(',', implode(',', (array) config('marketplace_order_sync.channels', []))))->map(fn ($channel): string => strtolower(trim((string) $channel)))->contains(fn (string $channel): bool => str_contains($channel, 'ovoko')), 'latest_order_sync_log' => $latest ? ['id' => $latest->id, 'marketplace' => $latest->marketplace, 'action' => $latest->action, 'status' => $latest->status, 'message' => $latest->message, 'created_at' => optional($latest->created_at)->toISOString(), 'payload' => $latest->payload] : null];
    }

    private function blockers(array $summary, ?Part $part, bool $hasOvokoMapping): array
    {
        return array_values(array_filter([
            $part ? null : 'part_not_found',
            $hasOvokoMapping ? null : 'missing_ovoko_marketplace_listing_mapping',
            $summary['ovoko_order_seen'] ? null : 'ovoko_order_not_seen_locally',
            $summary['ovoko_order_mapped_to_part'] ? null : 'ovoko_order_item_not_mapped_to_requested_part',
            $summary['cross_channel_dispatch_triggered'] ? null : 'sold_availability_event_not_found_for_ovoko',
        ]));
    }

    private function likelyMissingStep(array $summary, ?Part $part, bool $hasOvokoMapping): ?string
    {
        if (! $part) return 'part_not_found';
        if (! $hasOvokoMapping) return 'create_or_restore_ovoko_marketplace_listing_mapping';
        if (! $summary['ovoko_order_seen']) return 'ovoko_order_import_missing_or_scheduler_not_fetching_orders';
        if (! $summary['ovoko_order_mapped_to_part']) return 'ovoko_order_item_import_missing_part_id_mapping';
        if (! $summary['cross_channel_dispatch_triggered']) return 'ovoko_order_import_did_not_call_PartAvailabilityEventService_sold';
        if (! $summary['allegro_end_triggered'] || ! $summary['ebay_end_triggered']) return 'target_marketplace_availability_update_missing_or_failed';
        return null;
    }
}
