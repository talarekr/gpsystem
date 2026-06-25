<?php

namespace App\Http\Controllers\Tools;

use App\Filament\Resources\PartResource;
use App\Http\Controllers\Controller;
use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillPartMarketplacePricesController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(hash_equals(self::TOKEN, (string) $request->query('token', '')), 403);

        $scope = (string) $request->query('scope', 'to_publish');
        $onlyMissing = $request->boolean('only_missing', true);
        $dryRun = $request->boolean('dry_run', true);
        $confirm = $request->boolean('confirm', false);
        $readOnly = $dryRun || ! $confirm;
        $replaceRequested = $request->boolean('replace', false);
        $replace = $replaceRequested && $confirm;
        $batchSize = max(1, min(5000, (int) $request->query('batch_size', 1000)));
        $offset = max(0, (int) $request->query('offset', 0));
        $withDiagnostics = $request->boolean('diagnostics', false);

        if (! in_array($scope, ['to_publish', 'all'], true)) {
            return response()->json($this->basePayload($scope, $onlyMissing, $replace, $readOnly, $withDiagnostics, 'unsupported_scope'));
        }

        if ($scope === 'to_publish' && (! Schema::hasTable('parts') || ! Schema::hasColumn('parts', 'needs_listing'))) {
            return response()->json($this->basePayload($scope, $onlyMissing, $replace, $readOnly, $withDiagnostics, 'scope_to_publish_filter_not_found'));
        }

        if ($replaceRequested && ! $confirm) {
            return response()->json($this->basePayload($scope, $onlyMissing, false, $readOnly, $withDiagnostics, 'replace_requires_confirm'));
        }

        $baseScopeQuery = $this->scopeQuery($scope);
        $matchingQuery = $this->filteredQuery($baseScopeQuery, $request);
        $totalMatchingPartsCount = (clone $matchingQuery)->count();
        $skippedOutOfScopeCount = $this->skippedOutOfScopeCount($request, $scope);
        $parts = (clone $matchingQuery)->orderBy('id')->offset($offset)->limit($batchSize)->get();

        $items = [];
        $wouldUpdatePartsCount = 0;
        $updatedPartsCount = 0;
        $allegroWouldUpdateCount = 0;
        $allegroUpdatedCount = 0;
        $ebayWouldUpdateCount = 0;
        $ebayUpdatedCount = 0;
        $skippedMissingStorePriceCount = 0;
        $skippedAlreadySetCount = 0;
        $errorsCount = 0;
        $sampleWouldUpdatePartIds = [];
        $sampleSkippedMissingStorePricePartIds = [];

        foreach ($parts as $part) {
            try {
                if ($this->isEmptyPrice($part->price)) {
                    $skippedMissingStorePriceCount++;
                    $sampleSkippedMissingStorePricePartIds[] = $part->id;
                    $items[] = $this->item($part, null, null, 'skipped_missing_store_price', 'store_price_missing_null_or_not_positive');
                    continue;
                }

                $storePrice = round((float) $part->price, 2);
                $newAllegroPrice = $storePrice;
                $newEbayPrice = round($storePrice * 1.25, 2);
                $shouldUpdateAllegro = $replace || $this->isEmptyPrice($part->allegro_price);
                $shouldUpdateEbay = $replace || $this->isEmptyPrice($part->ebay_price);

                if (! $shouldUpdateAllegro && ! $shouldUpdateEbay) {
                    $skippedAlreadySetCount++;
                    $items[] = $this->item($part, null, null, 'skipped_already_set', 'allegro_and_ebay_prices_already_positive');
                    continue;
                }

                $wouldUpdatePartsCount++;
                $sampleWouldUpdatePartIds[] = $part->id;
                $allegroWouldUpdateCount += $shouldUpdateAllegro ? 1 : 0;
                $ebayWouldUpdateCount += $shouldUpdateEbay ? 1 : 0;

                if ($readOnly) {
                    $items[] = $this->item($part, $shouldUpdateAllegro ? $newAllegroPrice : null, $shouldUpdateEbay ? $newEbayPrice : null, 'would_update', 'dry_run_or_missing_confirm');
                    continue;
                }

                $result = DB::transaction(function () use ($part, $replace, $newAllegroPrice, $newEbayPrice): array {
                    $lockedPart = Part::query()->lockForUpdate()->findOrFail($part->id);
                    $lockedShouldUpdateAllegro = $replace || $this->isEmptyPrice($lockedPart->allegro_price);
                    $lockedShouldUpdateEbay = $replace || $this->isEmptyPrice($lockedPart->ebay_price);

                    if ($this->isEmptyPrice($lockedPart->price) || (! $lockedShouldUpdateAllegro && ! $lockedShouldUpdateEbay)) {
                        return ['updated' => false, 'allegro_updated' => false, 'ebay_updated' => false];
                    }

                    $update = ['updated_at' => now()];
                    if ($lockedShouldUpdateAllegro) {
                        $update['allegro_price'] = $newAllegroPrice;
                    }
                    if ($lockedShouldUpdateEbay) {
                        $update['ebay_price'] = $newEbayPrice;
                    }

                    DB::table('parts')->where('id', $lockedPart->id)->update($update);

                    return ['updated' => true, 'allegro_updated' => $lockedShouldUpdateAllegro, 'ebay_updated' => $lockedShouldUpdateEbay];
                });

                if (! $result['updated']) {
                    $skippedAlreadySetCount++;
                    $items[] = $this->item($part, null, null, 'skipped_already_set', 'prices_changed_after_lock');
                    continue;
                }

                $updatedPartsCount++;
                $allegroUpdatedCount += $result['allegro_updated'] ? 1 : 0;
                $ebayUpdatedCount += $result['ebay_updated'] ? 1 : 0;
                $items[] = $this->item($part, $result['allegro_updated'] ? $newAllegroPrice : null, $result['ebay_updated'] ? $newEbayPrice : null, 'updated', 'local_parts_prices_backfilled');
            } catch (\Throwable $e) {
                $errorsCount++;
                $items[] = $this->item($part, null, null, 'error', $e->getMessage());
            }
        }

        $payload = array_merge($this->basePayload($scope, $onlyMissing, $replace, $readOnly, $withDiagnostics), [
            'total_matching_parts_count' => $totalMatchingPartsCount,
            'processed_parts_count' => $parts->count(),
            'would_update_parts_count' => $wouldUpdatePartsCount,
            'updated_parts_count' => $updatedPartsCount,
            'allegro_would_update_count' => $allegroWouldUpdateCount,
            'allegro_updated_count' => $allegroUpdatedCount,
            'ebay_would_update_count' => $ebayWouldUpdateCount,
            'ebay_updated_count' => $ebayUpdatedCount,
            'skipped_missing_store_price_count' => $skippedMissingStorePriceCount,
            'skipped_already_set_count' => $skippedAlreadySetCount,
            'skipped_out_of_scope_count' => $skippedOutOfScopeCount,
            'errors_count' => $errorsCount,
            'parts_changed' => ! $readOnly && $updatedPartsCount > 0,
            'items' => $items,
        ]);

        if ($withDiagnostics) {
            $payload['diagnostics'] = array_merge($payload['diagnostics'], $this->diagnostics($scope, $matchingQuery, $sampleWouldUpdatePartIds, $sampleSkippedMissingStorePricePartIds));
        }

        return response()->json($payload);
    }

    private function scopeQuery(string $scope): Builder
    {
        return $scope === 'to_publish' ? PartResource::adminPartsToListQuery() : Part::query();
    }

    private function filteredQuery(Builder $query, Request $request): Builder
    {
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->query('category_id'));
        }
        if ($request->filled('part_id')) {
            $query->whereKey((int) $request->query('part_id'));
        }

        return $query;
    }

    private function skippedOutOfScopeCount(Request $request, string $scope): int
    {
        if (! $request->filled('part_id') || $scope === 'all') {
            return 0;
        }

        $partId = (int) $request->query('part_id');
        $exists = Part::query()->whereKey($partId)->exists();
        $inScope = (clone $this->scopeQuery($scope))->whereKey($partId)->exists();

        return $exists && ! $inScope ? 1 : 0;
    }

    private function isEmptyPrice(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '' || ! is_numeric($value) || (float) $value <= 0;
    }

    private function item(Part $part, ?float $newAllegroPrice, ?float $newEbayPrice, string $action, string $reason): array
    {
        return [
            'part_id' => $part->id,
            'current_price' => $part->price,
            'current_allegro_price' => $part->allegro_price,
            'new_allegro_price' => $newAllegroPrice,
            'current_ebay_price' => $part->ebay_price,
            'new_ebay_price' => $newEbayPrice,
            'current_ovoko_price' => $part->ovoko_price,
            'action' => $action,
            'reason' => $reason,
        ];
    }

    private function basePayload(string $scope, bool $onlyMissing, bool $replace, bool $readOnly, bool $withDiagnostics, ?string $diagnosticReason = null): array
    {
        $payload = [
            'ok' => $diagnosticReason === null,
            'read_only' => $readOnly,
            'local_update' => ! $readOnly,
            'scope' => $scope,
            'only_missing' => $onlyMissing,
            'replace' => $replace,
            'total_matching_parts_count' => 0,
            'processed_parts_count' => 0,
            'would_update_parts_count' => 0,
            'updated_parts_count' => 0,
            'allegro_would_update_count' => 0,
            'allegro_updated_count' => 0,
            'ebay_would_update_count' => 0,
            'ebay_updated_count' => 0,
            'skipped_missing_store_price_count' => 0,
            'skipped_already_set_count' => 0,
            'skipped_out_of_scope_count' => 0,
            'errors_count' => 0,
            'parts_changed' => false,
            'products_changed' => false,
            'offers_changed' => false,
            'mappings_changed' => false,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'items' => [],
        ];

        if ($withDiagnostics) {
            $payload['diagnostics'] = ['reason' => $diagnosticReason];
        }

        return $payload;
    }

    private function diagnostics(string $scope, Builder $matchingQuery, array $sampleWouldUpdatePartIds, array $sampleSkippedMissingStorePricePartIds): array
    {
        $matchingForCounts = clone $matchingQuery;
        $storePriceQuery = fn (Builder $query): Builder => $query->whereNotNull('price')->where('price', '>', 0);
        $missingStorePriceQuery = fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->whereNull('price')->orWhere('price', '<=', 0));
        $missingAllegroQuery = fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->whereNull('allegro_price')->orWhere('allegro_price', '<=', 0));
        $missingEbayQuery = fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->whereNull('ebay_price')->orWhere('ebay_price', '<=', 0));

        return [
            'current_filter_used' => $scope === 'to_publish' ? 'PartResource::adminPartsToListQuery(): parts.needs_listing = true' : 'Part::query(): all parts',
            'admin_to_publish_filter_used' => 'PartResource::adminPartsToListQuery(): parts.needs_listing = true',
            'total_parts_count' => Schema::hasTable('parts') ? Part::query()->count() : 0,
            'total_matching_parts_count' => (clone $matchingQuery)->count(),
            'counts_with_store_price' => $storePriceQuery(clone $matchingForCounts)->count(),
            'counts_missing_store_price' => $missingStorePriceQuery(clone $matchingQuery)->count(),
            'counts_missing_allegro_price' => $missingAllegroQuery(clone $matchingQuery)->count(),
            'counts_missing_ebay_price' => $missingEbayQuery(clone $matchingQuery)->count(),
            'counts_missing_both_allegro_and_ebay' => $missingEbayQuery($missingAllegroQuery(clone $matchingQuery))->count(),
            'sample_matching_part_ids' => (clone $matchingQuery)->orderBy('id')->limit(10)->pluck('id')->values(),
            'sample_would_update_part_ids' => array_values(array_slice(array_unique($sampleWouldUpdatePartIds), 0, 10)),
            'sample_skipped_missing_store_price_part_ids' => array_values(array_slice(array_unique($sampleSkippedMissingStorePricePartIds), 0, 10)),
        ];
    }
}
