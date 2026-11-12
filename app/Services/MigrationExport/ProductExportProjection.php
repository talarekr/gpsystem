<?php

namespace App\Services\MigrationExport;

/** Pure projections of locally captured data; deliberately no models, resolvers or HTTP clients. */
final class ProductExportProjection
{
    public const VERSION = 'gpswiss-products-1';

    public const BASE = 'part_id source_system external_id sku part_number oem_number manufacturer_code additional_part_codes status quantity needs_listing needs_review is_visible_storefront sale_source sold_at created_at updated_at name short_description description condition_notes internal_note category_id category_name category_path allegro_category_id allegro_category_source part_position steering_side steering_side_source weight_kg length_cm width_cm height_cm storage_location_id storage_location_name storage_location_description car_id car_source_system car_external_id ovoko_car_id car_make car_model car_generation car_year car_vin car_engine car_engine_code car_gearbox car_gearbox_code car_fuel car_body_type vehicle_snapshot store_price store_currency allegro_price ovoko_price ebay_price allegro_shipping_rate_name allegro_shipping_rate_id allegro_shipping_account_id store_legacy_url store_slug store_legacy_slug store_url image_count primary_image_path images_json legacy_payload review_metadata source_part_json';

    public const LISTING = 'listing_local_id external_listing_id external_offer_id marketplace_account_id account_code url status listing_price listing_currency last_synced_at last_seen_at';

    public const PHOTOS = ['part_id', 'image_id', 'original_name', 'source_path', 'sort_order', 'is_primary', 'role', 'source_type', 'mime_type', 'extension', 'import_order', 'copy_relative_path', 'source_metadata'];

    public const RELATIONS = ['part_id', 'listing_local_id', 'marketplace', 'marketplace_account_id', 'account_code', 'resolved_market', 'market_resolution_status', 'external_listing_id', 'external_offer_id', 'external_inventory_id', 'sku', 'url', 'status', 'price', 'currency', 'last_synced_at', 'last_seen_at', 'source_metadata'];

    public static function columns(): array
    {
        $out = explode(' ', self::BASE);
        foreach (['ebay_de', 'ovoko'] as $prefix) {
            foreach (['listing_count', 'resolution_status', 'listing_resolution_status', ...explode(' ', self::LISTING)] as $field) {
                $out[] = $prefix.'_'.$field;
            }
        }

        return $out;
    }

    public static function market(array $listing, array $account): array
    {
        $signals = [];
        foreach ([$listing['marketplace'] ?? null, $account['code'] ?? null] as $value) {
            if (in_array($value, ['ebay_de', 'ebay_fr'], true)) {
                $signals[] = strtoupper($value);
            }
        }
        foreach ([data_get($listing, 'raw_payload.marketplaceId'), data_get($listing, 'raw_payload.marketplace_id'), data_get($listing, 'raw_payload.offer.marketplaceId'), data_get($account, 'api_settings.marketplace_id')] as $value) {
            if (is_string($value) && str_starts_with(strtoupper($value), 'EBAY_')) {
                $signals[] = strtoupper($value);
            }
        }
        $signals = array_values(array_unique($signals));

        return count($signals) === 1 ? [$signals[0], 'resolved'] : [null, $signals ? 'conflict' : 'unknown'];
    }

