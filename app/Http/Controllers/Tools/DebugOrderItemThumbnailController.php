<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Part;
use App\Support\OrderItemThumbnailDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DebugOrderItemThumbnailController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $orderId = $request->query('order_id');
        if (! $orderId) {
            return response()->json(['ok' => false, 'error_message' => 'Missing order_id.'], 422);
        }

        $order = Order::query()
            ->with(['items.part.images', 'items.part.storageLocation', 'items.marketplaceListing.part.images', 'items.marketplaceListing.part.storageLocation'])
            ->find($orderId);

        if (! $order) {
            return response()->json(['ok' => false, 'error_message' => 'Order not found.'], 404);
        }

        $items = $order->items;
        if ($request->filled('order_item_id')) {
            $items = $items->where('id', (int) $request->query('order_item_id'))->values();

            if ($items->isEmpty()) {
                return response()->json(['ok' => false, 'error_message' => 'Order item not found for this order.'], 404);
            }
        }

        return response()->json([
            'ok' => true,
            'mode' => 'read_only_diagnostics',
            'safety_flags' => [
                'read_only' => true,
                'db_writes' => false,
                'marketplace_writes' => false,
                'downloads_missing_images' => false,
                'changes_import_or_parts' => false,
            ],
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'marketplace' => $order->marketplace,
                'marketplace_order_id' => $order->marketplace_order_id,
                'status' => $order->status,
            ],
            'items' => $items->map(fn (OrderItem $item): array => $this->itemPayload($order, $item))->values(),
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function itemPayload(Order $order, OrderItem $item): array
    {
        $debug = OrderItemThumbnailDiagnostics::resolve($order, $item);
        $listing = $debug['listing_id'] ? MarketplaceListing::query()->with(['part.images', 'part.storageLocation'])->find($debug['listing_id']) : null;
        $part = $debug['part_id'] ? Part::query()->with(['images', 'storageLocation'])->find($debug['part_id']) : null;
        $firstImage = $part?->primaryImage();

        return [
            'item' => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'part_id' => $item->part_id,
                'marketplace' => $item->marketplace,
                'marketplace_order_id' => $item->marketplace_order_id,
                'marketplace_item_id' => $item->marketplace_item_id,
                'offer_id' => $item->offer_id,
                'sku' => $item->sku,
                'external_product_id' => $item->external_product_id,
            ],
            'listing_resolution' => [
                'listing_found' => $debug['listing_found'],
                'listing_id' => $debug['listing_id'],
                'listing_part_id' => $listing?->part_id,
                'external_offer_id' => $listing?->external_offer_id,
                'external_listing_id' => $listing?->external_listing_id,
                'sku' => $listing?->sku,
                'title' => $listing?->title,
            ],
            'part_resolution' => [
                'part_found' => $debug['part_found'],
                'part_id' => $debug['part_id'],
                'local_part_id' => $debug['local_part_id'],
                'marketplace_listing_part_id' => $debug['marketplace_listing_part_id'],
                'name' => $part?->name,
            ],
            'image_resolution' => [
                'part_has_images' => $debug['part_has_images'],
                'first_image_id' => $firstImage?->id,
                'first_image_path' => $firstImage?->path,
                'first_image_path_present' => $debug['first_image_path_present'],
                'local_part_image_url_present' => $debug['local_part_image_url_present'],
                'marketplace_listing_part_image_url_present' => $debug['marketplace_listing_part_image_url_present'],
                'marketplace_snapshot_image_url_present' => $debug['marketplace_snapshot_image_url_present'],
                'resolved_thumbnail_url' => $debug['thumbnail_url'],
                'resolved_thumbnail_url_present' => $debug['resolved_thumbnail_url_present'],
            ],
            'storage_location_resolution' => [
                'storage_location' => $debug['storage_location'],
                'storage_location_present' => $debug['storage_location_present'],
            ],
            'final_thumbnail_source' => $debug['thumbnail_source'],
            'final_display_name_source' => $debug['display_name_source'],
            'final_storage_location_source' => $debug['storage_location_source'],
            'ovoko_mapping_diagnostics' => ($order->marketplace === 'ovoko' || $item->marketplace === 'ovoko')
                ? $this->ovokoMappingDiagnostics($order, $item)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function ovokoMappingDiagnostics(Order $order, OrderItem $item): array
    {
        $rawItems = $this->rawPayloadItems($order, $item);
        $identifiers = $this->mappingIdentifiers($item, $rawItems);
        $checks = [];

        $checks[] = $this->checkMarketplaceListings($identifiers);
        $checks[] = $this->checkParts($identifiers);
        $checks[] = $this->checkProductsTable($identifiers);
        $checks[] = $this->checkMappingTables($identifiers);

        $match = null;
        foreach ($checks as $check) {
            foreach (($check['matches'] ?? []) as $candidate) {
                if (! empty($candidate['part_id'])) {
                    $match = $candidate;
                    break 2;
                }
            }
        }

        return [
            'mode' => 'read_only_ovoko_mapping_diagnostics',
            'identifiers_checked' => $identifiers,
            'raw_payload_item_list_values' => array_map(fn (array $raw): array => [
                'id' => $raw['id'] ?? null,
                'id_bridge' => $raw['id_bridge'] ?? null,
                'external_id' => $raw['external_id'] ?? null,
                'car_id' => $raw['car_id'] ?? null,
            ], $rawItems),
            'local_tables_checked' => array_column($checks, 'table'),
            'mapping_candidates_checked' => $checks,
            'matched_local_part' => $match ? $this->partMatchPayload((int) $match['part_id'], (string) $match['matched_by']) : null,
            'no_match_reason' => $match ? null : 'No local part_id was found in marketplace_listings, parts, products, or detected mapping tables using the Ovoko identifiers stored on this order item/raw payload.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function rawPayloadItems(Order $order, OrderItem $item): array
    {
        $payloads = [$item->raw_payload, $item->meta, $order->raw_payload];
        foreach ($payloads as $payload) {
            $items = data_get($payload, 'item_list');
            if (is_array($items)) {
                return array_values(array_filter($items, 'is_array'));
            }
        }

        return is_array($item->raw_payload) ? [$item->raw_payload] : [];
    }

    /** @param array<int, array<string, mixed>> $rawItems @return array<string, array<int, string>> */
    private function mappingIdentifiers(OrderItem $item, array $rawItems): array
    {
        $ids = [
            'marketplace_item_id' => [$item->marketplace_item_id],
            'offer_id' => [$item->offer_id],
            'sku' => [$item->sku],
            'external_product_id' => [$item->external_product_id],
            'raw_payload.item_list[].id' => array_column($rawItems, 'id'),
            'raw_payload.item_list[].id_bridge' => array_column($rawItems, 'id_bridge'),
            'raw_payload.item_list[].external_id' => array_column($rawItems, 'external_id'),
            'raw_payload.item_list[].car_id' => array_column($rawItems, 'car_id'),
        ];

        return array_map(fn (array $values): array => array_values(array_unique(array_filter(array_map(fn ($v): string => trim((string) $v), $values), fn (string $v): bool => $v !== ''))), $ids);
    }

    /** @param array<string, array<int, string>> $identifiers @return array<string, mixed> */
    private function checkMarketplaceListings(array $identifiers): array
    {
        $fields = ['external_offer_id', 'external_listing_id', 'external_inventory_id', 'sku', 'id', 'part_id'];
        $matches = [];
        if (Schema::hasTable('marketplace_listings')) {
            foreach ($identifiers as $source => $values) {
                foreach ($fields as $field) {
                    if (! Schema::hasColumn('marketplace_listings', $field)) continue;
                    foreach ($this->rows('marketplace_listings', $field, $values, ['marketplace' => 'ovoko']) as $row) {
                        $matches[] = ['matched_by' => "marketplace_listings.$field from $source", 'listing_id' => $row->id ?? null, 'part_id' => $row->part_id ?? null, 'value' => $row->$field ?? null, 'title' => $row->title ?? null];
                    }
                }
            }
        }
        return ['table' => 'marketplace_listings (ovoko listings)', 'fields_checked' => $fields, 'matches' => $matches];
    }

    private function checkParts(array $identifiers): array
    {
        $fields = ['id', 'external_id', 'sku', 'part_number', 'oem_number', 'manufacturer_code', 'car_id'];
        $matches = [];
        if (Schema::hasTable('parts')) {
            foreach ($identifiers as $source => $values) foreach ($fields as $field) {
                if (! Schema::hasColumn('parts', $field)) continue;
                foreach ($this->rows('parts', $field, $values) as $row) {
                    $matches[] = ['matched_by' => "parts.$field from $source", 'part_id' => $row->id ?? null, 'value' => $row->$field ?? null, 'part_title' => $row->name ?? null];
                }
            }
        }
        return ['table' => 'parts', 'fields_checked' => $fields, 'matches' => $matches];
    }

    private function checkProductsTable(array $identifiers): array
    {
        if (! Schema::hasTable('products')) return ['table' => 'products', 'fields_checked' => [], 'matches' => [], 'note' => 'products table not present'];
        $fields = array_values(array_filter(['id', 'external_id', 'sku', 'part_id', 'ovoko_part_id'], fn ($f) => Schema::hasColumn('products', $f)));
        $matches = [];
        foreach ($identifiers as $source => $values) foreach ($fields as $field) foreach ($this->rows('products', $field, $values) as $row) {
            $matches[] = ['matched_by' => "products.$field from $source", 'product_id' => $row->id ?? null, 'part_id' => $row->part_id ?? null, 'value' => $row->$field ?? null];
        }
        return ['table' => 'products', 'fields_checked' => $fields, 'matches' => $matches];
    }

    private function checkMappingTables(array $identifiers): array
    {
        $tables = collect($this->databaseTables())
            ->filter(fn (string $table): bool => str_contains($table, 'mapping') || str_contains($table, 'bridge'))
            ->values();
        $matches = []; $checked = [];
        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);
            $fields = array_values(array_intersect($columns, ['id', 'part_id', 'external_id', 'ovoko_id', 'ovoko_part_id', 'id_bridge', 'car_id', 'sku']));
            $checked[$table] = $fields;
            foreach ($identifiers as $source => $values) foreach ($fields as $field) foreach ($this->rows($table, $field, $values) as $row) {
                $matches[] = ['matched_by' => "$table.$field from $source", 'table' => $table, 'row_id' => $row->id ?? null, 'part_id' => $row->part_id ?? null, 'value' => $row->$field ?? null];
            }
        }
        return ['table' => 'mapping_tables', 'fields_checked' => $checked, 'matches' => $matches];
    }

    /** @return array<int, string> */
    private function databaseTables(): array
    {
        if (method_exists(Schema::getFacadeRoot(), 'getTables')) {
            return array_map(fn (array $table): string => (string) ($table['name'] ?? $table['table'] ?? reset($table)), Schema::getTables());
        }

        $driver = DB::connection()->getDriverName();
        $sql = $driver === 'sqlite'
            ? "SELECT name FROM sqlite_master WHERE type = 'table'"
            : 'SHOW TABLES';

        return array_map(fn (object $row): string => (string) array_values((array) $row)[0], DB::select($sql));
    }

    private function rows(string $table, string $field, array $values, array $equals = []): array
    {
        if ($values === []) return [];
        $query = DB::table($table)->whereIn($field, $values)->limit(10);
        foreach ($equals as $column => $value) if (Schema::hasColumn($table, $column)) $query->where($column, $value);
        return $query->get()->all();
    }

    private function partMatchPayload(int $partId, string $matchedBy): ?array
    {
        $part = Part::query()->with(['images', 'storageLocation'])->find($partId);
        if (! $part) return null;
        $firstImage = $part->primaryImage();
        return ['matched_by' => $matchedBy, 'part_id' => $part->id, 'part_title' => $part->name, 'part_has_images' => $part->images->isNotEmpty(), 'first_image_path' => $firstImage?->path, 'storage_location' => $part->storageLocation?->name];
    }
}
