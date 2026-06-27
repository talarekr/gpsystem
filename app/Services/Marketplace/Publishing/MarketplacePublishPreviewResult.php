<?php

namespace App\Services\Marketplace\Publishing;

class MarketplacePublishPreviewResult
{
    public function __construct(public readonly string $channel, public readonly array $data) {}
}
