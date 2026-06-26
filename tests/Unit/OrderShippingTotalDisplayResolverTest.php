<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\OrderShippingTotalDisplayResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OrderShippingTotalDisplayResolverTest extends TestCase
{
    public function test_allegro_shipping_total_prefers_delivery_cost_amount_from_raw_payload(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'currency' => 'PLN',
            'total' => '60.00',
            'raw_payload' => [
                'delivery' => [
                    'cost' => ['amount' => '30.00', 'currency' => 'PLN'],
                ],
            ],
        ]);

        $shipping = app(OrderShippingTotalDisplayResolver::class)->resolve($order);

        $this->assertSame(30.0, $shipping['amount']);
        $this->assertSame('PLN', $shipping['currency']);
        $this->assertSame('raw_payload.delivery.cost.amount', $shipping['source']);
    }

    public function test_shipping_total_uses_safe_total_minus_items_fallback_when_dedicated_amount_is_missing(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'currency' => 'PLN',
            'total' => '60.00',
            'raw_payload' => [],
        ]);
        $order->setRelation('items', new Collection([
            new OrderItem(['line_total' => '30.00', 'currency' => 'PLN']),
        ]));

        $shipping = app(OrderShippingTotalDisplayResolver::class)->resolve($order);

        $this->assertSame(30.0, $shipping['amount']);
        $this->assertSame('PLN', $shipping['currency']);
        $this->assertSame('fallback.total_minus_items_total', $shipping['source']);
    }

    public function test_shipping_total_does_not_use_difference_fallback_when_item_currency_differs(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'currency' => 'PLN',
            'total' => '60.00',
            'raw_payload' => [],
        ]);
        $order->setRelation('items', new Collection([
            new OrderItem(['line_total' => '30.00', 'currency' => 'EUR']),
        ]));

        $this->assertNull(app(OrderShippingTotalDisplayResolver::class)->resolve($order));
    }
}
