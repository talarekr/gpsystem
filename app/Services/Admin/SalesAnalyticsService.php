<?php

namespace App\Services\Admin;

use App\Models\LocalSale;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

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
     *     summary: array{online_revenue_pln: float, online_orders_count: int, local_sales_pln: float, local_sales_count: int},
     *     channels: array<int, array{key: string, label: string, badge: string, orders_count: int, sales_pln: float, sales_eur?: float, exchange_rate?: float|null, note?: string}>
     * }
     */
    public function dashboardData(string $rangeKey): array
    {
        $range = $this->resolveRange($rangeKey);
        $channels = $this->onlineChannels($range['starts_at'], $range['ends_at']);
        $localSales = $this->localSales($range['starts_at'], $range['ends_at']);

        return [
            'range' => $range,
            'summary' => [
                'online_revenue_pln' => array_sum(array_column($channels, 'sales_pln')),
                'online_orders_count' => array_sum(array_column($channels, 'orders_count')),
                'local_sales_pln' => $localSales['sales_pln'],
                'local_sales_count' => $localSales['orders_count'],
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
        // TODO: Podłączyć odczyt zamówień online, gdy pojawią się modele/tabele integracji sklepu, Ovoko, Allegro i eBay.
        // eBay ma przygotowane pola EUR i kursu, ale na tym etapie nie pobieramy kursów z zewnętrznych API.
        return [
            ['key' => 'ebay', 'label' => 'eBay', 'badge' => 'eB', 'orders_count' => 0, 'sales_eur' => 0.0, 'exchange_rate' => null, 'sales_pln' => 0.0, 'note' => 'Oczekuje na integrację odczytu eBay.'],
            ['key' => 'ovoko', 'label' => 'Ovoko', 'badge' => 'Ov', 'orders_count' => 0, 'sales_pln' => 0.0, 'note' => 'Oczekuje na integrację odczytu Ovoko.'],
            ['key' => 'allegro', 'label' => 'Allegro', 'badge' => 'Al', 'orders_count' => 0, 'sales_pln' => 0.0, 'note' => 'Oczekuje na integrację odczytu Allegro.'],
            ['key' => 'shop', 'label' => 'Sklep', 'badge' => 'Sk', 'orders_count' => 0, 'sales_pln' => 0.0, 'note' => 'Oczekuje na model zamówień sklepu.'],
        ];
    }

    /**
     * @return array{orders_count: int, sales_pln: float}
     */
    private function localSales(CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $query = LocalSale::query()->whereBetween('sold_at', [$startsAt, $endsAt]);

        return [
            'orders_count' => (int) (clone $query)->count(),
            'sales_pln' => (float) (clone $query)->sum('amount'),
        ];
    }
}
