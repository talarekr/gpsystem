<?php

namespace App\Services\Admin;

use App\Models\LocalSale;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesAnalyticsService
{
    public function __construct(private readonly CurrencyConversionService $currencyConversion) {}

    /**
     * @return array<string, string>
     */
    public function ranges(): array
    {
        return [
            'today' => 'Dziś',
            'this_week' => 'W tym tygodniu',
            'last_7_days' => 'Ostatnie 7 dni',
            'this_month' => 'W tym miesiącu',
            'last_30_days' => 'Ostatnie 30 dni',
            'this_year' => 'W tym roku',
        ];
    }

    /**
     * @return array{
     *     range: array{key: string, label: string, starts_at: CarbonInterface, ends_at: CarbonInterface},
     *     summary: array{online_revenue_pln: float, online_orders_count: int, local_sales_pln: float, local_sales_count: int, total_sales_pln: float},
     *     channels: array<int, array{key: string, label: string, badge: string, orders_count: int, sales_pln: float, sales_eur?: float, exchange_rate?: float|null, note?: string}>
     * }
     */
    public function dashboardData(string $rangeKey): array
    {
        $range = $this->resolveRange($rangeKey);
        $channels = $this->onlineChannels($range['starts_at'], $range['ends_at']);
        $localSales = $this->localSales($range['starts_at'], $range['ends_at']);

        $onlineRevenuePln = array_sum(array_column($channels, 'sales_pln'));

        return [
            'range' => $range,
            'summary' => [
                'online_revenue_pln' => $onlineRevenuePln,
                'online_orders_count' => array_sum(array_column($channels, 'orders_count')),
                'local_sales_pln' => $localSales['sales_pln'],
                'local_sales_count' => $localSales['orders_count'],
                'total_sales_pln' => $onlineRevenuePln + $localSales['sales_pln'],
            ],
            'channels' => $channels,
        ];
    }

    /**
     * @return array{key: string, label: string, starts_at: CarbonInterface, ends_at: CarbonInterface}
     */
    public function resolveRange(string $rangeKey): array
    {
        $key = array_key_exists($rangeKey, $this->ranges()) ? $rangeKey : 'last_30_days';
        $now = CarbonImmutable::now();

        [$startsAt, $endsAt] = match ($key) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_7_days' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
        };

        return [
            'key' => $key,
            'label' => $this->ranges()[$key],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, badge: string, orders_count: int, sales_pln: float, sales_eur?: float, exchange_rate?: float|null, note?: string}>
     */
    private function onlineChannels(CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $channels = [
            'ebay' => ['key' => 'ebay', 'label' => 'eBay', 'badge' => 'eB', 'orders_count' => 0, 'sales_eur' => 0.0, 'exchange_rate' => null, 'sales_pln' => 0.0],
            'ovoko' => ['key' => 'ovoko', 'label' => 'Ovoko', 'badge' => 'Ov', 'orders_count' => 0, 'sales_pln' => 0.0],
            'allegro' => ['key' => 'allegro', 'label' => 'Allegro', 'badge' => 'Al', 'orders_count' => 0, 'sales_pln' => 0.0],
            'shop' => ['key' => 'shop', 'label' => 'Sklep', 'badge' => 'Sk', 'orders_count' => 0, 'sales_pln' => 0.0],
        ];

        if (! Schema::hasTable('orders')) {
            return array_values($channels);
        }

        $excludedStatuses = ['cancelled', 'canceled', 'refunded', 'returned', 'rejected', 'failed'];

        $orders = Order::query()
            ->with('items:id,order_id,marketplace,currency')
            ->whereBetween(DB::raw('COALESCE(ordered_at, created_at)'), [$startsAt, $endsAt])
            ->where(function ($query) use ($excludedStatuses): void {
                $query->whereNull('status')
                    ->orWhereNotIn(DB::raw('LOWER(status)'), $excludedStatuses);
            })
            ->where(function ($query) use ($excludedStatuses): void {
                $query->whereNull('marketplace_status')
                    ->orWhereNotIn(DB::raw('LOWER(marketplace_status)'), $excludedStatuses);
            })
            ->get(['id', 'marketplace', 'currency', 'total', 'meta', 'ordered_at', 'created_at']);

        foreach ($orders as $order) {
            $channel = $this->normalizeChannel($this->orderSource($order));

            if (! array_key_exists($channel, $channels)) {
                $channel = 'shop';
            }

            $total = (float) $order->total;
            $currency = strtoupper((string) ($order->currency ?: 'PLN'));
            $channels[$channel]['orders_count']++;

            $conversion = $this->currencyConversion->toPln($total, $currency, $order->ordered_at ?: $order->created_at);

            if ($channel === 'ebay' && $currency === 'EUR') {
                $channels[$channel]['sales_eur'] += $total;
            }
            if ($conversion['converted_amount_pln'] !== null) {
                $channels[$channel]['sales_pln'] += $conversion['converted_amount_pln'];
            } else {
                $channels[$channel]['unconverted_orders_count'] = ($channels[$channel]['unconverted_orders_count'] ?? 0) + 1;
                $channels[$channel]['conversion_warnings'][] = ['order_id' => $order->id, 'currency' => $currency, 'warning' => $conversion['warning']];
            }
        }

        return array_values($channels);
    }

    private function orderSource(Order $order): ?string
    {
        if (filled($order->marketplace)) {
            return (string) $order->marketplace;
        }

        $metaSource = data_get($order->meta, 'source') ?: data_get($order->meta, 'channel');
        if (filled($metaSource)) {
            return (string) $metaSource;
        }

        $itemMarketplace = $order->items->first(fn ($item) => filled($item->marketplace))?->marketplace;

        return filled($itemMarketplace) ? (string) $itemMarketplace : null;
    }

    private function normalizeChannel(?string $source): string
    {
        $source = str($source ?? '')->lower()->trim()->toString();

        return match (true) {
            $source === '', in_array($source, ['sklep', 'local', 'store', 'storefront', 'shop'], true) => 'shop',
            in_array($source, ['sprzedaż lokalna', 'sprzedaz lokalna', 'local_sale', 'local sale'], true) => 'local_sale',
            str_starts_with($source, 'allegro') => 'allegro',
            str_starts_with($source, 'ovoko') => 'ovoko',
            str_starts_with($source, 'ebay') => 'ebay',
            default => 'shop',
        };
    }

    /**
     * @return array{orders_count: int, sales_pln: float}
     */
    private function localSales(CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        if (! Schema::hasTable('local_sales')) {
            return ['orders_count' => 0, 'sales_pln' => 0.0];
        }

        $query = LocalSale::query()->whereBetween('sold_at', [$startsAt, $endsAt]);

        return [
            'orders_count' => (int) (clone $query)->count(),
            'sales_pln' => (float) (clone $query)->sum('amount'),
        ];
    }
}
