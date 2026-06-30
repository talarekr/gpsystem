<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetLocalOrders extends Command
{
    protected $signature = 'marketplace:reset-local-orders {--dry-run : Preview only; default mode} {--apply : Delete local orders} {--confirm= : Required confirmation token for apply}';
    protected $description = 'Safely reset local test orders and direct order dependencies without touching marketplace logs/listings/parts/stock.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('confirm') !== 'reset-local-orders') {
            $this->error('Apply requires --confirm=reset-local-orders. No data changed.');
            return self::FAILURE;
        }

        $orderIds = Order::query()->pluck('id');
        $counts = [
            'orders' => $orderIds->count(),
            'order_items' => Schema::hasTable('order_items') ? DB::table('order_items')->whereIn('order_id', $orderIds)->count() : 0,
            'shipments_detached' => Schema::hasTable('shipments') ? DB::table('shipments')->whereIn('order_id', $orderIds)->count() : 0,
            'marketplace_sync_logs_untouched' => Schema::hasTable('marketplace_sync_logs') ? DB::table('marketplace_sync_logs')->count() : 0,
            'marketplace_listings_untouched' => Schema::hasTable('marketplace_listings') ? DB::table('marketplace_listings')->count() : 0,
            'parts_untouched' => Schema::hasTable('parts') ? DB::table('parts')->count() : 0,
        ];

        $this->table(['table/action', 'records'], collect($counts)->map(fn ($v, $k) => [$k, $v])->all());
        $this->line($apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (default). No data changed.');
        $this->line('Order items are deleted directly; shipments are detached because shipments.order_id uses nullOnDelete; MarketplaceSyncLog is not deleted.');

        if (! $apply) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($orderIds): void {
            if (Schema::hasTable('shipments')) {
                DB::table('shipments')->whereIn('order_id', $orderIds)->update(['order_id' => null]);
            }
            if (Schema::hasTable('order_items')) {
                DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            }
            Order::query()->whereIn('id', $orderIds)->delete();
        });

        $this->info('Local orders reset completed. MarketplaceSyncLog, marketplace_listings, parts and stock were not touched.');
        return self::SUCCESS;
    }
}
