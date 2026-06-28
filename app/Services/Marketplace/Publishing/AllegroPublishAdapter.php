<?php

namespace App\Services\Marketplace\Publishing;

class AllegroPublishAdapter extends BaseMarketplacePublishAdapter
{
    protected function channel(): string { return 'allegro_main'; }
    protected function marketplace(): string { return 'allegro'; }
}
