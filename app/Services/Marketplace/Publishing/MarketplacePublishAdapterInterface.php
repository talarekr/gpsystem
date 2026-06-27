<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\Part;

interface MarketplacePublishAdapterInterface
{
    public function preview(Part $part): MarketplacePublishPreviewResult;

    public function publish(Part $part, MarketplacePublishCommand $command): MarketplacePublishResult;
}