    public static function project(array $capture): array
    {
        $p = $capture['part'];
        $car = $capture['car'] ?? [];
        $category = $capture['category'] ?? [];
        $location = $capture['location'] ?? [];
        $row = array_intersect_key($p, array_flip(self::columns()));
        $row['part_id'] = $p['id'];
        $row['source_part_json'] = $p;
        foreach (['source_system' => 'car_source_system', 'external_id' => 'car_external_id', 'make' => 'car_make', 'model' => 'car_model', 'model_variant' => 'car_generation', 'production_year' => 'car_year', 'vin' => 'car_vin', 'engine_capacity_cm3' => 'car_engine', 'engine_code' => 'car_engine_code', 'gearbox_type' => 'car_gearbox', 'gearbox_code' => 'car_gearbox_code', 'fuel_type' => 'car_fuel', 'body_type' => 'car_body_type'] as $from => $to) {
            $row[$to] = $car[$from] ?? null;
        }
        $row['ovoko_car_id'] = ($car['source_system'] ?? null) === 'ovoko' ? ($car['external_id'] ?? null) : data_get($car, 'legacy_payload.ovoko_car_id');
        $row['category_name'] = $category['name'] ?? null;
        $row['category_path'] = $category['category_path'] ?? null;
        $row['storage_location_name'] = $location['name'] ?? null;
        $row['storage_location_description'] = $location['description'] ?? null;
        $row['store_price'] = $p['price'] ?? null;
        $row['store_currency'] = $p['currency'] ?? null;
        $row['store_legacy_url'] = $p['legacy_url'] ?? null;
        $row['store_slug'] = $p['slug'] ?? null;
        $row['store_legacy_slug'] = $p['legacy_slug'] ?? null;
        $row['store_url'] = $capture['store_url'] ?? null;
        $row['part_position'] = collect([data_get($p, 'review_metadata.part_position'), data_get($p, 'legacy_payload.part_position'), data_get($p, 'legacy_payload.position')])->first(fn ($v) => is_scalar($v) && trim((string) $v) !== '');
        $row['steering_side'] = null;
        $row['steering_side_source'] = null;
        foreach (['parts.vehicle_snapshot.steering_side' => data_get($p, 'vehicle_snapshot.steering_side'), 'cars.steering_side' => $car['steering_side'] ?? null] as $source => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $row['steering_side'] = $value;
                $row['steering_side_source'] = $source;
                break;
            }
        }
        $issues = [];
        foreach (['car' => 'car_id', 'category' => 'category_id', 'location' => 'storage_location_id'] as $relation => $field) {
            if (($p[$field] ?? null) !== null && empty($capture[$relation])) {
                $issues[] = ['reason_code' => 'orphan_'.$relation, 'details' => [$field => $p[$field]]];
            } elseif (empty($capture[$relation])) {
                $issues[] = ['reason_code' => 'missing_'.$relation, 'details' => null];
            }
        }
        $mappings = array_values(array_filter($capture['mappings'], fn ($m) => str_starts_with($m['channel'] ?? '', 'allegro')));
        $ids = array_values(array_unique(array_filter(array_column($mappings, 'external_category_id'), fn ($v) => $v !== null && $v !== '')));
        $row['allegro_category_id'] = count($ids) === 1 ? $ids[0] : null;
        $row['allegro_category_source'] = count($ids) === 1 ? 'marketplace_category_mappings.external_category_id' : null;
        if (count($ids) > 1) {
            $issues[] = ['reason_code' => 'multiple_allegro_categories', 'details' => $ids];
        }
        $photos = self::photos($p, $capture['images'], $issues);
        $row['image_count'] = count($photos);
        $row['primary_image_path'] = $photos[0]['source_path'] ?? null;
        $row['images_json'] = array_map(fn ($i) => array_diff_key($i, ['source_metadata' => true]), $photos);
        $relations = [];
        $groups = ['ebay_de' => [], 'ovoko' => []];
        $ebayConflict = false;
        $allegroAccounts = [];
        foreach ($capture['listings'] as $l) {
            $account = $capture['accounts'][(string) ($l['marketplace_account_id'] ?? '')] ?? [];
            $family = FrozenMarketplaceLinks::family($l['marketplace'] ?? '');
            [$market, $resolution] = $family === 'ebay' ? self::market($l, $account) : [null, 'not_applicable'];
            $relation = $l + ['account_code' => $account['code'] ?? null];
            $relation['listing_local_id'] = $l['id'];
            $relation['resolved_market'] = $market;
            $relation['market_resolution_status'] = $resolution;
            $relation['source_metadata'] = $l;
            $relations[] = $relation;
            if ($family === 'ebay') {
                if ($resolution !== 'resolved') {
                    $ebayConflict = true;
                    $issues[] = ['reason_code' => 'ebay_market_'.$resolution, 'details' => ['listing_id' => $l['id']]];
                } elseif ($market === 'EBAY_DE') {
                    $groups['ebay_de'][] = $relation;
                }
            }
            if ($family === 'ovoko') {
                $groups['ovoko'][] = $relation;
            }
            if ($family === 'allegro' && ! empty($l['marketplace_account_id'])) {
                $allegroAccounts[] = (string) $l['marketplace_account_id'];
            }
        }
        foreach ($groups as $prefix => $listings) {
            $count = count($listings);
            $blocked = $prefix === 'ebay_de' && $ebayConflict;
            $status = $blocked ? 'market_unresolved' : ($count === 1 ? 'unique' : ($count === 0 ? 'missing' : 'multiple'));
            $row[$prefix.'_listing_count'] = $count;
            $row[$prefix.'_resolution_status'] = $row[$prefix.'_listing_resolution_status'] = $status;
            $l = $status === 'unique' ? $listings[0] : [];
            foreach (explode(' ', self::LISTING) as $field) {
                $row[$prefix.'_'.$field] = $l[match ($field) {
                    'listing_price' => 'price', 'listing_currency' => 'currency', default => $field
                }] ?? null;
            }
            if ($count > 1) {
                $issues[] = ['reason_code' => 'multiple_'.$prefix.'_listings', 'details' => array_column($listings, 'listing_local_id')];
            }
            if ($status === 'unique' && empty($l['url'])) {
                $issues[] = ['reason_code' => 'missing_'.$prefix.'_url', 'details' => ['listing_id' => $l['listing_local_id']]];
            }
        }
        // A part has a name, not an account binding. Require a unique locally associated account and rate.
        $allegroAccounts = array_values(array_unique($allegroAccounts));
        $rates = array_values(array_filter($capture['shipping_rates'], fn ($r) => count($allegroAccounts) === 1 && (string) $r['marketplace_account_id'] === $allegroAccounts[0] && ($r['shipping_rate_name'] ?? null) === ($p['allegro_shipping_rate_name'] ?? null)));
        $row['allegro_shipping_rate_id'] = count($rates) === 1 ? $rates[0]['shipping_rate_id'] : null;
        $row['allegro_shipping_account_id'] = count($rates) === 1 ? $rates[0]['marketplace_account_id'] : null;

