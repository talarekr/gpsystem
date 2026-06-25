<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WarehouseImportFromAllegroCsvController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const DEFAULT_PATH = 'storage/app/imports/allegro_external_id_warehouse_export.csv';

    public function suggest(Request $request): JsonResponse
    {
        return $this->handle($request, false);
    }

    public function apply(Request $request): JsonResponse
    {
        return $this->handle($request, true);
    }

    private function handle(Request $request, bool $applyEndpoint): JsonResponse
    {
        if ($request->query('token') !== self::TOKEN) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $dryRun = $applyEndpoint ? $request->boolean('dry_run', true) : true;
        $confirmed = $applyEndpoint && ! $dryRun && $request->boolean('confirm', false);
        $sampleLimit = max(1, min((int) $request->query('sample_limit', $applyEndpoint ? 300 : 300), $applyEndpoint ? 5000 : 2000));
        $includeEmpty = $request->boolean('include_empty_external_id', false);
        $onlyActive = $request->boolean('only_active', false);
        $createMissing = $request->boolean('create_missing_warehouses', true);
        $updateExisting = $request->boolean('update_existing_assignment', true);
        $path = $this->resolvePath((string) $request->query('path', self::DEFAULT_PATH));

        $base = $this->baseResponse($applyEndpoint, $confirmed);
        $base['diagnostics'] = $request->boolean('diagnostics') ? [
            'path' => $path,
            'warehouse_table' => 'storage_locations',
            'part_warehouse_column' => 'storage_location_id',
            'listing_match_strategy' => 'marketplace_listings.external_offer_id + marketplace=allegro',
            'allow_suspicious_ignored' => $request->has('allow_suspicious'),
            'suspicious_external_id_count' => 0,
        ] : null;

        if (! is_file($path) || ! is_readable($path)) {
            $base['ok'] = false;
            $base['errors_count'] = 1;
            $base['error'] = 'CSV file not found or not readable.';
            return response()->json($this->withoutNullDiagnostics($base, $request));
        }

        $rows = $this->readCsv($path);
        $base['csv_rows_count'] = count($rows);
        $existingByKey = $this->existingLocationsByKey();
        $warehousePreview = [];
        $items = [];
        $created = 0; $assigned = 0;

        foreach ($rows as $row) {
            $externalOriginal = (string) ($row['external_id'] ?? '');
            $external = $this->normalizeName($externalOriginal);
            $offerId = $this->normalizeName((string) ($row['offer_id'] ?? ''));
            $status = (string) ($row['status'] ?? '');
            if ($onlyActive && ! in_array(Str::lower($status), ['active', 'aktywny'], true)) {
                continue;
            }
            if ($external === '') {
                $base['rows_empty_external_id_count']++;
                $base['skipped_empty_external_id_count']++;
                if ($includeEmpty && count($items) < $sampleLimit) $items[] = $this->item($row, null, null, $externalOriginal, $external, 'skipped_empty_external_id', 'external_id is empty');
                continue;
            }
            $base['rows_with_external_id_count']++;
            $key = $this->warehouseKey($external);
            $warehousePreview[$key] ??= ['warehouse_name' => $external, 'warehouse_key' => $key, 'original_values_sample' => [], 'rows_count' => 0, 'matched_parts_count' => 0, 'exists' => isset($existingByKey[$key])];
            $warehousePreview[$key]['rows_count']++;
            if (count($warehousePreview[$key]['original_values_sample']) < 5 && ! in_array($externalOriginal, $warehousePreview[$key]['original_values_sample'], true)) $warehousePreview[$key]['original_values_sample'][] = $externalOriginal;

            $listing = $this->findListing($offerId);
            if (! $listing) {
                $base['unmatched_listings_count']++; $base['skipped_unmatched_listing_count']++;
                if (count($items) < $sampleLimit) $items[] = $this->item($row, null, null, $externalOriginal, $external, 'skipped_unmatched_listing', 'No local Allegro listing matched offer_id');
                continue;
            }
            $base['matched_listings_count']++;
            if (empty($listing->part_id)) {
                $base['unmatched_parts_count']++; $base['skipped_unmatched_part_count']++;
                if (count($items) < $sampleLimit) $items[] = $this->item($row, $listing, null, $externalOriginal, $external, 'skipped_unmatched_part', 'Listing has no local part_id');
                continue;
            }
            $part = DB::table('parts')->where('id', $listing->part_id)->first();
            if (! $part) {
                $base['unmatched_parts_count']++; $base['skipped_unmatched_part_count']++;
                if (count($items) < $sampleLimit) $items[] = $this->item($row, $listing, null, $externalOriginal, $external, 'skipped_unmatched_part', 'Local part not found');
                continue;
            }
            $base['matched_parts_count']++; $warehousePreview[$key]['matched_parts_count']++;
            $location = $existingByKey[$key] ?? null;
            $action = $location ? 'would_assign_existing_warehouse' : 'would_create_warehouse_and_assign';
            if ($location && (int) $part->storage_location_id === (int) $location->id) { $action = 'unchanged_existing_assignment'; $base['unchanged_parts_count']++; }
            else { $base['would_update_parts_count']++; if (! $location) $base['would_create_warehouses_count'] = count(array_filter($warehousePreview, fn ($p) => ! $p['exists'])); }

            if ($confirmed && $action !== 'unchanged_existing_assignment' && ($location || $createMissing) && $updateExisting) {
                $createdThis = false;
                if (! $location) {
                    $createdThis = true;
                    $id = DB::table('storage_locations')->insertGetId(['name' => $external, 'description' => 'Imported from Allegro export external_id', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                    $location = (object) ['id' => $id, 'name' => $external]; $existingByKey[$key] = $location; $created++; $warehousePreview[$key]['exists'] = true;
                }
                DB::table('parts')->where('id', $part->id)->update(['storage_location_id' => $location->id, 'updated_at' => now()]);
                $assigned++; $action = $createdThis ? 'created_warehouse_and_assigned' : 'assigned_existing_warehouse';
            }
            if (count($items) < $sampleLimit) $items[] = $this->item($row, $listing, $part, $externalOriginal, $external, $action, $this->reason($action), $location);
        }

        $base['processed_rows_count'] = $base['rows_with_external_id_count'];
        $base['unique_external_id_count'] = count($warehousePreview);
        $base['existing_warehouses_count'] = count(array_filter($warehousePreview, fn ($p) => $p['exists']));
        $base['would_create_warehouses_count'] = count(array_filter($warehousePreview, fn ($p) => ! $p['exists']));
        $base['created_warehouses_count'] = $created;
        $base['assigned_parts_count'] = $assigned;
        $base['warehouses_changed'] = $confirmed && $created > 0;
        $base['parts_changed'] = $confirmed && $assigned > 0;
        $base['warehouses_preview'] = array_values($warehousePreview);
        $base['items'] = $items;

        return response()->json($this->withoutNullDiagnostics($base, $request));
    }

    private function baseResponse(bool $applyEndpoint, bool $confirmed): array
    {
        return ['ok' => true, 'read_only' => ! $confirmed, 'local_update' => $confirmed, 'warehouses_changed' => false, 'parts_changed' => false, 'products_changed' => false, 'offers_changed' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false, 'mappings_changed' => false,
            'csv_rows_count' => 0, 'processed_rows_count' => 0, 'rows_with_external_id_count' => 0, 'rows_empty_external_id_count' => 0, 'unique_external_id_count' => 0, 'matched_listings_count' => 0, 'unmatched_listings_count' => 0, 'matched_parts_count' => 0, 'unmatched_parts_count' => 0, 'existing_warehouses_count' => 0, 'would_create_warehouses_count' => 0, 'would_update_parts_count' => 0, 'unchanged_parts_count' => 0, 'created_warehouses_count' => 0, 'assigned_parts_count' => 0, 'skipped_empty_external_id_count' => 0, 'skipped_unmatched_listing_count' => 0, 'skipped_unmatched_part_count' => 0, 'errors_count' => 0, 'warehouses_preview' => [], 'items' => []];
    }

    private function readCsv(string $path): array { $h = fopen($path, 'r'); $headers = fgetcsv($h) ?: []; $rows = []; $n = 1; while (($data = fgetcsv($h)) !== false) { $n++; $row = ['row_number' => $n]; foreach ($headers as $i => $header) $row[trim((string) $header)] = $data[$i] ?? null; $rows[] = $row; } fclose($h); return $rows; }
    private function resolvePath(string $path): string { return Str::startsWith($path, '/') ? $path : base_path($path); }
    private function normalizeName(string $value): string { return trim((string) preg_replace('/\s+/u', ' ', $value)); }
    private function warehouseKey(string $value): string { return Str::lower($this->normalizeName($value)); }
    private function existingLocationsByKey(): array { return DB::table('storage_locations')->get(['id', 'name'])->mapWithKeys(fn ($l) => [$this->warehouseKey($l->name) => $l])->all(); }
    private function findListing(string $offerId): ?object { $q = DB::table('marketplace_listings')->where('external_offer_id', $offerId); if (Schema::hasColumn('marketplace_listings', 'marketplace')) $q->where('marketplace', 'allegro'); return $q->first(); }
    private function item(array $row, ?object $listing, ?object $part, string $orig, string $ext, string $action, string $reason, ?object $loc = null): array { return ['row_number' => $row['row_number'], 'offer_id' => $row['offer_id'] ?? null, 'offer_name' => $row['name'] ?? null, 'external_id_original' => $orig, 'external_id_trimmed' => $ext, 'status' => $row['status'] ?? null, 'stock' => $row['stock'] ?? null, 'listing_id' => $listing->id ?? null, 'part_id' => $part->id ?? ($listing->part_id ?? null), 'product_id' => null, 'current_warehouse_id' => $part->storage_location_id ?? null, 'current_location' => isset($part->storage_location_id) ? optional(DB::table('storage_locations')->where('id', $part->storage_location_id)->first())->name : null, 'suggested_warehouse_name' => $ext ?: null, 'suggested_warehouse_id' => $loc->id ?? null, 'action' => $action, 'reason' => $reason]; }
    private function reason(string $action): string { return match ($action) { 'would_create_warehouse_and_assign' => 'Warehouse does not exist and would be created locally, then assigned to the part', 'would_assign_existing_warehouse' => 'Existing local warehouse would be assigned to the part', 'created_warehouse_and_assigned' => 'Created local warehouse and assigned it to the part', 'assigned_existing_warehouse' => 'Assigned existing local warehouse to the part', 'unchanged_existing_assignment' => 'Part already has this warehouse assigned', default => $action }; }
    private function withoutNullDiagnostics(array $response, Request $request): array { if (! $request->boolean('diagnostics')) unset($response['diagnostics']); return $response; }
}
