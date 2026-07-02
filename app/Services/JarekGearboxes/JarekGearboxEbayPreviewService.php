<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;
use Illuminate\Support\Str;

class JarekGearboxEbayPreviewService
{
    public function preview(JarekGearbox $gearbox, string $channel = 'ebay_de'): array
    {
        $sourcePrice = $gearbox->ebaySourcePrice();
        $finalPrice = $gearbox->ebayPreviewPrice();
        $images = array_values(array_filter($gearbox->images ?: ($gearbox->main_image_url ? [$gearbox->main_image_url] : [])));
        $blockers = [];
        if (blank($gearbox->title)) $blockers[] = 'Title missing.';
        if ($sourcePrice === null) $blockers[] = 'Source price missing.';
        if ((int) $gearbox->quantity <= 0) $blockers[] = 'Quantity must be greater than zero.';
        if ($images === []) $blockers[] = 'Images missing.';
        if (blank($gearbox->category_id)) $blockers[] = 'eBay category mapping/category must be reviewed manually for Jarek gearbox.';

        $sku = $gearbox->ebay_inventory_sku ?: 'JAREK-GB-'.$gearbox->allegro_offer_id;
        $description = $gearbox->description ?: nl2br(e($gearbox->plain_description ?: $gearbox->title));

        return [
            'ok' => true,
            'dry_run' => true,
            'live_publish_enabled' => false,
            'adapter' => 'temporary JarekGearbox -> eBay preview adapter; no Part row is created',
            'channel' => $channel,
            'jarek_gearbox_id' => $gearbox->id,
            'source' => ['model' => JarekGearbox::class, 'allegro_offer_id' => $gearbox->allegro_offer_id, 'source_account' => $gearbox->source_account],
            'price' => ['source_price' => $sourcePrice, 'source_currency' => $gearbox->currency, 'multiplier' => 1.25, 'final_ebay_price' => $finalPrice, 'final_currency' => $gearbox->currency],
            'planned_steps' => [
                'inventory_item_upsert' => ['would_send' => false, 'dry_run_only' => true],
                'offer_create_or_update' => ['would_send' => false, 'dry_run_only' => true],
                'offer_publish' => ['would_send' => false, 'dry_run_only' => true, 'requires_future_manual_confirm' => true],
            ],
            'inventory_item_payload' => $blockers === [] ? ['sku' => $sku, 'product' => ['title' => Str::limit($gearbox->title, 80, ''), 'description' => Str::limit(strip_tags($description), 400, ''), 'imageUrls' => $images], 'condition' => 'USED', 'availability' => ['shipToLocationAvailability' => ['quantity' => $gearbox->quantity]]] : null,
            'offer_payload' => $blockers === [] ? ['sku' => $sku, 'marketplaceId' => strtoupper($channel), 'format' => 'FIXED_PRICE', 'availableQuantity' => $gearbox->quantity, 'categoryId' => $gearbox->category_id, 'listingDescription' => $description, 'pricingSummary' => ['price' => ['value' => $finalPrice, 'currency' => $gearbox->currency]]] : null,
            'blockers' => $blockers,
            'warnings' => ['Preview only. No eBay API write, no inventory item, no offer, no publish, no Part mutation.'],
        ];
    }
}
