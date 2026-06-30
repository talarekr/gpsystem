<?php

namespace Tests\Unit;

use App\Services\Marketplace\MarketplaceOrderTimeService;
use PHPUnit\Framework\TestCase;

class MarketplaceOrderTimeServiceTest extends TestCase
{
    public function test_marketplace_utc_timestamps_are_converted_to_europe_warsaw_cest(): void
    {
        $service = new MarketplaceOrderTimeService();

        $this->assertSame('2026-06-29 23:44:35', $service->marketplaceUtcToLocalStorage('2026-06-29T21:44:35.000Z'));
        $this->assertSame('2026-06-29 19:59:03', $service->marketplaceUtcToLocalStorage('2026-06-29T17:59:03.074Z'));
    }
}
