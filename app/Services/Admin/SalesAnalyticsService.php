<?php

namespace App\Services\Admin;

use App\Models\LocalSale;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class SalesAnalyticsService
{
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
        $shopOrdersCount = 0;
        $shopSalesPln = 0.0;

        if (Schema::hasTable('orders')) {
            $shopOrdersCount = $this->shopOrdersQuery($startsAt, $endsAt, ['new', 'processing'])->count();
            $shopSalesPln = (float) $this->shopOrdersQuery($startsAt, $endsAt, ['new', 'processing', 'completed'])->sum('total');
        }

        // eBay ma przygotowane pola EUR i kursu, ale na tym etapie nie pobieramy kursów z zewnętrznych API.
        return [
            ['key' => 'ebay', 'label' => 'eBay', 'badge' => 'eB', 'orders_count' => 0, 'sales_eur' => 0.0, 'exchange_rate' => null, 'sales_pln' => 0.0, 'note' => 'Oczekuje na integrację odczytu eBay.'],
            ['key' => 'ovoko', 'label' => 'Ovoko', 'badge' => 'Ov', 'orders_count' => 0, 'sales_pln' => 0.0, 'note' => 'Oczekuje na integrację odczytu Ovoko.'],
            ['key' => 'allegro', 'label' => 'Allegro', 'badge' => 'Al', 'orders_count' => 0, 'sales_pln' => 0.0, 'note' => 'Oczekuje na integrację odczytu Allegro.'],
            ['key' => 'shop', 'label' => 'Sklep', 'badge' => 'Sk', 'orders_count' => $shopOrdersCount, 'sales_pln' => $shopSalesPln],
        ];
    }

    /**
     * @param array<int, string> $statuses
     */
    private function shopOrdersQuery(CarbonInterface $startsAt, CarbonInterface $endsAt, array $statuses): Builder
    {
        return Order::query()
            ->whereBetween('created_at', [$startsAt, $endsAt])
            ->whereIn('status', $statuses)
            ->where(function (Builder $query): void {
                $query
                    ->where('meta->source', 'storefront')
                    ->orWhere('meta->channel', 'sklep')
                    ->orWhereNull('meta');
            });
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
