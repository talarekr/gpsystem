<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Part;
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
            $listing = self::resolveListing($order, $item);
            $localPart = $item->relationLoaded('part') ? $item->part : $item->part()->first();
        }

        if ($listing !== null) {
            $listingPart = $listing->relationLoaded('part') ? $listing->part : $listing->part()->first();
        }
        $part = $localPart ?: $listingPart;

        $localPartImageUrl = $localPart?->adminTableImageUrl();
        $listingPartImageUrl = $listingPart?->adminTableImageUrl();
        $adminPartsMechanismPart = $localPart ?: $listingPart;
        $adminPartsMechanismUrl = $localPartImageUrl ?: $listingPartImageUrl;
        $snapshotImageUrl = self::snapshotImageUrl($item);

        $thumbnailUrl = null;
        $thumbnailSource = 'placeholder';

        if ($localPartImageUrl) {
            $thumbnailUrl = $localPartImageUrl;
            $thumbnailSource = 'admin_parts_thumbnail';
        } elseif ($listingPartImageUrl) {
            $thumbnailUrl = $listingPartImageUrl;
            $thumbnailSource = 'admin_parts_thumbnail';
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
            'resolved_part_id' => $part?->id,
            'admin_parts_mechanism_part_id' => $adminPartsMechanismPart?->id,
            'admin_parts_mechanism_url' => $adminPartsMechanismUrl,
            'final_thumbnail_url' => $thumbnailUrl,
            'admin_parts_mechanism_url_matches_final_thumbnail_url' => filled($adminPartsMechanismUrl) && $adminPartsMechanismUrl === $thumbnailUrl,
            'part' => $part,
            'thumbnail_part' => $thumbnailSource === 'admin_parts_thumbnail' && $part ? $part : null,
            'thumbnail_part_id' => $thumbnailSource === 'admin_parts_thumbnail' && $part ? $part->id : null,
            'admin_parts_thumbnail_url_present' => filled($localPartImageUrl) || filled($listingPartImageUrl),
            'display_name' => $part?->name ?: $listing?->title ?: $item?->product_name ?: 'Brak danych',
            'display_name_source' => $displayNameSource,
            'storage_location' => $part?->storageLocation?->name ?: 'Brak lokalizacji',
            'storage_location_source' => $storageLocationSource,
            'local_part_id' => $localPart?->id,
            'marketplace_listing_part_id' => $listingPart?->id,
            'resolved_listing_external_offer_id' => $listing?->external_offer_id,
            'resolved_listing_external_listing_id' => $listing?->external_listing_id,
            'resolved_listing_sku' => $listing?->sku,
            'resolved_listing_title' => $listing?->title,
            'local_part_image_url_present' => filled($localPartImageUrl),
            'marketplace_listing_part_image_url_present' => filled($listingPartImageUrl),
            'marketplace_snapshot_image_url_present' => filled($snapshotImageUrl),
        ];
    }


    private static function resolveListing(?Order $order, OrderItem $item): ?MarketplaceListing
    {
        if (self::marketplace($order, $item) === 'ovoko') {
            $listing = self::resolveOvokoListingByExternalOfferId($order, $item);

            if ($listing !== null) {
                return $listing;
            }
        }

        return $item->relationLoaded('marketplaceListing')
            ? $item->marketplaceListing
            : $item->marketplaceListing()->first();
    }

    private static function resolveOvokoListingByExternalOfferId(?Order $order, OrderItem $item): ?MarketplaceListing
    {
        $externalOfferIds = self::ovokoExternalOfferIdCandidates($order, $item);

        if ($externalOfferIds === []) {
            return null;
        }

        $orderSql = 'CASE external_offer_id '.implode(' ', array_fill(0, count($externalOfferIds), 'WHEN ? THEN ?')).' ELSE '.count($externalOfferIds).' END';
        $orderBindings = [];
        foreach ($externalOfferIds as $index => $externalOfferId) {
            $orderBindings[] = $externalOfferId;
            $orderBindings[] = $index;
        }

        return MarketplaceListing::query()
            ->with(['part.images', 'part.storageLocation'])
            ->where('marketplace', 'ovoko')
            ->whereIn('external_offer_id', $externalOfferIds)
            ->orderByRaw($orderSql, $orderBindings)
            ->first();
    }

    /** @return array<int, string> */
    private static function ovokoExternalOfferIdCandidates(?Order $order, OrderItem $item): array
    {
        $values = [$item->marketplace_item_id];

        foreach ([$item->raw_payload, $item->meta, $order?->raw_payload] as $payload) {
            $rawItems = data_get($payload, 'item_list');

            if (! is_array($rawItems)) {
                continue;
            }

            foreach ($rawItems as $rawItem) {
                if (is_array($rawItem)) {
                    $values[] = $rawItem['id'] ?? null;
                }
            }
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($value): string => trim((string) $value), $values),
            fn (string $value): bool => $value !== ''
        )));
    }

    private static function marketplace(?Order $order, OrderItem $item): ?string
    {
        $marketplace = $item->marketplace ?: $order?->marketplace;

        return is_string($marketplace) ? strtolower($marketplace) : null;
    }

    public static function attribute(array $debug): string
    {
        return e(json_encode(Arr::only($debug, [
            'thumbnail_source', 'thumbnail_part_id', 'admin_parts_thumbnail_url_present', 'order_item_id', 'marketplace', 'marketplace_order_id', 'offer_id', 'sku',
            'external_product_id', 'marketplace_item_id', 'listing_found', 'listing_id', 'part_found',
            'part_id', 'part_has_images', 'first_image_path_present', 'resolved_thumbnail_url_present',
            'storage_location_present',
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
