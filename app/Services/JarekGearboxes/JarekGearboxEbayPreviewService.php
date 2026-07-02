<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;

class JarekGearboxEbayPreviewService
{
    public function build(JarekGearbox $gearbox): array
    {
        $sourcePrice = (float) ($gearbox->price ?? 0);
        $finalPrice = round($sourcePrice * 1.25, 2);
        $blockers = [];
        foreach (['title' => $gearbox->title, 'price' => $gearbox->price, 'images' => $gearbox->images] as $field => $value) {
            if (blank($value)) $blockers[] = "Missing {$field}";
        }

        return [
            'would_send' => false,
            'marketplace_write' => false,
            'adapter' => 'JarekGearbox -> eBay preview data',
            'source_price' => $sourcePrice,
            'multiplier' => 1.25,
            'final_ebay_price' => $finalPrice,
            'blockers' => $blockers,
            'payload' => [
                'sku' => 'JAREK-GB-'.$gearbox->id,
                'title' => $gearbox->title,
                'description' => $gearbox->description ?: $gearbox->plain_description,
                'price' => ['value' => $finalPrice, 'currency' => $gearbox->currency ?: 'PLN'],
                'quantity' => $gearbox->quantity,
                'images' => $gearbox->images ?? [],
                'category' => ['source_category_id' => $gearbox->category_id, 'source_category_name' => $gearbox->category_name],
                'condition' => 'used',
                'item_specifics' => $gearbox->parameters ?? [],
                'source' => ['type' => 'jarek_gearbox', 'allegro_offer_id' => $gearbox->allegro_offer_id],
            ],
        ];
    }
}
