<?php

namespace App\Services\Marketplace;

use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PurgeMarketplaceOrdersService
{
    public const DEFAULT_MARKETPLACES = ['allegro', 'ebay', 'ovoko'];

    public function run(array $marketplaces, bool $apply = false, bool $onlyTestImport = false): array
    {
        $marketplaces = $this->normalizeMarketplaces($marketplaces);
        $orderIds = $this->orderIds($marketplaces, $onlyTestImport);
        $counts = $this->counts($orderIds);
        $exportPath = $this->export($marketplaces, $orderIds, $counts, $onlyTestImport);

        $changed = false;
        if ($apply) {
            DB::transaction(function () use ($orderIds, &$changed): void {
                if ($orderIds->isEmpty()) {
                    return;
                }

                if (Schema::hasTable('marketplace_sync_logs') && Schema::hasColumn('marketplace_sync_logs', 'order_id')) {
                    DB::table('marketplace_sync_logs')->whereIn('order_id', $orderIds)->update(['order_id' => null]);
                }

                if (Schema::hasTable('shipments') && Schema::hasColumn('shipments', 'order_id')) {
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
            'marketplaces' => $marketplaces,
            'only_test_import' => $onlyTestImport,
            'export_path' => $exportPath,
            'summary' => $counts,
            'message' => $apply
                ? 'Marketplace orders purge completed after backup export. Shipments and API logs were detached, not deleted.'
                : 'DRY-RUN only. Backup export prepared; no data changed.',
            'notes' => [
                'Scope is limited to orders.marketplace IN ('.implode(', ', $marketplaces).').',
                'Manual/shop orders with marketplace NULL or outside the selected marketplace list are not selected.',
                'Hard delete is used for orders and order_items because no soft-delete/archive columns exist on these tables.',
                'Shipments and marketplace_sync_logs are preserved and detached because their foreign keys are nullable with nullOnDelete.',
            ],
        ];
    }

    private function normalizeMarketplaces(array $marketplaces): array
    {
        $marketplaces = array_values(array_unique(array_filter(array_map(
            fn ($marketplace): string => strtolower(trim((string) $marketplace)),
            $marketplaces
        ))));

        if ($marketplaces === []) {
            $marketplaces = self::DEFAULT_MARKETPLACES;
        }

        $unsupported = array_values(array_diff($marketplaces, self::DEFAULT_MARKETPLACES));
        if ($unsupported !== []) {
            throw new InvalidArgumentException('Unsupported marketplace values: '.implode(', ', $unsupported));
        }

        return $marketplaces;
    }

    private function orderIds(array $marketplaces, bool $onlyTestImport): Collection
    {
        $query = Order::query()->whereIn('marketplace', $marketplaces)->orderBy('id');
        if ($onlyTestImport && Schema::hasColumn('orders', 'test_import')) {
            $query->where('test_import', true);
        }

        return $query->pluck('id');
    }

    private function counts(Collection $orderIds): array
    {
        $counts = [
            'orders' => $orderIds->count(),
            'order_items' => 0,
            'shipments_detached' => 0,
            'marketplace_sync_logs_detached' => 0,
            'manual_or_shop_orders_untouched' => Order::query()->where(function ($query): void {
                $query->whereNull('marketplace')->orWhereNotIn('marketplace', self::DEFAULT_MARKETPLACES);
            })->count(),
        ];

        if ($orderIds->isEmpty()) {
            return $counts;
        }

        if (Schema::hasTable('order_items')) {
            $counts['order_items'] = DB::table('order_items')->whereIn('order_id', $orderIds)->count();
        }
        if (Schema::hasTable('shipments') && Schema::hasColumn('shipments', 'order_id')) {
            $counts['shipments_detached'] = DB::table('shipments')->whereIn('order_id', $orderIds)->count();
        }
        if (Schema::hasTable('marketplace_sync_logs') && Schema::hasColumn('marketplace_sync_logs', 'order_id')) {
            $counts['marketplace_sync_logs_detached'] = DB::table('marketplace_sync_logs')->whereIn('order_id', $orderIds)->count();
        }

        return $counts;
    }

    private function export(array $marketplaces, Collection $orderIds, array $counts, bool $onlyTestImport): string
    {
        $timestamp = now()->format('Ymd_His');
        $path = 'exports/marketplace-orders-purge-'.$timestamp.'.json';
        $payload = [
            'created_at' => now()->toISOString(),
            'marketplaces' => $marketplaces,
            'only_test_import' => $onlyTestImport,
            'counts' => $counts,
            'orders' => [],
            'order_items' => [],
            'shipments' => [],
            'marketplace_sync_logs' => [],
        ];

        if ($orderIds->isNotEmpty()) {
            $payload['orders'] = Order::query()->whereIn('id', $orderIds)->orderBy('id')->get()->map(fn ($order): array => $order->getAttributes())->all();
            foreach (['order_items', 'shipments', 'marketplace_sync_logs'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'order_id')) {
                    $payload[$table] = DB::table($table)->whereIn('order_id', $orderIds)->orderBy('id')->get()->map(fn ($row): array => Arr::accessible($row) ? (array) $row : get_object_vars($row))->all();
                }
            }
        }

        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return storage_path('app/'.$path);
    }
}
