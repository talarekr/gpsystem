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

class BackfillPartDefaultConditionAndSteeringController extends Controller
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
        $batchSize = max(1, min(5000, (int) $request->query('batch_size', 1000)));
        $offset = max(0, (int) $request->query('offset', 0));
        $processAll = $request->boolean('process_all', false);
        $itemsLimit = $processAll ? 100 : null;
        $withDiagnostics = $request->boolean('diagnostics', false);

        $scopeAvailable = Schema::hasTable('parts') && (
            $scope === 'all' || ($scope === 'to_publish' && Schema::hasColumn('parts', 'needs_listing'))
        );
        if (! $scopeAvailable) {
            return response()->json($this->basePayload($scope, $onlyMissing, $readOnly, $withDiagnostics, 'scope_to_publish_filter_not_found', $processAll, $itemsLimit));
        }

        $matchingQuery = $this->scopedQuery($scope);
        $totalMatchingPartsCount = (clone $matchingQuery)->count();
        $items = [];
        $processedPartsCount = 0;
        $wouldUpdatePartsCount = 0;
        $updatedPartsCount = 0;
        $qualityOkCount = 0;
        $qualityWouldUpdateCount = 0;
        $qualityUpdatedCount = 0;
        $steeringWouldUpdateCount = 0;
        $steeringUpdatedCount = 0;
        $steeringAdminVisibleCount = 0;
        $skippedAlreadySetCount = 0;
        $skippedSteeringAlreadyVisibleCount = 0;
        $errorsCount = 0;

        $processPart = function (Part $part) use (&$items, &$processedPartsCount, &$wouldUpdatePartsCount, &$updatedPartsCount, &$qualityOkCount, &$qualityWouldUpdateCount, &$qualityUpdatedCount, &$steeringWouldUpdateCount, &$steeringUpdatedCount, &$steeringAdminVisibleCount, &$skippedAlreadySetCount, &$skippedSteeringAlreadyVisibleCount, &$errorsCount, $onlyMissing, $readOnly, $itemsLimit): void {
            $processedPartsCount++;
            try {
                $currentQuality = $part->condition_notes;
                $qualityOk = ! $this->isMissing($currentQuality);
                $qualityOkCount += $qualityOk ? 1 : 0;
                $shouldFixQuality = ! $qualityOk;
                $vehicleSnapshot = is_array($part->vehicle_snapshot) ? $part->vehicle_snapshot : [];
                $currentSteering = $vehicleSnapshot['steering_side'] ?? null;
                $adminSteeringValue = PartResource::adminSteeringFormValue($currentSteering);
                $steeringAdminVisible = $adminSteeringValue !== null;
                $steeringAdminVisibleCount += $steeringAdminVisible ? 1 : 0;
                $shouldFixSteering = ! $steeringAdminVisible && ($this->isMissing($currentSteering) || ! $onlyMissing);

                if (! $shouldFixQuality && ! $shouldFixSteering) {
                    $skippedAlreadySetCount++;
                    $skippedSteeringAlreadyVisibleCount += $steeringAdminVisible ? 1 : 0;
                    $this->appendItem($items, $itemsLimit, $this->item($part->id, $currentQuality, null, $currentSteering, null, $adminSteeringValue, 'skipped_already_set', $steeringAdminVisible ? 'steering_side_admin_visible' : 'steering_side_non_empty_not_admin_visible_only_missing_enabled'));
                    return;
                }

                $wouldUpdatePartsCount++;
                $qualityWouldUpdateCount += $shouldFixQuality ? 1 : 0;
                $steeringWouldUpdateCount += $shouldFixSteering ? 1 : 0;

                if ($readOnly) {
                    $this->appendItem($items, $itemsLimit, $this->item($part->id, $currentQuality, $shouldFixQuality ? PartResource::DEFAULT_CONDITION_VALUE : null, $currentSteering, $shouldFixSteering ? PartResource::expectedLeftSteeringValue() : null, $adminSteeringValue, 'would_update', 'dry_run_or_missing_confirm'));
                    return;
                }

                $result = DB::transaction(function () use ($part, $onlyMissing): array {
                    $lockedPart = Part::query()->lockForUpdate()->findOrFail($part->id);
                    $lockedCurrentQuality = $lockedPart->condition_notes;
                    $lockedShouldFixQuality = $this->isMissing($lockedCurrentQuality);
                    $lockedVehicleSnapshot = is_array($lockedPart->vehicle_snapshot) ? $lockedPart->vehicle_snapshot : [];
                    $lockedCurrentSteering = $lockedVehicleSnapshot['steering_side'] ?? null;
                    $lockedAdminSteeringValue = PartResource::adminSteeringFormValue($lockedCurrentSteering);
                    $lockedShouldFixSteering = $lockedAdminSteeringValue === null && ($this->isMissing($lockedCurrentSteering) || ! $onlyMissing);

                    if (! $lockedShouldFixQuality && ! $lockedShouldFixSteering) {
                        return ['updated' => false, 'quality_updated' => false, 'steering_updated' => false];
                    }

                    $updates = ['updated_at' => now()];

                    if ($lockedShouldFixQuality) {
                        $updates['condition_notes'] = PartResource::DEFAULT_CONDITION_VALUE;
                    }

                    if ($lockedShouldFixSteering) {
                        $lockedVehicleSnapshot['steering_side'] = PartResource::expectedLeftSteeringValue();
                        $updates['vehicle_snapshot'] = json_encode($lockedVehicleSnapshot, JSON_UNESCAPED_UNICODE);
                    }

                    DB::table('parts')->where('id', $lockedPart->id)->update($updates);

                    return ['updated' => true, 'quality_updated' => $lockedShouldFixQuality, 'steering_updated' => $lockedShouldFixSteering];
                });

                if (! $result['updated']) {
                    $skippedAlreadySetCount++;
                    $this->appendItem($items, $itemsLimit, $this->item($part->id, $currentQuality, null, $currentSteering, null, $adminSteeringValue, 'skipped_already_set', 'steering_side_admin_visible_after_lock'));
                    return;
                }

                $updatedPartsCount++;
                $qualityUpdatedCount += $result['quality_updated'] ? 1 : 0;
                $steeringUpdatedCount += $result['steering_updated'] ? 1 : 0;
                $this->appendItem($items, $itemsLimit, $this->item($part->id, $currentQuality, $result['quality_updated'] ? PartResource::DEFAULT_CONDITION_VALUE : null, $currentSteering, $result['steering_updated'] ? PartResource::expectedLeftSteeringValue() : null, $adminSteeringValue, 'updated', 'local_admin_defaults_backfilled'));
            } catch (\Throwable $e) {
                $errorsCount++;
                $this->appendItem($items, $itemsLimit, $this->item($part->id, $part->condition_notes, null, is_array($part->vehicle_snapshot) ? ($part->vehicle_snapshot['steering_side'] ?? null) : null, null, null, 'error', $e->getMessage()));
            }
        };

        if ($processAll) {
            (clone $matchingQuery)->chunkById($batchSize, function ($parts) use ($processPart): void {
                foreach ($parts as $part) {
                    $processPart($part);
                }
            });
        } else {
            $parts = (clone $matchingQuery)->orderBy('id')->offset($offset)->limit($batchSize)->get();

            foreach ($parts as $part) {
                $processPart($part);
            }
        }

        $payload = $this->basePayload($scope, $onlyMissing, $readOnly, $withDiagnostics, null, $processAll, $itemsLimit);
        $resultPayload = array_merge($payload, [
            'total_matching_parts_count' => $totalMatchingPartsCount,
            'processed_parts_count' => $processedPartsCount,
            'would_update_parts_count' => $wouldUpdatePartsCount,
            'updated_parts_count' => $updatedPartsCount,
            'quality_ok_count' => $qualityOkCount,
            'quality_would_update_count' => $qualityWouldUpdateCount,
            'quality_updated_count' => $qualityUpdatedCount,
            'steering_admin_visible_count' => $steeringAdminVisibleCount,
            'would_fix_steering_count' => $steeringWouldUpdateCount,
            'fixed_steering_count' => $steeringUpdatedCount,
            'steering_would_update_count' => $steeringWouldUpdateCount,
            'steering_updated_count' => $steeringUpdatedCount,
            'skipped_steering_already_visible_count' => $skippedSteeringAlreadyVisibleCount,
            'skipped_already_set_count' => $skippedAlreadySetCount,
            'skipped_out_of_scope_count' => 0,
            'errors_count' => $errorsCount,
            'parts_changed' => ! $readOnly && ($qualityUpdatedCount > 0 || $steeringUpdatedCount > 0),
            'items_truncated' => $itemsLimit !== null && $processedPartsCount > count($items),
            'items_limit' => $itemsLimit,
            'items' => $items,
        ]);

        if ($withDiagnostics) {
            $firstItem = $items[0] ?? [];
            $resultPayload['diagnostics']['current_steering_side_raw'] = $firstItem['current_steering_side_raw'] ?? null;
            $resultPayload['diagnostics']['current_steering_side_admin_visible'] = $firstItem['current_steering_side_admin_visible'] ?? null;
            $resultPayload['diagnostics']['would_fix_steering_count'] = $steeringWouldUpdateCount;
            $resultPayload['diagnostics']['fixed_steering_count'] = $steeringUpdatedCount;
            $resultPayload['diagnostics']['skipped_steering_already_visible_count'] = $skippedSteeringAlreadyVisibleCount;
        }

        return response()->json($resultPayload);
    }

    private function scopedQuery(string $scope): Builder
    {
        return match ($scope) {
            'all' => Part::query(),
            'to_publish' => PartResource::adminPartsToListQuery(),
            default => Part::query()->whereRaw('1 = 0'),
        };
    }

    private function isMissing(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '' || mb_strtolower(trim((string) $value)) === 'do uzupełnienia';
    }

    private function appendItem(array &$items, ?int $itemsLimit, array $item): void
    {
        if ($itemsLimit === null || count($items) < $itemsLimit) {
            $items[] = $item;
        }
    }

    private function item(int $partId, mixed $currentQuality, mixed $newQuality, mixed $currentSteering, mixed $newSteering, mixed $adminVisibleSteeringValue, string $action, string $reason): array
    {
        return ['part_id' => $partId, 'current_quality' => $currentQuality, 'new_quality' => $newQuality, 'current_steering_side' => $currentSteering, 'new_steering_side' => $newSteering, 'current_steering_side_raw' => $currentSteering, 'current_steering_side_admin_visible' => $adminVisibleSteeringValue !== null, 'current_steering_side_admin_value' => $adminVisibleSteeringValue, 'action' => $action, 'reason' => $reason];
    }

    private function countsBy(string $column): array
    {
        if (! Schema::hasTable('parts') || ! Schema::hasColumn('parts', $column)) {
            return [];
        }

        return Part::query()
            ->selectRaw($column.' as value, count(*) as aggregate')
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) ($row->value ?? 'NULL') => (int) $row->aggregate])
            ->all();
    }

    private function countsWithoutMarketplaceListings(): array
    {
        if (! Schema::hasTable('parts') || ! Schema::hasTable('marketplace_listings')) {
            return [];
        }

        return [
            'total' => Part::query()->whereDoesntHave('marketplaceListings')->count(),
            'admin_to_publish' => PartResource::adminPartsToListQuery()->whereDoesntHave('marketplaceListings')->count(),
        ];
    }

    private function basePayload(string $scope, bool $onlyMissing, bool $readOnly, bool $withDiagnostics, ?string $diagnosticReason = null, bool $processAll = false, ?int $itemsLimit = null): array
    {
        $payload = [
            'ok' => $diagnosticReason === null,
            'read_only' => $readOnly,
            'local_update' => ! $readOnly,
            'scope' => $scope,
            'only_missing' => $onlyMissing,
            'process_all' => $processAll,
            'total_matching_parts_count' => 0,
            'processed_parts_count' => 0,
            'would_update_parts_count' => 0,
            'updated_parts_count' => 0,
            'quality_ok_count' => 0,
            'quality_would_update_count' => 0,
            'quality_updated_count' => 0,
            'steering_admin_visible_count' => 0,
            'would_fix_steering_count' => 0,
            'fixed_steering_count' => 0,
            'steering_would_update_count' => 0,
            'steering_updated_count' => 0,
            'skipped_steering_already_visible_count' => 0,
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
            'items_truncated' => false,
            'items_limit' => $itemsLimit,
            'items' => [],
        ];

        if ($withDiagnostics) {
            $adminToPublishQuery = Schema::hasTable('parts') ? PartResource::adminPartsToListQuery() : Part::query()->whereRaw('1 = 0');
            $matchingQuery = $diagnosticReason === null ? $this->scopedQuery($scope) : Part::query()->whereRaw('1 = 0');

            $payload['diagnostics'] = [
                'reason' => $diagnosticReason,
                'admin_steering_field_path' => PartResource::adminSteeringFieldPath(),
                'admin_steering_form_state' => PartResource::ADMIN_STEERING_FORM_STATE,
                'admin_steering_options' => PartResource::adminSteeringOptions(),
                'expected_left_steering_value' => PartResource::expectedLeftSteeringValue(),
                'current_filter_used' => $scope === 'all' ? 'Part::query(): all local parts' : ($scope === 'to_publish' ? 'PartResource::adminPartsToListQuery(): parts.needs_listing = true' : 'unsupported_scope'),
                'admin_to_publish_filter_used' => 'PartResource::adminPartsToListQuery(): parts.needs_listing = true',
                'admin_to_publish_route_name' => 'filament.admin.resources.parts.to-list',
                'admin_to_publish_page' => 'App\\Filament\\Resources\\PartResource\\Pages\\PartsToList',
                'total_parts_count' => Schema::hasTable('parts') ? Part::query()->count() : 0,
                'total_matching_parts_count' => (clone $matchingQuery)->count(),
                'counts_by_status' => $this->countsBy('status'),
                'counts_by_needs_listing' => $this->countsBy('needs_listing'),
                'counts_by_needs_review' => $this->countsBy('needs_review'),
                'counts_without_marketplace_listings' => $this->countsWithoutMarketplaceListings(),
                'sample_matching_part_ids' => (clone $matchingQuery)->orderBy('id')->limit(10)->pluck('id')->values(),
                'sample_admin_to_publish_part_ids' => (clone $adminToPublishQuery)->orderBy('id')->limit(10)->pluck('id')->values(),
                'parts_columns' => Schema::getColumnListing('parts'),
                'available_statuses' => Part::query()->select('status')->distinct()->pluck('status')->values(),
            ];
        }

        return $payload;
    }
}
