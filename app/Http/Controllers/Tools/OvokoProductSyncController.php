<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OvokoProductSyncController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const REQUIRED_FIELDS = [
        'part_number', 'name', 'description', 'price', 'currency', 'quantity', 'storage_location',
        'images', 'public_image_urls', 'ovoko_category_mapping', 'condition',
    ];

    public function dryRun(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $limit = max(1, min(250, (int) $request->query('limit', 50)));
        $page = max(1, (int) $request->query('page', 1));
        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 20)));
        $mode = (string) $request->query('mode', 'create_missing');
        $includeAlreadyListed = $request->boolean('include_already_listed', false);

        if ($mode !== 'create_missing') {
            return response()->json(['ok' => false, 'dry_run' => true, 'ovoko_write' => false, 'blockers' => ['unsupported_mode'], 'mode' => $mode], 422);
        }

        $summary = $this->emptyDryRunSummary($mode, $page, $limit);

        $parts = $this->partsQuery($request)
            ->forPage($page, $limit)
            ->get();

        foreach ($parts as $part) {
            $summary['local_candidate_parts_count']++;
            $analysis = $this->analysePart($part, $includeAlreadyListed);

            if ($analysis['already_has_ovoko_listing']) {
                $summary['already_has_ovoko_listing_count']++;
                $this->pushSample($summary['sample_already_listed'], $analysis['sample'], $sampleLimit);
            } else {
                $summary['missing_ovoko_listing_candidate_count']++;
            }

            if ($analysis['blockers'] !== []) {
                $summary['blocked_count']++;
                $this->pushSample($summary['sample_blocked'], $analysis['sample'], $sampleLimit);
                if ($analysis['already_has_ovoko_listing']) {
                    $this->pushSample($summary['sample_already_listed_blocked'], $analysis['sample'], $sampleLimit);
                } else {
                    $this->pushSample($summary['sample_missing_listing_blocked'], $analysis['sample'], $sampleLimit);
                    $this->pushSample($summary['sample_create_missing_blocked'], $analysis['sample'], $sampleLimit);
                }
                foreach ($analysis['blockers'] as $blocker) {
                    $summary['blockers'][$blocker] = ($summary['blockers'][$blocker] ?? 0) + 1;
                    $bucket = $analysis['already_has_ovoko_listing'] ? 'top_blockers_already_listed' : 'top_blockers_missing_listing';
                    $summary[$bucket][$blocker] = ($summary[$bucket][$blocker] ?? 0) + 1;
                }
            } elseif (! $analysis['already_has_ovoko_listing']) {
                $summary['would_create_ovoko_count']++;
                $this->pushSample($summary['sample_would_create'], $analysis['sample'], $sampleLimit);
                $this->pushSample($summary['sample_payloads'], $analysis['payload'], $sampleLimit);
            }

            if ($analysis['warnings'] !== []) {
                $summary['warning_count']++;
                foreach ($analysis['warnings'] as $warning) $summary['warnings'][$warning] = ($summary['warnings'][$warning] ?? 0) + 1;
            }
        }

        arsort($summary['top_blockers_already_listed']);
        arsort($summary['top_blockers_missing_listing']);
        ksort($summary['blockers']);
        ksort($summary['warnings']);

        return response()->json($summary);
    }

    public function readiness(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 20)));
        $result = ['ok' => true, 'ready_for_ovoko_create_count' => 0, 'already_has_ovoko_listing_count' => 0, 'missing_ovoko_listing_candidate_count' => 0, 'blocked_count' => 0, 'top_blockers' => [], 'top_blockers_already_listed' => [], 'top_blockers_missing_listing' => [], 'sample_ready' => [], 'sample_blocked' => [], 'sample_missing_listing_blocked' => [], 'sample_create_missing_blocked' => [], 'warnings' => ['dry_run_only_no_ovoko_or_other_marketplace_writes']];

        $this->partsQuery($request)->chunkById(200, function ($parts) use (&$result, $sampleLimit): void {
            foreach ($parts as $part) {
                $analysis = $this->analysePart($part, false);
                if ($analysis['already_has_ovoko_listing']) $result['already_has_ovoko_listing_count']++;
                else $result['missing_ovoko_listing_candidate_count']++;
                if ($analysis['blockers'] !== []) {
                    $result['blocked_count']++;
                    $this->pushSample($result['sample_blocked'], $analysis['sample'], $sampleLimit);
                    if (! $analysis['already_has_ovoko_listing']) {
                        $this->pushSample($result['sample_missing_listing_blocked'], $analysis['sample'], $sampleLimit);
                        $this->pushSample($result['sample_create_missing_blocked'], $analysis['sample'], $sampleLimit);
                    }
                    foreach ($analysis['blockers'] as $blocker) {
                        $result['top_blockers'][$blocker] = ($result['top_blockers'][$blocker] ?? 0) + 1;
                        $bucket = $analysis['already_has_ovoko_listing'] ? 'top_blockers_already_listed' : 'top_blockers_missing_listing';
                        $result[$bucket][$blocker] = ($result[$bucket][$blocker] ?? 0) + 1;
                    }
                } elseif (! $analysis['already_has_ovoko_listing']) {
                    $result['ready_for_ovoko_create_count']++;
                    $this->pushSample($result['sample_ready'], $analysis['sample'], $sampleLimit);
                }
            }
        });

        arsort($result['top_blockers']);
        arsort($result['top_blockers_already_listed']);
        arsort($result['top_blockers_missing_listing']);

        return response()->json($result);
    }

    private function analysePart(Part $part, bool $includeAlreadyListed): array
    {
        $part->loadMissing(['images', 'category', 'storageLocation', 'car', 'marketplaceListings']);
        $blockers = [];
        $warnings = [];
        $listing = $this->ovokoListing($part);
        $ovokoListingsCount = $this->ovokoListingsCount($part);
        $mapping = $this->ovokoCategoryMapping($part);
        $description = trim(strip_tags((string) (($part->description ?: $part->short_description) ?? '')));
        $price = $this->ovokoPrice($part);
        $imageCheck = $this->imageUrls($part);

        if ($listing && ! $includeAlreadyListed) $blockers[] = 'already_has_ovoko_listing';
        if ($ovokoListingsCount > 1) $blockers[] = 'conflicting_ovoko_listing_mapping';
        if ($listing && blank($listing->external_offer_id ?? $listing->external_listing_id)) $blockers[] = 'missing_ovoko_external_id';
        if (($part->status ?? null) === 'sold') $blockers[] = 'sold';
        if (($part->status ?? null) === 'archived') $blockers[] = 'archived';
        if ((bool) ($part->needs_review ?? false)) $blockers[] = 'needs_review';
        if ((bool) ($part->needs_listing ?? false)) $blockers[] = 'needs_listing';
        if (! is_numeric($part->quantity) || (int) $part->quantity <= 0) $blockers[] = 'quantity_not_positive';
        if (blank($part->part_number)) $blockers[] = 'missing_part_number';
        if (blank($part->name)) $blockers[] = 'missing_name';
        if ($description === '') $blockers[] = 'missing_description';
        if (! is_numeric($price) || (float) $price <= 0) $blockers[] = 'missing_price';
        if (blank($part->storageLocation?->name)) $blockers[] = 'missing_storage_location';
        if ($imageCheck['count'] < 1) $blockers[] = 'missing_images';
        if ($imageCheck['public_count'] < 1) $blockers[] = 'missing_public_image_url';
        if ($imageCheck['inaccessible_count'] > 0) $blockers[] = 'image_url_not_publicly_accessible';
        if (! $mapping) $blockers[] = 'missing_ovoko_category_mapping';
        elseif ($mapping->is_blocked) $blockers[] = 'blocked_ovoko_category_mapping';
        elseif (blank($mapping->external_category_id)) $blockers[] = 'missing_ovoko_category_id';
        if (blank($part->car_id) && ! is_array($part->vehicle_snapshot)) $warnings[] = 'missing_vehicle_data_check_if_ovoko_requires_it';

        $storageLocation = $this->storageLocationDiagnostics($part);
        $categoryMapping = $this->categoryMappingDiagnostics($part, $mapping);
        $payload = $this->payloadPreview($part, $description, $price, $mapping, $imageCheck['urls']);
        $sample = ['part_id' => $part->id, 'part_number' => $part->part_number, 'name' => $part->name, 'has_ovoko_listing' => (bool) $listing, 'storage_location' => $storageLocation, 'ovoko_category_mapping' => $categoryMapping, 'blockers' => array_values(array_unique($blockers)), 'warnings' => array_values(array_unique($warnings))];

        return ['already_has_ovoko_listing' => (bool) $listing, 'listing' => $listing, 'blockers' => $sample['blockers'], 'warnings' => $sample['warnings'], 'sample' => $sample + ['ovoko_external_id' => $listing?->external_offer_id ?? $listing?->external_listing_id], 'payload' => $payload];
    }

    private function payloadPreview(Part $part, string $description, ?float $price, ?MarketplaceCategoryMapping $mapping, array $imageUrls): array
    {
        return ['dry_run' => true, 'will_make_ovoko_request' => false, 'part_id' => $part->id, 'sku' => $part->sku, 'part_number' => $part->part_number, 'name' => $part->name, 'description' => $description, 'price' => $price, 'currency' => $part->currency ?: 'PLN', 'quantity' => (int) $part->quantity, 'storage_location' => $part->storageLocation?->name, 'ovoko_category_id' => $mapping?->external_category_id, 'local_category_id' => $part->category_id, 'local_category_path' => $part->category?->category_path ?? $part->category?->name, 'image_urls' => $imageUrls, 'vehicle' => ['car_id' => $part->car_id, 'snapshot' => $part->vehicle_snapshot], 'condition' => $part->condition_notes ?: 'used'];
    }

    private function partsQuery(Request $request): Builder
    {
        $query = Part::query()->with(['images', 'category', 'storageLocation', 'car', 'marketplaceListings'])->orderBy('id');
        if (! $request->boolean('include_archived', false)) $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'archived'));
        $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'sold'))->where('quantity', '>', 0);
        if (! $request->boolean('include_needs_review', false)) $query->where(fn ($q) => $q->where('needs_review', false)->orWhereNull('needs_review'));
        if (! $request->boolean('include_needs_listing', false)) $query->where(fn ($q) => $q->where('needs_listing', false)->orWhereNull('needs_listing'));
        if ($request->boolean('only_ready', true)) $query->where(fn ($q) => $q->where('is_visible_storefront', true)->orWhereIn('status', ['ready', 'published']));
        return $query;
    }

    private function imageUrls(Part $part): array
    {
        $urls = [];
        $inaccessible = 0;
        foreach ($part->images as $image) {
            $url = method_exists($image, 'listingUrl') ? $image->listingUrl() : null;
            if (blank($url)) continue;
            $urls[] = $url;
            if (! $this->imageUrlLooksAccessible((string) $url, $image->path ?? null)) $inaccessible++;
        }
        return ['count' => $part->images->count(), 'public_count' => count($urls), 'inaccessible_count' => $inaccessible, 'urls' => array_values($urls)];
    }

    private function imageUrlLooksAccessible(string $url, ?string $path): bool
    {
        if ($path && Storage::disk('public')->exists(ltrim(str_replace('storage/', '', $path), '/'))) return true;
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) return false;
        try { return Http::timeout(3)->head($url)->successful(); } catch (\Throwable) { return false; }
    }

    private function storageLocationDiagnostics(Part $part): array { return ['source' => 'parts.storage_location_id -> storage_locations.name', 'storage_location_id' => $part->storage_location_id, 'resolved_name' => $part->storageLocation?->name, 'has_relation' => $part->storageLocation !== null]; }
    private function categoryMappingDiagnostics(Part $part, ?MarketplaceCategoryMapping $mapping): array { return ['source' => 'marketplace_category_mappings.local_category_id = parts.category_id and channel = ovoko', 'local_category_id' => $part->category_id, 'local_category_path' => $part->category?->category_path ?? $part->category?->name, 'mapping_id' => $mapping?->id, 'channel' => $mapping?->channel, 'external_category_id' => $mapping?->external_category_id, 'is_blocked' => $mapping?->is_blocked]; }
    private function ovokoPrice(Part $part): ?float { $value = $part->ovoko_price ?? null; return is_numeric($value) ? (float) $value : (is_numeric($part->price ?? null) ? (float) $part->price : null); }
    private function ovokoListing(Part $part): ?MarketplaceListing { return $part->marketplaceListings->first(fn ($listing) => $listing->marketplace === 'ovoko'); }
    private function ovokoListingsCount(Part $part): int { return $part->marketplaceListings->filter(fn ($listing) => $listing->marketplace === 'ovoko')->count(); }
    private function ovokoCategoryMapping(Part $part): ?MarketplaceCategoryMapping { if (! Schema::hasTable('marketplace_category_mappings') || ! $part->category_id) return null; return MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->where('channel', 'ovoko')->first(); }
    private function pushSample(array &$items, array $item, int $limit): void { if (count($items) < $limit) $items[] = $item; }
    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }
    private function invalidTokenResponse(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
    private function emptyDryRunSummary(string $mode, int $page, int $limit): array { return ['ok' => true, 'dry_run' => true, 'local_update_only' => false, 'ovoko_write' => false, 'mode' => $mode, 'page' => $page, 'limit' => $limit, 'local_candidate_parts_count' => 0, 'already_has_ovoko_listing_count' => 0, 'missing_ovoko_listing_candidate_count' => 0, 'would_create_ovoko_count' => 0, 'blocked_count' => 0, 'warning_count' => 0, 'sample_would_create' => [], 'sample_already_listed' => [], 'sample_blocked' => [], 'sample_already_listed_blocked' => [], 'sample_missing_listing_blocked' => [], 'sample_create_missing_blocked' => [], 'sample_payloads' => [], 'required_fields' => self::REQUIRED_FIELDS, 'blockers' => [], 'top_blockers_already_listed' => [], 'top_blockers_missing_listing' => [], 'warnings' => ['dry_run_only_no_ovoko_or_other_marketplace_writes' => 1]]; }
}
