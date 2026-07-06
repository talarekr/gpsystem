<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Admin\SalesAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_analytics_counts_shipped_marketplace_orders_by_order_date_and_channel(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 12:00:00'));

        $this->createOrder('ALG-1', 'allegro', 'new', 215.57, '2026-07-06 08:00:00');
        $this->createOrder('EBAY-1', 'eBay_DE', 'shipped', 100.00, '2026-07-06 09:00:00');
        $this->createOrder('OVO-1', 'ovoko', 'wysłane', 150.00, '2026-07-06 10:00:00');
        $this->createOrder('SHOP-1', 'local', 'completed', 50.00, '2026-07-06 11:00:00');

        $data = app(SalesAnalyticsService::class)->dashboardData('today');
        $channels = collect($data['channels'])->keyBy('key');

        $this->assertSame(4, $data['summary']['online_orders_count']);
        $this->assertSame(515.57, round($data['summary']['online_revenue_pln'], 2));
        $this->assertSame(1, $channels['allegro']['orders_count']);
        $this->assertSame(1, $channels['ebay']['orders_count']);
        $this->assertSame(1, $channels['ovoko']['orders_count']);
        $this->assertSame(1, $channels['shop']['orders_count']);
    }

    public function test_sales_analytics_excludes_only_non_sale_statuses(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 12:00:00'));

        foreach (['processing', 'paid', 'sent', 'fulfilled', 'completed'] as $index => $status) {
            $this->createOrder('SALE-'.$index, 'ebay_main', $status, 10.00, '2026-07-06 08:00:00');
        }

        foreach (['cancelled', 'canceled', 'refunded', 'returned', 'rejected', 'failed'] as $index => $status) {
            $this->createOrder('NO-SALE-'.$index, 'ovoko', $status, 99.00, '2026-07-06 08:00:00');
        }

        $data = app(SalesAnalyticsService::class)->dashboardData('today');
        $channels = collect($data['channels'])->keyBy('key');

        $this->assertSame(5, $data['summary']['online_orders_count']);
        $this->assertSame(5, $channels['ebay']['orders_count']);
        $this->assertSame(0, $channels['ovoko']['orders_count']);
    }

    public function test_sales_analytics_uses_ordered_at_instead_of_status_change_or_import_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 12:00:00'));

        Order::query()->create([
            'order_number' => 'OLD-ORDER-SHIPPED-TODAY',
            'marketplace' => 'ovoko',
            'marketplace_order_id' => 'OLD-ORDER-SHIPPED-TODAY',
            'ordered_at' => '2026-07-05 12:00:00',
            'status' => 'shipped',
            'status_changed_at' => '2026-07-06 09:00:00',
            'currency' => 'PLN',
            'subtotal' => 120.00,
            'shipping_total' => 0.00,
            'total' => 120.00,
            'customer_name' => 'Customer',
            'email' => 'customer@example.com',
            'phone' => '123456789',
            'address_line1' => 'Street 1',
            'postal_code' => '00-001',
            'city' => 'Warsaw',
            'created_at' => '2026-07-06 09:00:00',
            'updated_at' => '2026-07-06 09:00:00',
        ]);

        $data = app(SalesAnalyticsService::class)->dashboardData('today');
        $channels = collect($data['channels'])->keyBy('key');

        $this->assertSame(0, $channels['ovoko']['orders_count']);
        $this->assertSame(0, $data['summary']['online_orders_count']);
    }

    private function createOrder(string $number, string $marketplace, string $status, float $total, string $orderedAt): Order
    {
        return Order::query()->create([
            'order_number' => $number,
            'marketplace' => $marketplace,
            'marketplace_order_id' => $number,
            'ordered_at' => $orderedAt,
            'status' => $status,
            'currency' => 'PLN',
            'subtotal' => $total,
            'shipping_total' => 0.00,
            'total' => $total,
            'customer_name' => 'Customer',
            'email' => 'customer@example.com',
            'phone' => '123456789',
            'address_line1' => 'Street 1',
            'postal_code' => '00-001',
            'city' => 'Warsaw',
            'created_at' => $orderedAt,
            'updated_at' => $orderedAt,
        ]);
    }
}
