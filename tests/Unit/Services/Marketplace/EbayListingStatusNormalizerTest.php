<?php

namespace Tests\Unit\Services\Marketplace;

use App\Services\Marketplace\EbayListingStatusNormalizer;
use PHPUnit\Framework\TestCase;

class EbayListingStatusNormalizerTest extends TestCase
{
    public function test_active_browse_status_allows_checkmark_and_blocks_relisting(): void
    {
        $result = (new EbayListingStatusNormalizer())->normalize(['http_status' => 200, 'api_listing_status' => 'active']);

        $this->assertSame('active', $result['normalized_status']);
        $this->assertTrue($result['should_show_checkmark']);
        $this->assertFalse($result['should_allow_relisting']);
    }

    public function test_past_end_date_is_ended_and_allows_relisting(): void
    {
        $result = (new EbayListingStatusNormalizer())->normalize(['http_status' => 200, 'api_listing_status' => 'unknown', 'end_date' => '2026-05-31T12:00:00.000Z']);

        $this->assertSame('ended', $result['normalized_status']);
        $this->assertFalse($result['should_show_checkmark']);
        $this->assertTrue($result['should_allow_relisting']);
    }

    public function test_not_found_allows_relisting(): void
    {
        $result = (new EbayListingStatusNormalizer())->normalize(['http_status' => 404, 'api_listing_status' => 'not_found']);

        $this->assertSame('not_found', $result['normalized_status']);
        $this->assertFalse($result['item_found']);
        $this->assertTrue($result['should_allow_relisting']);
    }

    public function test_rate_limit_is_unknown_not_ended(): void
    {
        $result = (new EbayListingStatusNormalizer())->normalize(['http_status' => 429, 'api_listing_status' => null]);

        $this->assertSame('unknown', $result['normalized_status']);
        $this->assertSame('transient_api', $result['error_type']);
        $this->assertFalse($result['should_allow_relisting']);
    }
}
