<?php

namespace App\Services\Marketplace;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetLocalOrdersService
{
    public function run(bool $apply = false): array
    {
        $orderIds = Order::query()->pluck('id');
        $counts = [
            'orders' => $orderIds->count(),
            'order_items' => Schema::hasTable('order_items') ? DB::table('order_items')->whereIn('order_id', $orderIds)->count() : 0,
            'shipments_detached' => Schema::hasTable('shipments') ? DB::table('shipments')->whereIn('order_id', $orderIds)->count() : 0,
            'marketplace_sync_logs_untouched' => Schema::hasTable('marketplace_sync_logs') ? DB::table('marketplace_sync_logs')->count() : 0,
            'marketplace_listings_untouched' => Schema::hasTable('marketplace_listings') ? DB::table('marketplace_listings')->count() : 0,
            'parts_untouched' => Schema::hasTable('parts') ? DB::table('parts')->count() : 0,
        ];

        $changed = false;
        if ($apply) {
            DB::transaction(function () use ($orderIds, &$changed): void {
                if (Schema::hasTable('shipments')) {
                    DB::table('shipments')->whereIn('order_id', $orderIds)->update(['order_id' => null]);
                }
                if (Schema::hasTable('order_items')) {
                    DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
                }
                Order::query()->whereIn('id', $orderIds)->delete();
                $changed = true;
            });
        }

        return [
            'ok' => true,
            'dry_run' => ! $apply,
            'apply' => $apply,
            'changed' => $changed,
            'summary' => $counts,
            'message' => $apply
                ? 'Local orders reset completed. MarketplaceSyncLog, marketplace_listings, parts and stock were not touched.'
                : 'DRY-RUN only. No data changed.',
            'notes' => [
                'Order items are deleted directly during apply.',
                'Shipments are detached during apply because shipments.order_id uses nullOnDelete.',
                'MarketplaceSyncLog is not deleted.',
            ],
        ];
    }
}
