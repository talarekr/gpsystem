<?php

namespace App\Services\Marketplace\Publishing;

class MarketplacePublishCommand
{
    public function __construct(
        public readonly bool $dryRun,
        public readonly bool $confirm,
        /** @var array<int, string> */ public readonly array $channels,
        public readonly bool $marketplacePublishEnabled,
    ) {}
}
