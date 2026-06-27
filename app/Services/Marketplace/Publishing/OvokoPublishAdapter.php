<?php

namespace App\Services\Marketplace\Publishing;

class OvokoPublishAdapter extends BaseMarketplacePublishAdapter
{
    protected function channel(): string { return 'ovoko'; }
    protected function marketplace(): string { return 'ovoko'; }
}