        return ['part' => $row, 'photos' => $photos, 'listings' => $relations, 'issues' => array_map(fn ($i) => ['part_id' => $p['id']] + $i, $issues)];
    }

    private static function photos(array $part, array $images, array &$issues): array
    {
        $out = [];
        foreach ($images as $image) {
            $path = $image['path'] ?? null;
            if (! $path) {
                $issues[] = ['reason_code' => 'missing_image_path', 'details' => ['image_id' => $image['id']]];

                continue;
            }
            $out[] = ['part_id' => $part['id'], 'image_id' => $image['id'], 'original_name' => basename($path), 'source_path' => $path, 'sort_order' => $image['sort_order'] ?? null, 'is_primary' => (bool) ($image['is_primary'] ?? false), 'role' => 'gallery', 'source_type' => $image['source_system'] ?? null, 'mime_type' => $image['mime_type'] ?? null, 'extension' => pathinfo($path, PATHINFO_EXTENSION) ?: null, 'source_metadata' => $image];
        }
        $path = $part['code_photo_path'] ?? null;
        if ($path && ! in_array($path, array_column($out, 'source_path'), true)) {
            $out[] = ['part_id' => $part['id'], 'image_id' => null, 'original_name' => basename($path), 'source_path' => $path, 'sort_order' => null, 'is_primary' => false, 'role' => 'code_photo', 'source_type' => null, 'mime_type' => null, 'extension' => pathinfo($path, PATHINFO_EXTENSION) ?: null, 'source_metadata' => null];
        }
        usort($out, fn ($a, $b) => [(int) ! $a['is_primary'], $a['sort_order'] ?? PHP_INT_MAX, (int) ($a['image_id'] ?? PHP_INT_MAX), $a['source_path']] <=> [(int) ! $b['is_primary'], $b['sort_order'] ?? PHP_INT_MAX, (int) ($b['image_id'] ?? PHP_INT_MAX), $b['source_path']]);
        foreach ($out as $n => &$image) {
            $image['import_order'] = $n + 1;
            $path = $image['source_path'];
            $relative = preg_replace('~^(?:/?storage/app/public/|/?storage/)~', '', $path);
            $image['copy_relative_path'] = ! str_contains($relative, '://') && ! str_starts_with($relative, '/') && ! str_contains($relative, '\\') && ! in_array('..', explode('/', $relative), true) ? $relative : null;
            if ($image['copy_relative_path'] === null) {
                $issues[] = ['reason_code' => 'image_copy_path_unresolved', 'details' => ['source_path' => $path]];
            }
        }
        unset($image);
        if (! $out) {
            $issues[] = ['reason_code' => 'missing_images', 'details' => null];
        }

        return $out;
    }
}
