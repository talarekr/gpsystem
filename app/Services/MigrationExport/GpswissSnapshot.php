<?php

namespace App\Services\MigrationExport;

use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** An allowlisted catalog snapshot. Never instantiates business models or reads accounts/orders/users. */
final class GpswissSnapshot
{
    public function capture(): array
    {
        $db = DB::connection();
        if ($db->transactionLevel() !== 0 && ! app()->environment('testing')) {
            throw new RuntimeException('existing_transaction_not_supported');
        }
        if ($db->getDriverName() === 'mysql') {
            // Session/transaction characteristics only, no application SQL writes. Applies to next transaction.
            $db->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        } elseif ($db->getDriverName() !== 'sqlite') {
            throw new RuntimeException('snapshot_driver_not_supported');
        }

        return ReadOnlyDatabase::run(fn () => $db->transaction(function () use ($db): array {
            $tables = ['parts', 'part_images', 'part_categories', 'cars', 'storage_locations', 'marketplace_listings'];
            $parametersAvailable = Schema::hasTable('allegro_parameter_selections');
            if ($parametersAvailable) {
                $tables[] = 'allegro_parameter_selections';
            }
            $mappingAvailable = Schema::hasTable('marketplace_category_mappings');
            if ($mappingAvailable) {
                $tables[] = 'marketplace_category_mappings';
            }
            if ($db->getDriverName() === 'mysql') {
                $engines = $db->table('information_schema.tables')->where('table_schema', $db->getDatabaseName())->whereIn('table_name', $tables)->get(['table_name as source_table', 'engine as source_engine']);
                if ($engines->count() !== count($tables) || $engines->contains(fn ($t) => strtoupper((string) $t->source_engine) !== 'INNODB')) {
                    throw new RuntimeException('snapshot_requires_innodb');
                }
            }
            $started = now()->toIso8601String();
            // First non-locking InnoDB read establishes one repeatable-read view for ALL following tables.
            $parts = $this->read('parts', ['id', 'source_system', 'external_id', 'sku', 'name', 'slug', 'part_number', 'additional_part_codes', 'oem_number', 'manufacturer_code', 'short_description', 'description', 'condition_notes', 'category_id', 'car_id', 'storage_location_id', 'price', 'currency', 'allegro_price', 'ovoko_price', 'ebay_price', 'allegro_shipping_rate_name', 'quantity', 'status', 'is_visible_storefront', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'code_photo_path', 'vehicle_snapshot', 'review_metadata', 'legacy_payload', 'updated_at']);
            $categories = $this->read('part_categories', ['id', 'parent_id', 'name', 'slug', 'category_path', 'sort_order', 'description', 'is_visible']);
            $cars = $this->read('cars', ['id', 'source_system', 'external_id', 'make', 'model', 'model_variant', 'production_year', 'fuel_type', 'gearbox_type', 'engine_code', 'engine_capacity_cm3', 'steering_side', 'body_type', 'color', 'color_code', 'legacy_payload']);
            $locations = $this->read('storage_locations', ['id', 'name', 'is_active']);
            $images = $this->read('part_images', ['id', 'part_id', 'path', 'alt_text', 'sort_order', 'is_primary']);
            $listings = $this->read('marketplace_listings', ['id', 'part_id', 'marketplace', 'marketplace_account_id', 'external_offer_id', 'external_listing_id', 'external_inventory_id', 'sku', 'status', 'url', 'raw_payload']);
            $parameters = $parametersAvailable ? $this->read('allegro_parameter_selections', ['part_id', 'allegro_category_id', 'parameter_id', 'parameter_name', 'value_id', 'value_label']) : [];
            $categoryMappings = $mappingAvailable ? $this->read('marketplace_category_mappings', ['id', 'local_category_id', 'channel', 'external_category_id', 'external_category_name', 'external_category_path', 'is_blocked']) : [];
            $byListing = [];
            foreach ($listings as $listing) {
                $raw = $this->json($listing['raw_payload'] ?? null);
                unset($listing['raw_payload']);
                $listing['url'] = self::offerUrl($listing['url'] ?? null);
                $listing['allegro_parameters_from_saved_offer'] = str_starts_with($listing['marketplace'], 'allegro') ? $this->offerParameters($raw) : [];
                $byListing[$listing['part_id']][] = self::clean($listing);
            }
            $byParameter = [];
            foreach ($parameters as $parameter) {
                $byParameter[$parameter['part_id']][] = self::clean($parameter);
            }
            $products = [];
            foreach ($parts as $part) {
                $legacy = $this->json($part['legacy_payload'] ?? null);
                $review = $this->json($part['review_metadata'] ?? null);
                $part['part_position'] = collect([data_get($review, 'part_position'), data_get($legacy, 'part_position'), data_get($legacy, 'position')])->first(fn ($v) => is_scalar($v) && trim((string) $v) !== '');
                $part['additional_part_codes'] = $this->codes($this->json($part['additional_part_codes'] ?? null));
                $part['brand'] = is_scalar($legacy['brand'] ?? null) ? $legacy['brand'] : null;
                $part['manufacturer'] = is_scalar($legacy['manufacturer'] ?? null) ? $legacy['manufacturer'] : null;
                $part['allegro_category_id'] = data_get($review, 'marketplace_category_selections.allegro');
                if (! is_scalar($part['allegro_category_id'])) {
                    $part['allegro_category_id'] = null;
                }
                $part['allegro_parameters'] = $byParameter[$part['id']] ?? [];
                $part['vehicle_snapshot'] = array_intersect_key($this->json($part['vehicle_snapshot'] ?? null), array_flip(['make', 'model', 'model_variant', 'production_year', 'fuel_type', 'gearbox_type', 'engine_code', 'engine_capacity_cm3', 'steering_side', 'body_type', 'color', 'color_code']));
                $part['offers'] = $byListing[$part['id']] ?? [];
                foreach (['price', 'allegro_price', 'ovoko_price', 'ebay_price'] as $field) {
                    $part[$field] = isset($part[$field]) ? number_format((float) $part[$field], 2, '.', '') : null;
                }
                $part['ean'] = null;
                $part['vat_rate'] = null;
                $part['images'] = [];
                // No raw JSON, user identifiers, internal notes, customer or integration data crosses this boundary.
                unset($part['legacy_payload'], $part['review_metadata']);
                $products[] = self::clean($part);
            }
            foreach ($cars as &$car) {
                $legacy = $this->json($car['legacy_payload'] ?? null);
                $car['ovoko_car_id'] = ($car['source_system'] ?? null) === 'ovoko' ? $car['external_id'] : ($legacy['ovoko_car_id'] ?? null);
                if (! is_scalar($car['ovoko_car_id'])) {
                    $car['ovoko_car_id'] = null;
                }
                unset($car['legacy_payload']);
                $car = self::clean($car);
            }
            unset($car);

            return ['snapshot_at' => $started, 'snapshot_finished_at' => now()->toIso8601String(), 'database_consistency' => $db->getDriverName() === 'mysql' ? 'repeatable_read_read_only_innodb' : 'sqlite_read_transaction',
                'source_environment' => app()->environment(), 'source_host' => parse_url((string) config('app.url'), PHP_URL_HOST),
                'products' => $products, 'marketplace_category_mappings' => self::clean($categoryMappings), 'categories' => self::clean($categories), 'cars' => $cars, 'storage_locations' => self::clean($locations), 'images' => $images,
                'source_counts' => ['products' => count($parts), 'photo_rows' => count($images), 'categories' => count($categories), 'cars' => count($cars), 'storage_locations' => count($locations), 'active_by_status' => count(array_filter($parts, fn ($p) => in_array($p['status'], ['ready', 'published'], true))), 'inactive_by_status' => count(array_filter($parts, fn ($p) => in_array($p['status'], ['draft', 'needs_review', 'sold'], true))), 'archived' => count(array_filter($parts, fn ($p) => $p['status'] === 'archived')), 'statuses' => array_count_values(array_map(fn ($p) => $p['status'] ?? 'unknown', $parts))],
                'warnings' => array_values(array_filter(['ean_and_vat_not_native_do_not_infer', 'native_product_variants_not_supported', $parametersAvailable ? null : 'allegro_parameter_selections_table_missing']))];
        }));
    }

