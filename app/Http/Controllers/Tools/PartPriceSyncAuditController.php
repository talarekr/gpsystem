<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Models\Part;
use App\Services\Marketplace\PriceSync\PartPriceResolver;
use App\Services\Marketplace\PriceSync\PartPriceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartPriceSyncAuditController
{
    public function __invoke(Request $request, Part $part, PartPriceResolver $resolver, PartPriceSyncService $sync): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole([UserRole::OwnerAdmin->value]), 403);
        $part->loadMissing('marketplaceListings.account');
        $prices = ['store'=>['source_field'=>'parts.price','stored_source_value'=>$part->price,'normalized_price'=>app(\App\Services\Marketplace\PriceSync\PriceNormalizer::class)->normalize($part->price),'source_currency'=>'PLN','marketplace_currency'=>'PLN']];
        foreach (['allegro','ovoko','ebay_de'] as $channel) $prices[$channel] = $resolver->resolve($part, $channel);
        $channels = [];
        foreach (['allegro','ovoko','ebay_de'] as $channel) {
            $ctx = $sync->context($part, $channel, $prices[$channel], $prices[$channel]);
            $pre = $sync->preflight($ctx);
            $channels[$channel] = [
                'stored_source_value'=>$prices[$channel]['source_value'] ?? null,'source_field'=>$prices[$channel]['source_field'] ?? null,'normalized_price'=>$prices[$channel]['marketplace_price'] ?? null,'marketplace_price'=>$prices[$channel]['marketplace_price'] ?? null,'source_currency'=>$prices[$channel]['source_currency'] ?? null,'marketplace_currency'=>$prices[$channel]['marketplace_currency'] ?? null,'conversion_result'=>$prices[$channel]['conversion'] ?? null,'current_listing_found'=>(bool)$ctx['listing'],'marketplace_account_id'=>$ctx['marketplace_account_id'],'listing_id'=>$ctx['listing_id'],'external_ids'=>['external_id'=>$ctx['external_id']],'sku'=>$ctx['sku'],'listing_type'=>$ctx['listing_type'],'last_confirmed_listing_price'=>$ctx['listing']?->price,'changed'=>$ctx['changed'],'preflight_blockers'=>$pre['blockers'],'can_sync_price'=>$pre['blockers']===[],'enabled'=>$ctx['enabled'],'channel_allowed'=>$ctx['channel_allowed'],
            ] + ($channel==='ebay_de' ? ['conversion_rate'=>data_get($prices,'ebay_de.conversion.rate'),'conversion_source'=>data_get($prices,'ebay_de.conversion.source'),'conversion_date'=>data_get($prices,'ebay_de.conversion.effective_date'),'price_only_write_supported'=>false,'quantity_mutation_risk'=>true,'read_after_write_endpoint'=>'GET /sell/inventory/v1/offer/{offerId}'] : []);
        }
        return response()->json(['ok'=>true,'read_only'=>true,'no_mutation'=>true,'external_requests'=>false,'part_id'=>$part->id,'prices'=>$prices,'channels'=>$channels]);
    }
}
