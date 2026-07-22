<?php
namespace App\Services\Marketplace\PriceSync;
use App\Models\MarketplaceListing;
class OvokoPriceSyncAdapter implements MarketplacePriceSyncAdapter { public function sync(MarketplaceListing $listing, array $price): array { return ['status'=>'blocked','blocker'=>'ovoko_price_write_endpoint_not_confirmed','endpoint'=>null,'request_summary'=>['price'=>$price['marketplace_price'],'currency'=>$price['marketplace_currency']],'final_success'=>false]; } }
