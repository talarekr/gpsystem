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
    private const DEFAULT_QUALITY = 'Używany';
    private const DEFAULT_STEERING_SIDE = 'po lewej';

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
        $withDiagnostics = $request->boolean('diagnostics', false);

        $scopeAvailable = $scope === 'to_publish' && Schema::hasColumn('parts', 'needs_listing');
        if (! $scopeAvailable) {
            return response()->json($this->basePayload($scope, $onlyMissing, $readOnly, $withDiagnostics, 'scope_to_publish_filter_not_found'));
        }

        $matchingQuery = $this->scopedQuery($scope);
        $totalMatchingPartsCount = (clone $matchingQuery)->count();
        $parts = (clone $matchingQuery)->orderBy('id')->offset($offset)->limit($batchSize)->get();

        $items = [];
        $wouldUpdatePartsCount = 0;
        $updatedPartsCount = 0;
        $qualityWouldUpdateCount = 0;
        $qualityUpdatedCount = 0;
        $steeringWouldUpdateCount = 0;
        $steeringUpdatedCount = 0;
        $skippedAlreadySetCount = 0;
        $errorsCount = 0;

        foreach ($parts as $part) {
            try {
                $currentQuality = $part->condition_notes;
                $vehicleSnapshot = is_array($part->vehicle_snapshot) ? $part->vehicle_snapshot : [];
                $currentSteering = $vehicleSnapshot['steering_side'] ?? null;

                $qualityMissing = $this->isMissing($currentQuality);
                $steeringMissing = $this->isMissing($currentSteering);
                $shouldSetQuality = $qualityMissing;
                $shouldSetSteering = $steeringMissing;
                $willUpdate = $shouldSetQuality || $shouldSetSteering;

                if (! $willUpdate) {
                    $skippedAlreadySetCount++;
                    $items[] = $this->item($part->id, $currentQuality, null, $currentSteering, null, 'skipped_already_set', 'quality_and_steering_side_already_set');
                    continue;
                }

                $wouldUpdatePartsCount++;
                $qualityWouldUpdateCount += $shouldSetQuality ? 1 : 0;
                $steeringWouldUpdateCount += $shouldSetSteering ? 1 : 0;

                if ($readOnly) {
                    $items[] = $this->item($part->id, $currentQuality, $shouldSetQuality ? self::DEFAULT_QUALITY : null, $currentSteering, $shouldSetSteering ? self::DEFAULT_STEERING_SIDE : null, 'would_update', 'dry_run_or_missing_confirm');
                    continue;
                }

                DB::transaction(function () use ($part, $vehicleSnapshot, $shouldSetQuality, $shouldSetSteering): void {
                    $updates = ['updated_at' => now()];
                    if ($shouldSetQuality) {
                        $updates['condition_notes'] = self::DEFAULT_QUALITY;
                    }
                    if ($shouldSetSteering) {
                        $vehicleSnapshot['steering_side'] = self::DEFAULT_STEERING_SIDE;
                        $updates['vehicle_snapshot'] = json_encode($vehicleSnapshot, JSON_UNESCAPED_UNICODE);
                    }

                    DB::table('parts')->whereKey($part->id)->update($updates);
                });

                $updatedPartsCount++;
                $qualityUpdatedCount += $shouldSetQuality ? 1 : 0;
                $steeringUpdatedCount += $shouldSetSteering ? 1 : 0;
                $items[] = $this->item($part->id, $currentQuality, $shouldSetQuality ? self::DEFAULT_QUALITY : null, $currentSteering, $shouldSetSteering ? self::DEFAULT_STEERING_SIDE : null, 'updated', 'local_defaults_backfilled');
            } catch (\Throwable $e) {
                $errorsCount++;
                $items[] = $this->item($part->id, $part->condition_notes, null, is_array($part->vehicle_snapshot) ? ($part->vehicle_snapshot['steering_side'] ?? null) : null, null, 'error', $e->getMessage());
            }
        }

        $payload = $this->basePayload($scope, $onlyMissing, $readOnly, $withDiagnostics);
        return response()->json(array_merge($payload, [
            'total_matching_parts_count' => $totalMatchingPartsCount,
            'processed_parts_count' => $parts->count(),
            'would_update_parts_count' => $wouldUpdatePartsCount,
            'updated_parts_count' => $updatedPartsCount,
            'quality_would_update_count' => $qualityWouldUpdateCount,
            'quality_updated_count' => $qualityUpdatedCount,
            'steering_would_update_count' => $steeringWouldUpdateCount,
            'steering_updated_count' => $steeringUpdatedCount,
            'skipped_already_set_count' => $skippedAlreadySetCount,
            'skipped_out_of_scope_count' => 0,
            'errors_count' => $errorsCount,
            'parts_changed' => ! $readOnly && $updatedPartsCount > 0,
            'items' => $items,
        ]));
    }

    private function scopedQuery(string $scope): Builder
    {
        return match ($scope) {
            'to_publish' => PartResource::adminPartsToListQuery(),
            default => Part::query()->whereRaw('1 = 0'),
        };
    }

    private function isMissing(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '' || mb_strtolower(trim((string) $value)) === 'do uzupełnienia';
    }

    private function item(int $partId, mixed $currentQuality, mixed $newQuality, mixed $currentSteering, mixed $newSteering, string $action, string $reason): array
    {
        return ['part_id' => $partId, 'current_quality' => $currentQuality, 'new_quality' => $newQuality, 'current_steering_side' => $currentSteering, 'new_steering_side' => $newSteering, 'action' => $action, 'reason' => $reason];
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

    private function basePayload(string $scope, bool $onlyMissing, bool $readOnly, bool $withDiagnostics, ?string $diagnosticReason = null): array
    {
        $payload = [
            'ok' => $diagnosticReason === null,
            'read_only' => $readOnly,
            'local_update' => ! $readOnly,
            'scope' => $scope,
            'only_missing' => $onlyMissing,
            'total_matching_parts_count' => 0,
            'processed_parts_count' => 0,
            'would_update_parts_count' => 0,
            'updated_parts_count' => 0,
            'quality_would_update_count' => 0,
            'quality_updated_count' => 0,
            'steering_would_update_count' => 0,
            'steering_updated_count' => 0,
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
            $adminToPublishQuery = Schema::hasTable('parts') ? PartResource::adminPartsToListQuery() : Part::query()->whereRaw('1 = 0');
            $matchingQuery = $diagnosticReason === null ? $this->scopedQuery($scope) : Part::query()->whereRaw('1 = 0');

            $payload['diagnostics'] = [
                'reason' => $diagnosticReason,
                'current_filter_used' => $scope === 'to_publish' ? 'PartResource::adminPartsToListQuery(): parts.needs_listing = true' : 'unsupported_scope',
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
