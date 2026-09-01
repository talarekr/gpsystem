<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Admin\CurrencyConversionService;
use App\Services\Admin\SalesAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesAnalyticsCurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-06 12:00:00');
    }

    public function test_huf_uses_100_unit_quote_and_analytics_aggregates_converted_pln_without_mutating_order(): void
    {
        Http::fake(['api.nbp.pl/*' => Http::response(['rates' => [['mid' => 0.0105, 'effectiveDate' => '2026-07-03', 'no' => '128/A/NBP/2026']]])]);
        $order = $this->order('HUF-1', 250000, 'HUF');

        $conversion = app(CurrencyConversionService::class)->toPln($order->total, $order->currency, $order->ordered_at);
        $dashboard = app(SalesAnalyticsService::class)->dashboardData('today');

        $this->assertSame(100, $conversion['exchange_rate_unit']);
        $this->assertSame(1.05, $conversion['exchange_rate']);
        $this->assertSame(2625.0, $conversion['converted_amount_pln']);
        $this->assertSame(2625.0, $dashboard['summary']['online_revenue_pln']);
        $this->assertNotSame(250000.0, $dashboard['summary']['online_revenue_pln']);
        $this->assertSame('250000.00', $order->fresh()->total);
        $this->assertSame('HUF', $order->fresh()->currency);
    }

    public function test_czk_converts_and_pln_remains_unchanged(): void
    {
        Http::fake(['api.nbp.pl/*' => Http::response(['rates' => [['mid' => 0.17, 'effectiveDate' => '2026-07-03', 'no' => '128/A/NBP/2026']]])]);
        $service = app(CurrencyConversionService::class);
        $this->assertSame(170.0, $service->toPln(1000, 'CZK', '2026-07-06')['converted_amount_pln']);
        $this->assertSame(123.45, $service->toPln(123.45, 'PLN', '2026-07-06')['converted_amount_pln']);
        Http::assertSentCount(1);
    }

    public function test_missing_rate_never_falls_back_to_one_to_one(): void
    {
        Http::fake(['api.nbp.pl/*' => Http::response([], 404)]);
        $this->order('NO-RATE', 999, 'XYZ');
        $dashboard = app(SalesAnalyticsService::class)->dashboardData('today');

        $this->assertSame(0.0, $dashboard['summary']['online_revenue_pln']);
        $channel = collect($dashboard['channels'])->firstWhere('key', 'ovoko');
        $this->assertSame(1, $channel['unconverted_orders_count']);
    }

    private function order(string $number, float $total, string $currency): Order
    {
        return Order::query()->create(['order_number' => $number, 'marketplace_order_id' => $number, 'marketplace' => 'ovoko', 'ordered_at' => '2026-07-06 09:00:00', 'status' => 'paid', 'currency' => $currency, 'subtotal' => $total, 'shipping_total' => 0, 'total' => $total, 'customer_name' => 'Test', 'email' => 'test@example.com', 'phone' => '1', 'address_line1' => 'Street', 'postal_code' => '00-001', 'city' => 'Warsaw']);
    }
}
