<?php

namespace App\Services\Marketplace\Publishing;

class MarketplacePublishResult
{
    public function __construct(public readonly string $channel, public readonly array $data) {}
}
