<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\OrderShippingPaymentDisplayResolver;
use Tests\TestCase;

class OrderShippingPaymentDisplayResolverTest extends TestCase
{
    public function test_allegro_cash_on_delivery_listing_hides_local_total_when_snapshot_amount_is_missing(): void
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

        $this->assertSame('Pobranie', $lines['payment']);
    }


    public function test_allegro_cash_on_delivery_listing_and_detail_share_payment_logic_with_detail_amount(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'currency' => 'PLN',
            'total' => '60.00',
            'raw_payload' => [
                'payment' => ['type' => 'CASH_ON_DELIVERY'],
            ],
        ]);

        $resolver = app(OrderShippingPaymentDisplayResolver::class);

        $this->assertSame('Pobranie', $resolver->resolve($order)['payment']);
        $this->assertSame('Pobranie · 60,00 PLN', $resolver->resolve($order, includeAmount: true)['payment']);
    }

    public function test_allegro_cash_on_delivery_detail_includes_local_total_when_snapshot_amount_is_missing(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'currency' => 'PLN',
            'total' => '60.00',
            'raw_payload' => [
                'payment' => ['type' => 'CASH_ON_DELIVERY'],
            ],
        ]);

        $lines = app(OrderShippingPaymentDisplayResolver::class)->resolve($order, includeAmount: true);

        $this->assertSame('Pobranie · 60,00 PLN', $lines['payment']);
    }

    public function test_allegro_cash_on_delivery_detail_prefers_snapshot_amount(): void
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

        $lines = app(OrderShippingPaymentDisplayResolver::class)->resolve($order, includeAmount: true);

        $this->assertSame('Pobranie · 60,00 PLN', $lines['payment']);
    }

    public function test_allegro_paid_order_listing_uses_paid_label_without_amount(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'payment_status' => 'PAID',
            'currency' => 'PLN',
            'total' => '60.00',
            'raw_payload' => [
                'payment' => ['type' => 'ONLINE', 'paidAmount' => ['amount' => '60.00', 'currency' => 'PLN']],
            ],
        ]);

        $lines = app(OrderShippingPaymentDisplayResolver::class)->resolve($order);

        $this->assertSame('Zapłacono', $lines['payment']);
    }

    public function test_allegro_paid_order_detail_includes_paid_amount(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'payment_status' => 'PAID',
            'currency' => 'PLN',
            'total' => '99.00',
            'raw_payload' => [
                'payment' => ['type' => 'ONLINE', 'paidAmount' => ['amount' => '60.00', 'currency' => 'PLN']],
            ],
        ]);

        $lines = app(OrderShippingPaymentDisplayResolver::class)->resolve($order, includeAmount: true);

        $this->assertSame('Zapłacono · 60,00 PLN', $lines['payment']);
    }
}
