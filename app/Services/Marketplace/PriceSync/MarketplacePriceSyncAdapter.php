<?php
namespace App\Services\Marketplace\PriceSync;
use App\Models\MarketplaceListing;
interface MarketplacePriceSyncAdapter { public function sync(MarketplaceListing $listing, array $price): array; }
