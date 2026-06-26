<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\OrderShippingPaymentDisplayResolver;
use Tests\TestCase;

class OrderShippingPaymentDisplayResolverTest extends TestCase
{
    public function test_allegro_cash_on_delivery_includes_local_total_when_snapshot_amount_is_missing(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'currency' => 'PLN',
            'total' => '60.00',
            'raw_payload' => [
                'payment' => ['type' => 'CASH_ON_DELIVERY'],
            ],
        ]);

        $lines = app(OrderShippingPaymentDisplayResolver::class)->resolve($order);

        $this->assertSame('Pobranie · 60,00 PLN', $lines['payment']);
    }

    public function test_allegro_cash_on_delivery_prefers_snapshot_amount(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'currency' => 'PLN',
            'total' => '99.00',
            'raw_payload' => [
                'payment' => [
                    'type' => 'CASH_ON_DELIVERY',
                    'cashOnDelivery' => ['amount' => '60.00', 'currency' => 'PLN'],
                ],
            ],
        ]);

        $lines = app(OrderShippingPaymentDisplayResolver::class)->resolve($order);

        $this->assertSame('Pobranie · 60,00 PLN', $lines['payment']);
    }

    public function test_allegro_paid_order_uses_paid_label(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'payment_status' => 'PAID',
            'raw_payload' => [
                'payment' => ['type' => 'ONLINE'],
            ],
        ]);

        $lines = app(OrderShippingPaymentDisplayResolver::class)->resolve($order);

        $this->assertSame('Zapłacono', $lines['payment']);
    }
}
