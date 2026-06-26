<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Arr;

class OrderItemThumbnailDiagnostics
{
    public static function snapshotImageUrl(?OrderItem $item): ?string
    {
        $payloads = [$item?->meta, $item?->raw_payload];
        $paths = [
            'image_url', 'image', 'thumbnail', 'thumbnail_url', 'picture', 'picture.url',
            'photo', 'photo.url', 'offer.image', 'offer.image.url', 'offer.thumbnail',
            'offer.thumbnail_url', 'offer.primaryImage.url', 'offer.images.0.url',
            'images.0.url', 'images.0', 'photos.0.url', 'photos.0', 'gallery.0.url', 'gallery.0',
        ];

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            foreach ($paths as $path) {
                $candidate = data_get($payload, $path);

                if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_URL)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function resolve(?Order $order, ?OrderItem $item): array
    {
        $listing = null;
        $localPart = null;
        $listingPart = null;

        if ($item !== null) {
            $listing = $item->relationLoaded('marketplaceListing') ? $item->marketplaceListing : $item->marketplaceListing()->first();
            $localPart = $item->relationLoaded('part') ? $item->part : $item->part()->first();
        }

        if ($listing !== null) {
            $listingPart = $listing->relationLoaded('part') ? $listing->part : $listing->part()->first();
        }
        $part = $localPart ?: $listingPart;

        $localPartImageUrl = $localPart?->adminTableImageUrl();
        $listingPartImageUrl = $listingPart?->adminTableImageUrl();
        $snapshotImageUrl = self::snapshotImageUrl($item);

        $thumbnailUrl = null;
        $thumbnailSource = 'placeholder';

        if ($localPartImageUrl) {
            $thumbnailUrl = $localPartImageUrl;
            $thumbnailSource = 'local_part';
        } elseif ($listingPartImageUrl) {
            $thumbnailUrl = $listingPartImageUrl;
            $thumbnailSource = 'marketplace_listing_part';
        } elseif ($snapshotImageUrl) {
            $thumbnailUrl = $snapshotImageUrl;
            $thumbnailSource = 'marketplace_snapshot';
        }

        $firstImage = $part?->primaryImage();
        $displayNameSource = $part?->name ? ($localPart ? 'local_part' : 'marketplace_listing_part') : ($listing?->title ? 'marketplace_listing' : ($item?->product_name ? 'order_item' : 'fallback'));
        $storageLocationSource = $part?->storageLocation?->name ? ($localPart ? 'local_part' : 'marketplace_listing_part') : 'fallback';

        return [
            'thumbnail_source' => $thumbnailSource,
            'order_item_id' => $item?->id,
            'marketplace' => $item?->marketplace ?: $order?->marketplace,
            'marketplace_order_id' => $item?->marketplace_order_id ?: $order?->marketplace_order_id,
            'offer_id' => $item?->offer_id,
            'sku' => $item?->sku,
            'external_product_id' => $item?->external_product_id,
            'marketplace_item_id' => $item?->marketplace_item_id,
            'listing_found' => $listing !== null,
            'listing_id' => $listing?->id,
            'part_found' => $part !== null,
            'part_id' => $part?->id,
            'part_has_images' => $part ? $part->images()->exists() : false,
            'first_image_path_present' => filled($firstImage?->path),
            'resolved_thumbnail_url_present' => filled($thumbnailUrl),
            'storage_location_present' => filled($part?->storageLocation?->name),
            'thumbnail_url' => $thumbnailUrl,
            'display_name' => $part?->name ?: $listing?->title ?: $item?->product_name ?: 'Brak danych',
            'display_name_source' => $displayNameSource,
            'storage_location' => $part?->storageLocation?->name ?: 'Brak lokalizacji',
            'storage_location_source' => $storageLocationSource,
            'local_part_id' => $localPart?->id,
            'marketplace_listing_part_id' => $listingPart?->id,
            'local_part_image_url_present' => filled($localPartImageUrl),
            'marketplace_listing_part_image_url_present' => filled($listingPartImageUrl),
            'marketplace_snapshot_image_url_present' => filled($snapshotImageUrl),
        ];
    }

    public static function attribute(array $debug): string
    {
        return e(json_encode(Arr::only($debug, [
            'thumbnail_source', 'order_item_id', 'marketplace', 'marketplace_order_id', 'offer_id', 'sku',
            'external_product_id', 'marketplace_item_id', 'listing_found', 'listing_id', 'part_found',
            'part_id', 'part_has_images', 'first_image_path_present', 'resolved_thumbnail_url_present',
            'storage_location_present',
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
