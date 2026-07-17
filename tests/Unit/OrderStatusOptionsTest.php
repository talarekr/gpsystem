<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\Admin\OrderStatusOptions;
use PHPUnit\Framework\TestCase;

class OrderStatusOptionsTest extends TestCase
{
    public function test_it_returns_allegro_statuses_with_cancelled_and_on_hold(): void
    {
        $this->assertSame([
            'new' => 'NOWE',
            'processing' => 'W REALIZACJI',
            'ready_to_ship' => 'DO WYSŁANIA',
            'ready_for_pickup' => 'DO ODBIORU',
            'shipped' => 'WYSŁANE',
            'picked_up' => 'ODEBRANE',
            'cancelled' => 'ANULOWANE',
            'on_hold' => 'WSTRZYMANE',
        ], OrderStatusOptions::optionsForSource('allegro'));
    }

    public function test_it_returns_source_specific_safe_statuses(): void
    {
        $this->assertSame(['new' => 'NOWE', 'processing' => 'W REALIZACJI', 'shipped' => 'WYSŁANE'], OrderStatusOptions::optionsForSource('ebay'));
        $this->assertSame(['new' => 'NOWE', 'processing' => 'W REALIZACJI', 'ready_to_ship' => 'DO WYSŁANIA', 'shipped' => 'WYSŁANE'], OrderStatusOptions::optionsForSource('ovoko'));
        $this->assertSame(['new' => 'NOWE', 'processing' => 'W REALIZACJI', 'shipped' => 'WYSŁANE'], OrderStatusOptions::optionsForSource('unknown'));
    }

    public function test_it_maps_selected_status_and_falls_back_to_new(): void
    {
        $this->assertSame('processing', OrderStatusOptions::selectedValueForOrder(new Order([
            'marketplace' => 'allegro',
            'marketplace_status' => 'READY_FOR_PROCESSING',
        ])));

        $this->assertSame('new', OrderStatusOptions::selectedValueForOrder(new Order([
            'marketplace' => 'ebay',
            'status' => 'ready_for_pickup',
        ])));
    }
}
