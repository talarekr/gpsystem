<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\OrderDeliveryDisplayResolver;
use Tests\TestCase;

class OrderDeliveryDisplayResolverTest extends TestCase
{
    public function test_allegro_delivery_lines_use_raw_payload_without_empty_placeholders(): void
    {
        $order = new Order([
            'marketplace' => 'allegro',
            'currency' => 'PLN',
            'raw_payload' => [
                'delivery' => [
                    'method' => ['name' => 'Dostawa przez sprzedającego', 'id' => 'method-id'],
                    'cost' => ['amount' => '30.00', 'currency' => 'PLN'],
                    'time' => [
                        'dispatch' => ['to' => '2026-06-24T23:59:00.000Z'],
                        'from' => '2026-06-26',
                        'to' => '2026-06-26',
                    ],
                ],
                'payment' => ['type' => 'CASH_ON_DELIVERY'],
                'shipmentSummary' => ['count' => 1, 'carrier' => 'Allegro', 'trackingNumber' => 'AD02NEFYK6'],
            ],
        ]);
        $order->setRelation('items', collect());
        $order->setRelation('shipments', collect());

        $this->assertSame([
            'Czas na wysłanie: do 2026-06-24 23:59',
            'Przewidywana dostawa: 2026-06-26',
            'Dostawa przez sprzedającego',
            'Pobranie',
            'Koszt dostawy: 30,00 PLN',
            'Liczba przesyłek: 1',
            'Przesyłka: Allegro AD02NEFYK6',
        ], app(OrderDeliveryDisplayResolver::class)->resolve($order));
    }
}
