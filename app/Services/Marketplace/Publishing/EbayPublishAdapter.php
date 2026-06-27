<?php

namespace App\Services\Marketplace\Publishing;

class EbayPublishAdapter extends BaseMarketplacePublishAdapter
{
    protected function channel(): string { return 'ebay_de'; }
    protected function marketplace(): string { return 'ebay_de'; }
}
