<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\Part;

class AllegroPublishAdapter extends BaseMarketplacePublishAdapter
{
    protected function channel(): string { return 'allegro_main'; }
    protected function marketplace(): string { return 'allegro'; }

    public function publish(Part $part, MarketplacePublishCommand $command): MarketplacePublishResult
    {
        return $this->blocked('allegro_publish_disabled_parameters_preview_only');
    }
}