    private function read(string $table, array $columns): array
    {
        return DB::table($table)->orderBy(in_array('id', $columns, true) ? 'id' : 'part_id')->get(array_values(array_intersect($columns, Schema::getColumnListing($table))))->map(fn ($r) => (array) $r)->all();
    }

    private function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    private function codes(array $codes): array
    {
        return array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : (is_scalar($v['code'] ?? null) ? (string) $v['code'] : null), $codes), fn ($v) => $v !== null));
    }

    private function offerParameters(array $raw): array
    {
        $out = [];
        foreach (['parameters' => $raw['parameters'] ?? [], 'product_parameters' => data_get($raw, 'productSet.0.product.parameters', [])] as $scope => $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $out[] = ['scope' => $scope] + array_intersect_key($row, array_flip(['id', 'name', 'values', 'valuesIds', 'rangeValue']));
            }
        }

        return $out;
    }

    public static function offerUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $url = parse_url($value);
        $host = strtolower($url['host'] ?? '');
        if (! in_array($url['scheme'] ?? '', ['http', 'https'], true) || ! preg_match('/(?:^|\.)(?:allegro\.pl|allegrolokalnie\.pl|ebay\.(?:com|de|pl|co\.uk|fr|it|es)|ovoko\.(?:pl|com|de|fr|it|es)|rrr\.lt)$/D', $host)) {
            return null;
        }

        return 'https://'.$host.($url['path'] ?? '/'); // Never userinfo, fragments, signed query strings or tokens.
    }

    public static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/token|secret|password|credential|authorization|customer|buyer|seller_address|email/i', $key)) {
                    continue;
                }
                $out[$key] = self::clean($item);
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }
        $value = preg_replace_callback("~https?://[^\\s<>\"']+~u", static function ($match) {
            $url = parse_url($match[0]);

            return isset($url['host']) ? ($url['scheme'] ?? 'https').'://'.$url['host'].($url['path'] ?? '/') : '[URL_REDACTED]';
        }, $value);
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[EMAIL_REDACTED]', $value);

        return preg_replace('/\b(access_token|refresh_token|token|client_secret|password|api_key|authorization)\s*[:=]\s*[^\s<>"\']+/i', '$1=[REDACTED]', $value);
    }
}
