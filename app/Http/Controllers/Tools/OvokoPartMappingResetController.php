<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class OvokoPartMappingResetController extends Controller
{
    private const CONFIRM = 'reset-ovoko-part-mapping-for-recreate';
    private const MODE = 'detach_ovoko_mapping_for_recreate';
    private const MARKER = 'ovoko_recreate_numeric_id_bridge_v5';

    public function diagnose(Request $request, PublishPartToMarketplacesService $publisher): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        abort_if($partId <= 0, 422, 'Invalid part_id.');

        return response()->json($this->snapshot($partId, $publisher, true));
    }

    public function publishPathDiagnose(Request $request, PublishPartToMarketplacesService $publisher): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        abort_if($partId <= 0, 422, 'Invalid part_id.');

        $snapshot = $this->snapshot($partId, $publisher, true);
        if (! ($snapshot['found'] ?? false)) {
            return response()->json($snapshot);
        }

        $part = Part::query()->with(['marketplaceListings' => fn ($q) => $q->where('marketplace', 'ovoko')->orderByDesc('id')])->findOrFail($partId);
        $preview = $snapshot['publish_decision']['readiness_preview'] ?? [];
        $payload = data_get($preview, 'payload_preview_safe', data_get($preview, 'readiness.prepared_payload_preview_safe', []));
        $activeIdentity = $this->activeOvokoIdentityFields($part->marketplaceListings->first());
        $effectiveIdentity = $this->effectiveOvokoIdentityFieldsForPublish($part);
        $payloadSku = $effectiveIdentity['external_id'] ?? $payload['sku'] ?? $part->sku ?? ('gps-part-'.$part->id);
        $visibleFields = $this->visiblePartCodeFields($part);
        $leakFlags = $this->visibleLeakFlags($part, $effectiveIdentity, $visibleFields, (array) $payload);
        $latestImportPartLog = MarketplaceSyncLog::query()
            ->where('marketplace', 'ovoko')
            ->where('part_id', $partId)
            ->where('action', 'crm/importPart')
            ->latest('created_at')
            ->latest('id')
            ->first();

        $responseOvokoId = $latestImportPartLog?->external_id
            ?: data_get($latestImportPartLog?->payload, 'response.ovoko_part_id')
            ?: data_get($latestImportPartLog?->payload, 'response.response_summary.ovoko_part_id')
            ?: data_get($latestImportPartLog?->payload, 'response.part_id');

        return response()->json([
            'found' => true,
            'marker' => self::MARKER,
            'part_id' => $part->id,
            'read_only' => true,
            'publish_path' => [
                'would_choose' => $snapshot['publish_decision']['would_choose'] ?? null,
                'intended_operation' => $snapshot['publish_decision']['would_choose'] ?? null,
                'duplicate_guard' => $snapshot['publish_decision'],
                'endpoint' => 'POST /crm/importPart',
                'planned_endpoint' => '/crm/importPart',
                'endpoint_source' => 'OvokoPublishAdapter::performLivePublish always calls OvokoApiClient::importPart after readiness and duplicate guard pass.',
            ],
            'local_part_identity' => [
                'sku' => $part->sku,
                'part_code' => $part->part_number,
            ],
            'active_ovoko_identity_fields' => $activeIdentity,
            'effective_ovoko_identity_fields_for_next_publish' => $effectiveIdentity,
            'technical_identity_fields' => [
                'external_id' => $effectiveIdentity['external_id'] ?? $payloadSku,
                'id_bridge' => $effectiveIdentity['id_bridge'] ?? (string) $part->id,
                'id_bridge_is_numeric' => ctype_digit((string) ($effectiveIdentity['id_bridge'] ?? $part->id)),
                'id_bridge_source' => $effectiveIdentity['id_bridge_source'] ?? 'local_part_id_numeric',
            ],
            'visible_part_code_fields' => $visibleFields,
            'payload_identity' => [
                'external_id' => $payloadSku,
                'id_bridge' => $effectiveIdentity['id_bridge'] ?? (string) $part->id,
                'id_bridge_is_numeric' => ctype_digit((string) ($effectiveIdentity['id_bridge'] ?? $part->id)),
                'id_bridge_source' => $effectiveIdentity['id_bridge_source'] ?? 'local_part_id_numeric',
                'visible_code' => $effectiveIdentity['visible_code'] ?? $visibleFields['visible_code'],
                'sku' => $effectiveIdentity['sku'] ?? null,
                'part_code' => $visibleFields['part_code'],
                'manufacturer_code' => $visibleFields['manufacturer_code'],
                'oem_number' => $visibleFields['oem_number'],
                'contains_old_ovoko_id' => $this->payloadContainsAny($effectiveIdentity + (array) $payload, $this->previousOvokoIds($part)),
                'uses_gps_gmail_as_ovoko_identity' => $this->identityUsesGpsGmail($effectiveIdentity),
                'payload_will_use_local_gps_gmail_as_ovoko_identity' => $this->identityUsesGpsGmail($effectiveIdentity),
                'payload_contains_previous_ovoko_id' => $this->payloadContainsAny($effectiveIdentity + (array) $payload, $this->previousOvokoIds($part)),
                'previous_external_offer_id_is_archival' => true,
            ],
            'technical_identity_leaks_to_visible_codes' => $leakFlags['technical_identity_leaks_to_visible_codes'],
            'technical_identity_leaks_to_title' => $leakFlags['technical_identity_leaks_to_title'],
            'payload_contains_gps_part_as_visible_code' => $leakFlags['payload_contains_gps_part_as_visible_code'],
            'payload_contains_gps_gmail_as_visible_code' => $leakFlags['payload_contains_gps_gmail_as_visible_code'],
            'payload_contains_previous_ovoko_id' => $leakFlags['payload_contains_previous_ovoko_id'],
            'local_rematch_controls' => [
                'uses_previous_external_offer_id_as_candidate' => false,
                'previous_external_offer_id_note' => 'Reset stores previous_* under raw_payload.metadata for audit; publish payload uses sku as external_id and does not read metadata.previous_external_offer_id.',
                'lookup_or_rematch_by_sku_before_publish' => false,
                'local_mapping_restored_from' => $responseOvokoId ? 'latest_crm_importPart_response_or_log_external_id' : 'not_observed_in_logs',
            ],
            'latest_import_part_log' => $latestImportPartLog ? [
                'id' => $latestImportPartLog->id,
                'created_at' => optional($latestImportPartLog->created_at)->toISOString(),
                'status' => $latestImportPartLog->status,
                'http_status' => $latestImportPartLog->http_status,
                'external_id' => $latestImportPartLog->external_id,
                'message' => $latestImportPartLog->message,
                'api_response_ovoko_id' => $responseOvokoId,
                'request_external_id' => data_get($latestImportPartLog->payload, 'request.external_id') ?: data_get($latestImportPartLog->payload, 'request.request.external_id') ?: data_get($latestImportPartLog->payload, 'request.sku'),
                'payload_candidates' => $this->ovokoCandidates($latestImportPartLog->payload),
            ] : null,
            'current_mapping' => $snapshot['ovoko_mapping_fields'],
            'visible_code_repair_preview' => $this->visibleCodeRepairPreview($part, $visibleFields, (array) $payload),
            'recommendation' => $this->publishPathRecommendation($responseOvokoId, $payloadSku),
            'safety_flags' => ['read_only' => true, 'no_mutation' => true, 'no_ovoko_request' => true, 'no_publish' => true, 'single_part_only' => true],
        ]);
    }

    public function candidates(Request $request): JsonResponse|Response
    {
        $limit = max(1, min(1000, (int) $request->integer('limit', 100)));
        $includeReadiness = $request->boolean('include_readiness');
        $statuses = collect(explode(',', (string) $request->query('status', 'imported,mapped,confirmed,published,ready')))
            ->map(fn (string $status): string => trim($status))
            ->filter()
            ->values()
            ->all();

        $query = Part::query()
            ->with(['marketplaceListings' => fn ($q) => $q->where('marketplace', 'ovoko')->orderByDesc('id'), 'images'])
            ->whereHas('marketplaceListings', function ($q) use ($statuses, $request): void {
                $q->where('marketplace', 'ovoko')
                    ->where(function ($inner): void {
                        $inner->whereNotNull('external_offer_id')
                            ->orWhereNotNull('external_listing_id')
                            ->orWhereNotNull('external_inventory_id')
                            ->orWhereNotNull('url')
                            ->orWhereNotNull('sku')
                            ->orWhereNotNull('raw_payload');
                    });

                if ($statuses !== []) {
                    $q->where(function ($statusQuery) use ($statuses): void {
                        $statusQuery->whereIn('status', $statuses)
                            ->orWhereIn('sync_status', $statuses)
                            ->orWhereIn('match_status', $statuses);
                    });
                }

                if ($request->boolean('only_with_ovoko_url')) {
                    $q->whereNotNull('url')->where('url', '!=', '');
                }
            });

        if ($request->filled('source_system')) {
            $query->where('source_system', (string) $request->query('source_system'));
        }

        $rows = $query->orderByDesc('id')->limit($limit * 3)->get()
            ->map(fn (Part $part): array => $this->candidateRow($part, $includeReadiness))
            ->filter(function (array $row) use ($request): bool {
                if ($request->boolean('only_gps_gmail') && ! $row['identity_looks_like_gps_gmail']) return false;
                if ($request->boolean('only_missing_price') && ! in_array('brak ceny', $row['readiness_blockers_for_audit'], true)) return false;
                if ($request->boolean('only_with_ovoko_url') && ! $row['has_active_ovoko_url']) return false;
                if (($request->boolean('only_not_ready') || $request->boolean('exclude_ready')) && $row['readiness_blockers_for_audit'] === []) return false;
                if ($request->boolean('exclude_published') && strcasecmp((string) $row['status'], 'published') === 0) return false;
                if ($request->boolean('only_imported') && strcasecmp((string) $row['status'], 'imported') !== 0) return false;

                return true;
            })
            ->take($limit)
            ->values()
            ->all();

        $rowCollection = collect($rows);

        $summary = [
            'total_candidates' => count($rows),
            'candidates_with_gps_gmail_sku' => $rowCollection->where('identity_looks_like_gps_gmail', true)->count(),
            'candidates_with_ovoko_url' => $rowCollection->where('has_active_ovoko_url', true)->count(),
            'candidates_missing_price' => $rowCollection->filter(fn (array $row): bool => in_array('brak ceny', $row['readiness_blockers_for_audit'], true))->count(),
            'candidates_missing_category' => $rowCollection->filter(fn (array $row): bool => in_array('brak kategorii Ovoko', $row['readiness_blockers_for_audit'], true))->count(),
            'candidates_ready_after_reset' => $rowCollection->where('should_create_after_reset', true)->filter(fn (array $row): bool => ($row['readiness_blockers_for_audit'] ?? []) === [])->count(),
            'ready_candidates_count' => $rowCollection->filter(fn (array $row): bool => ($row['readiness_blockers_for_audit'] ?? []) === [])->count(),
            'not_ready_candidates_count' => $rowCollection->filter(fn (array $row): bool => ($row['readiness_blockers_for_audit'] ?? []) !== [])->count(),
            'published_candidates_count' => $rowCollection->filter(fn (array $row): bool => strcasecmp((string) $row['status'], 'published') === 0)->count(),
            'imported_not_ready_candidates_count' => $rowCollection->filter(fn (array $row): bool => strcasecmp((string) $row['status'], 'imported') === 0)->filter(fn (array $row): bool => ($row['readiness_blockers_for_audit'] ?? []) !== [])->count(),
            'safety_flags' => $this->readOnlySafetyFlags(),
        ];

        $responseRows = $rowCollection->map(fn (array $row): array => Arr::except($row, ['readiness_blockers_for_audit']))->values()->all();

        $payload = [
            'marker' => 'ovoko_part_mapping_reset_candidates_not_ready_filter_v2',
            'filters' => $request->only(['limit', 'only_gps_gmail', 'only_missing_price', 'only_with_ovoko_url', 'only_not_ready', 'exclude_ready', 'exclude_published', 'only_imported', 'status', 'source_system', 'include_readiness', 'export']),
            'summary' => $summary,
            'candidates' => $responseRows,
            'safety_flags' => $this->readOnlySafetyFlags(),
        ];

        if ($request->query('export') === 'csv') {
            return $this->csvResponse('ovoko-part-mapping-reset-candidates.csv', $responseRows);
        }

        return response()->json($payload);
    }

    public function preview(Request $request): JsonResponse
    {
        $partIds = collect(explode(',', (string) $request->query('part_ids')))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        abort_if($partIds->isEmpty(), 422, 'Invalid part_ids.');

        $parts = Part::query()->with(['marketplaceListings' => fn ($q) => $q->orderBy('marketplace')->orderByDesc('id')])->whereIn('id', $partIds)->get()->keyBy('id');

        return response()->json([
            'marker' => 'ovoko_part_mapping_reset_candidates_audit_v1',
            'items' => $partIds->map(function (int $partId) use ($parts): array {
                $part = $parts->get($partId);
                if (! $part) return ['part_id' => $partId, 'found' => false, 'safety_flags' => $this->readOnlySafetyFlags()];

                $ovoko = $part->marketplaceListings->where('marketplace', 'ovoko')->first();

                return [
                    'part_id' => $part->id,
                    'found' => true,
                    'current_active_ovoko_identity' => $this->activeOvokoIdentityFields($ovoko),
                    'archived_identity' => $this->archivedOvokoIdentityFields($ovoko),
                    'what_would_be_cleared_by_reset' => $this->fieldsClearedByReset($ovoko),
                    'what_would_be_preserved' => ['parts.*', 'part_images.*', 'parts.price', 'parts.ovoko_price', 'parts.description', 'parts.quantity', 'non_ovoko_marketplace_listings.*', 'marketplace_sync_logs.*'],
                    'post_reset_expected_identity' => ['external_id' => 'gps-part-'.$part->id, 'id_bridge' => (string) $part->id],
                    'safety_flags' => $this->readOnlySafetyFlags(),
                ];
            })->values()->all(),
            'safety_flags' => $this->readOnlySafetyFlags(),
        ]);
    }

    public function reset(Request $request, PublishPartToMarketplacesService $publisher): JsonResponse
    {
        abort_unless($request->input('mode') === self::MODE, 422, 'Invalid mode.');
        abort_unless($request->input('confirm') === self::CONFIRM, 422, 'Missing confirm='.self::CONFIRM.'.');
        $partId = (int) $request->input('part_id');
        abort_if($partId <= 0, 422, 'Invalid part_id.');

        $before = $this->snapshot($partId, $publisher, true);
        abort_unless($before['found'], 404, 'Part not found.');

        $cleared = [];
        DB::transaction(function () use ($partId, &$cleared): void {
            MarketplaceListing::query()
                ->where('part_id', $partId)
                ->where('marketplace', 'ovoko')
                ->orderBy('id')
                ->each(function (MarketplaceListing $listing) use (&$cleared): void {
                    $raw = is_array($listing->raw_payload) ? $listing->raw_payload : [];
                    Arr::set($raw, 'metadata.ovoko_part_mapping_reset_for_recreate', true);
                    Arr::set($raw, 'metadata.reset_marker', self::MARKER);
                    Arr::set($raw, 'metadata.reset_at', now()->toISOString());
                    Arr::set($raw, 'metadata.previous_sku', $listing->sku);
                    Arr::set($raw, 'metadata.previous_external_offer_id', $listing->external_offer_id);
                    Arr::set($raw, 'metadata.previous_external_listing_id', $listing->external_listing_id);
                    Arr::set($raw, 'metadata.previous_external_inventory_id', $listing->external_inventory_id);
                    Arr::set($raw, 'metadata.previous_url', $listing->url);
                    Arr::set($raw, 'metadata.previous_ovoko_part_id', data_get($raw, 'ovoko_part_id') ?: data_get($raw, 'metadata.ovoko_part_id') ?: $listing->external_offer_id ?: $listing->external_listing_id);
                    Arr::forget($raw, ['external_id', 'sku', 'external_inventory_id', 'external_offer_id', 'external_listing_id', 'external_listing_id', 'ovoko_part_id', 'marketplace_external_id', 'listing_id', 'id_bridge', 'part_id', 'metadata.ovoko_part_id']);

                    $listing->fill([
                        'sku' => null,
                        'external_offer_id' => null,
                        'external_listing_id' => null,
                        'external_inventory_id' => null,
                        'url' => null,
                        'status' => 'unlinked',
                        'sync_status' => 'stale',
                        'match_status' => 'unmatched',
                        'raw_payload' => $raw,
                        'last_error' => null,
                    ])->save();

                    $cleared[] = 'marketplace_listings#'.$listing->id.'.sku';
                    $cleared[] = 'marketplace_listings#'.$listing->id.'.external_offer_id';
                    $cleared[] = 'marketplace_listings#'.$listing->id.'.external_listing_id';
                    $cleared[] = 'marketplace_listings#'.$listing->id.'.external_inventory_id';
                    $cleared[] = 'marketplace_listings#'.$listing->id.'.url';
                    $cleared[] = 'marketplace_listings#'.$listing->id.'.status';
                    $cleared[] = 'marketplace_listings#'.$listing->id.'.sync_status';
                    $cleared[] = 'marketplace_listings#'.$listing->id.'.match_status';
                    $cleared[] = 'marketplace_listings#'.$listing->id.'.raw_payload.mapping_keys';
                });
        });

        return response()->json([
            'ok' => true,
            'marker' => self::MARKER,
            'part_id' => $partId,
            'mode' => self::MODE,
            'before' => $before,
            'after' => $this->snapshot($partId, $publisher, true),
            'cleared_fields' => array_values(array_unique($cleared)),
            'preserved_fields' => ['parts.*', 'part_images.*', 'parts.price', 'parts.ovoko_price', 'parts.description', 'parts.quantity', 'non_ovoko_marketplace_listings.*', 'marketplace_sync_logs.*'],
            'safety_flags' => ['single_part_only' => true, 'no_ovoko_request' => true, 'no_delete_part' => true, 'no_allegro_change' => true, 'no_ebay_change' => true, 'no_bulk_update' => true],
        ]);
    }

    private function snapshot(int $partId, PublishPartToMarketplacesService $publisher, bool $readOnly): array
    {
        $part = Part::query()->with(['marketplaceListings' => fn ($q) => $q->orderBy('marketplace')->orderBy('id')])->find($partId);
        if (! $part) return ['found' => false, 'part_id' => $partId, 'safety_flags' => ['read_only' => $readOnly, 'no_mutation' => true, 'no_ovoko_request' => true]];

        $ovokoListings = $part->marketplaceListings->where('marketplace', 'ovoko')->values();
        $blocking = $ovokoListings->filter(fn (MarketplaceListing $l): bool => $this->listingForcesExistingPath($l))->values();
        $preview = $publisher->preview($part, ['ovoko'], false)['channels']['ovoko'] ?? [];

        return [
            'found' => true,
            'marker' => self::MARKER,
            'part_id' => $part->id,
            'sku' => $part->sku,
            'part_code' => $part->part_number,
            'local_price' => ['price' => $part->price, 'ovoko_price' => $part->ovoko_price, 'currency' => $part->currency, 'has_local_price' => is_numeric($part->ovoko_price ?? $part->price)],
            'parts_ovoko_fields' => ['source_system' => $part->source_system, 'external_id' => $part->external_id, 'legacy_url' => $part->legacy_url, 'legacy_payload_ovoko_candidates' => $this->ovokoCandidates($part->legacy_payload)],
            'has_local_ovoko_id' => $ovokoListings->contains(fn ($l) => filled($l->external_offer_id) || filled($l->external_listing_id) || filled(data_get($l->raw_payload, 'ovoko_part_id')) || filled(data_get($l->raw_payload, 'metadata.ovoko_part_id'))),
            'has_ovoko_url' => $ovokoListings->contains(fn ($l) => filled($l->url)),
            'has_local_marketplace_listing_for_ovoko' => $ovokoListings->isNotEmpty(),
            'ovoko_publication_status' => $ovokoListings->pluck('status')->filter()->values()->all(),
            'marketplace_listings' => $part->marketplaceListings->map(fn (MarketplaceListing $l): array => $this->listingSnapshot($l))->values()->all(),
            'ovoko_mapping_fields' => $ovokoListings->map(fn (MarketplaceListing $l): array => $this->listingSnapshot($l))->values()->all(),
            'ovoko_identity_fields_active' => $this->activeOvokoIdentityFields($ovokoListings->last()),
            'ovoko_identity_fields_archived' => $this->archivedOvokoIdentityFields($ovokoListings->last()),
            'publish_decision' => [
                'would_choose' => $blocking->isNotEmpty() ? 'update_or_skip_existing' : 'create',
                'reason' => $blocking->isNotEmpty() ? 'BaseMarketplacePublishAdapter activeListing duplicate guard sees an Ovoko listing with external_offer_id/external_listing_id and non-terminal status.' : 'No active Ovoko marketplace listing with external_offer_id/external_listing_id blocks crm/importPart creation.',
                'blocking_marketplace_listing_ids' => $blocking->pluck('id')->values()->all(),
                'readiness_preview' => $preview,
            ],
            'safety_flags' => ['read_only' => $readOnly, 'no_mutation' => true, 'no_ovoko_request' => true],
        ];
    }

    /** @return array<int, string> */
    private function previousOvokoIds(Part $part): array
    {
        return $part->marketplaceListings
            ->where('marketplace', 'ovoko')
            ->flatMap(fn (MarketplaceListing $listing): array => array_filter([
                (string) data_get($listing->raw_payload, 'metadata.previous_external_offer_id'),
                (string) data_get($listing->raw_payload, 'metadata.previous_external_listing_id'),
            ], fn (string $id): bool => $id !== ''))
            ->unique()
            ->values()
            ->all();
    }

    private function payloadContainsAny(array $payload, array $needles): bool
    {
        $encoded = json_encode($payload);
        if (! is_string($encoded)) return false;

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($encoded, (string) $needle)) return true;
        }

        return false;
    }

    private function publishPathRecommendation(mixed $responseOvokoId, mixed $externalId): array
    {
        return [
            'most_likely_cause' => filled($responseOvokoId) ? 'Ovoko /crm/importPart returned the existing Ovoko ID for the submitted external_id/SKU, so the local listing was recreated from the API response rather than from previous_* metadata.' : 'No importPart response with an Ovoko ID was found in local logs; inspect latest logs and Ovoko API response.',
            'recommended_fix' => 'When an Ovoko mapping has been explicitly reset for recreate, send gps-part-{part_id} only as external_id and send numeric local part_id as id_bridge. Keep visible_code and part-code fields on real part codes only.',
            'current_external_id_would_be' => $externalId,
            'do_not_use_previous_metadata_as_candidate' => true,
            'do_not_bulk_reset' => true,
        ];
    }

    private function listingForcesExistingPath(MarketplaceListing $l): bool
    {
        return (filled($l->external_offer_id) || filled($l->external_listing_id))
            && ! str_starts_with((string) $l->external_offer_id, 'GPSW-')
            && ! str_starts_with((string) $l->external_listing_id, 'GPSW-')
            && ! in_array((string) $l->status, ['ended','failed','deleted','archived','cancelled','historical','stale','unlinked','ENDED','FAILED','DELETED','ARCHIVED','CANCELLED','HISTORICAL','STALE','UNLINKED'], true)
            && ! in_array((string) $l->last_api_status, ['ended','failed','deleted','archived','not_found','ENDED','FAILED','DELETED','ARCHIVED','NOT_FOUND'], true)
            && ! (bool) data_get($l->raw_payload, 'metadata.ovoko_unlinked_for_republish', false);
    }

    private function listingSnapshot(MarketplaceListing $l): array
    {
        return $l->only(['id','marketplace','part_id','external_offer_id','external_listing_id','external_inventory_id','sku','price','quantity','currency','status','url','sync_status','match_status','last_api_status','not_seen_in_active_api_at','last_error']) + ['raw_payload_ovoko_candidates' => $this->ovokoCandidates($l->raw_payload)];
    }

    private function candidateRow(Part $part, bool $includeReadiness): array
    {
        /** @var MarketplaceListing|null $listing */
        $listing = $part->marketplaceListings->first();
        $blockers = $this->readinessBlockers($part);
        $hasActiveMapping = $listing ? $this->hasActiveOvokoIdentity($listing) : false;
        $hasUrl = filled($listing?->url);

        $status = $listing?->status ?? $part->status;
        $identityLooksLikeGpsGmail = $this->identityLooksLikeGpsGmail($part, $listing);
        $resetRecommendedNow = $hasActiveMapping
            && $identityLooksLikeGpsGmail
            && strcasecmp((string) $status, 'published') !== 0
            && $blockers !== [];

        return [
            'part_id' => $part->id,
            'sku' => $part->sku,
            'part_code' => $part->part_number,
            'source_system' => $part->source_system,
            'source_external_id' => $part->external_id,
            'legacy_url' => $part->legacy_url,
            'local_price' => $part->price,
            'ovoko_price' => $part->ovoko_price ?? $listing?->price,
            'marketplace_listing_id' => $listing?->id,
            'ovoko_external_offer_id' => $listing?->external_offer_id,
            'ovoko_external_listing_id' => $listing?->external_listing_id,
            'ovoko_external_inventory_id' => $listing?->external_inventory_id,
            'ovoko_sku' => $listing?->sku,
            'ovoko_url' => $listing?->url,
            'status' => $status,
            'sync_status' => $listing?->sync_status,
            'match_status' => $listing?->match_status,
            'raw_payload_ovoko_part_id' => data_get($listing?->raw_payload, 'ovoko_part_id') ?: data_get($listing?->raw_payload, 'metadata.ovoko_part_id'),
            'identity_looks_like_gps_gmail' => $identityLooksLikeGpsGmail,
            'has_active_ovoko_identity' => $hasActiveMapping,
            'has_active_ovoko_url' => $hasUrl,
            'should_create_after_reset' => $hasActiveMapping,
            'readiness_blockers' => $includeReadiness ? $blockers : [],
            'readiness_blockers_for_audit' => $blockers,
            'reset_recommended_now' => $resetRecommendedNow,
            'suggested_action' => $this->suggestedAction($listing),
        ];
    }

    /** @return array<int, string> */
    private function readinessBlockers(Part $part): array
    {
        $blockers = [];
        if (! is_numeric($part->ovoko_price ?? $part->price) || (float) ($part->ovoko_price ?? $part->price) <= 0) $blockers[] = 'brak ceny';
        if (! $this->hasOvokoCategory($part)) $blockers[] = 'brak kategorii Ovoko';
        if (! is_numeric($part->weight_kg) || ! is_numeric($part->length_cm) || ! is_numeric($part->width_cm) || ! is_numeric($part->height_cm)) $blockers[] = 'brak wymiarów/wagi';
        if ($part->images->isEmpty()) $blockers[] = 'brak zdjęć';
        if (blank($part->car_id) && blank($part->vehicle_snapshot)) $blockers[] = 'brak auta';

        return $blockers;
    }

    private function hasOvokoCategory(Part $part): bool
    {
        if (filled(data_get($part->review_metadata, 'marketplace_category_overrides.ovoko.external_category_id'))) return true;
        if (blank($part->category_id)) return false;

        return DB::table('marketplace_category_mappings')
            ->where('local_category_id', $part->category_id)
            ->where('channel', 'ovoko')
            ->whereNotNull('external_category_id')
            ->exists();
    }

    private function hasActiveOvokoIdentity(MarketplaceListing $listing): bool
    {
        return (filled($listing->external_offer_id) || filled($listing->external_listing_id) || filled($listing->external_inventory_id) || filled($listing->url) || filled($listing->sku))
            && ! in_array((string) $listing->status, ['unlinked', 'archived', 'deleted', 'ended', 'UNLINKED', 'ARCHIVED', 'DELETED', 'ENDED'], true);
    }

    private function identityLooksLikeGpsGmail(Part $part, ?MarketplaceListing $listing): bool
    {
        foreach ([$part->sku, $part->part_number, $listing?->sku, $listing?->external_offer_id, $listing?->external_inventory_id] as $value) {
            if (preg_match('/^GPS-GMAIL-/i', (string) $value) === 1) return true;
        }

        return false;
    }

    private function suggestedAction(?MarketplaceListing $listing): string
    {
        if (! $listing) return 'skip_no_active_ovoko_mapping';
        if (in_array((string) $listing->status, ['unlinked', 'UNLINKED'], true)) return 'skip_already_unlinked';
        if ($this->hasActiveOvokoIdentity($listing)) return 'reset_mapping_for_recreate';

        return 'inspect_manually';
    }

    private function fieldsClearedByReset(?MarketplaceListing $listing): array
    {
        if (! $listing) return [];

        return array_filter([
            'marketplace_listings#'.$listing->id.'.sku' => $listing->sku,
            'marketplace_listings#'.$listing->id.'.external_offer_id' => $listing->external_offer_id,
            'marketplace_listings#'.$listing->id.'.external_listing_id' => $listing->external_listing_id,
            'marketplace_listings#'.$listing->id.'.external_inventory_id' => $listing->external_inventory_id,
            'marketplace_listings#'.$listing->id.'.url' => $listing->url,
            'marketplace_listings#'.$listing->id.'.status' => $listing->status,
            'marketplace_listings#'.$listing->id.'.sync_status' => $listing->sync_status,
            'marketplace_listings#'.$listing->id.'.match_status' => $listing->match_status,
            'marketplace_listings#'.$listing->id.'.raw_payload.mapping_keys' => $this->ovokoCandidates($listing->raw_payload),
        ], fn ($value) => filled($value) || (is_array($value) && $value !== []));
    }

    private function readOnlySafetyFlags(): array
    {
        return ['read_only' => true, 'no_mutation' => true, 'no_ovoko_request' => true, 'no_publish' => true];
    }

    private function csvResponse(string $filename, array $rows): Response
    {
        $headers = array_keys($rows[0] ?? ['part_id' => null]);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($value) => is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? 'true' : 'false') : $value), Arr::only($row, $headers)));
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }

    private function activeOvokoIdentityFields(?MarketplaceListing $listing): array
    {
        return [
            'sku' => $listing?->sku,
            'external_inventory_id' => $listing?->external_inventory_id,
            'external_offer_id' => $listing?->external_offer_id,
            'external_listing_id' => $listing?->external_listing_id,
            'url' => $listing?->url,
        ];
    }

    private function archivedOvokoIdentityFields(?MarketplaceListing $listing): array
    {
        return [
            'previous_sku' => data_get($listing?->raw_payload, 'metadata.previous_sku'),
            'previous_external_inventory_id' => data_get($listing?->raw_payload, 'metadata.previous_external_inventory_id'),
            'previous_external_offer_id' => data_get($listing?->raw_payload, 'metadata.previous_external_offer_id'),
            'previous_external_listing_id' => data_get($listing?->raw_payload, 'metadata.previous_external_listing_id'),
            'previous_url' => data_get($listing?->raw_payload, 'metadata.previous_url'),
            'previous_ovoko_part_id' => data_get($listing?->raw_payload, 'metadata.previous_ovoko_part_id'),
        ];
    }

    private function effectiveOvokoIdentityFieldsForPublish(Part $part): array
    {
        $listing = $part->marketplaceListings->where('marketplace', 'ovoko')->first();
        $resetForRecreate = $listing
            && (bool) data_get($listing->raw_payload, 'metadata.ovoko_part_mapping_reset_for_recreate', false)
            && blank($listing->sku)
            && blank($listing->external_inventory_id)
            && blank($listing->external_offer_id)
            && blank($listing->external_listing_id)
            && in_array((string) $listing->status, ['unlinked', 'stale', 'UNLINKED', 'STALE'], true)
            && in_array((string) $listing->sync_status, ['stale', 'STALE'], true)
            && in_array((string) $listing->match_status, ['unmatched', 'UNMATCHED'], true);

        $externalId = $resetForRecreate ? 'gps-part-'.$part->id : ($part->sku ?? 'gps-part-'.$part->id);

        return [
            'sku' => null,
            'external_id' => $externalId,
            'id_bridge' => (string) $part->id,
            'id_bridge_is_numeric' => ctype_digit((string) $part->id),
            'id_bridge_source' => $resetForRecreate ? 'local_part_id_numeric_after_ovoko_mapping_reset' : 'local_part_id_numeric',
            'visible_code' => $this->visiblePartCodeFields($part)['visible_code'],
            'source' => $resetForRecreate ? 'neutral_part_id_after_ovoko_mapping_reset' : 'part_sku_fallback',
        ];
    }

    private function visiblePartCodeFields(Part $part): array
    {
        $codes = [];
        foreach ([$part->part_number ?? null, $part->oem_number ?? null, $part->manufacturer_code ?? null] as $value) {
            $code = $this->visibleCode($value);
            if ($code !== null && ! in_array($code, $codes, true)) $codes[] = $code;
        }
        $main = $codes[0] ?? null;

        return [
            'main_part_code' => $main,
            'visible_code' => $main,
            'part_code' => $this->visibleCode($part->part_number),
            'manufacturer_code' => $this->visibleCode($part->manufacturer_code),
            'oem_number' => $this->visibleCode($part->oem_number),
            'additional_codes' => $codes,
        ];
    }

    private function visibleCode(mixed $value): ?string
    {
        $code = trim((string) $value);
        if ($code === '') return null;
        if ($this->isTechnicalVisibleCode($code)) return null;
        return $code;
    }


    private function visibleCodeRepairPreview(Part $part, array $visibleFields, array $payload): array
    {
        $rawVisible = [
            'part_number' => $part->part_number,
            'oem_number' => $part->oem_number,
            'manufacturer_code' => $part->manufacturer_code,
            'payload_visible_code' => $payload['visible_code'] ?? null,
            'payload_part_code' => $payload['part_code'] ?? null,
            'payload_optional_codes' => $payload['optional_codes'] ?? [],
        ];
        $rawEncoded = json_encode($rawVisible) ?: '';
        $suggestedTitle = $this->sanitizedVisibleText((string) ($payload['title'] ?? $payload['name'] ?? $part->name ?? ''));

        return [
            'recommended_repair_for_visible_codes' => $this->containsTechnicalCode($rawEncoded) || $this->containsTechnicalCode((string) ($payload['title'] ?? $payload['name'] ?? $part->name ?? '')),
            'suggested_visible_codes' => array_values(array_unique(array_filter($visibleFields['additional_codes'] ?? []))),
            'suggested_title' => $suggestedTitle === '' ? null : $suggestedTitle,
            'safety_flags' => ['read_only' => true, 'no_mutation' => true, 'no_ovoko_request' => true, 'no_publish' => true],
        ];
    }

    private function sanitizedVisibleText(string $value): string
    {
        $value = preg_replace('/\bGPS[-_ ]*GMAIL[-_ ]*\d+\b/i', '', $value) ?? $value;
        $value = preg_replace('/\bGPSGMAIL\d+\b/i', '', $value) ?? $value;
        $value = preg_replace('/\bgps[-_ ]*part[-_ ]*\d+\b/i', '', $value) ?? $value;
        $value = preg_replace('/\bGPSPART\d+\b/i', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function visibleLeakFlags(Part $part, array $identity, array $visibleFields, array $payload): array
    {
        $visiblePayload = $visibleFields + Arr::only($payload, ['visible_code','part_code','manufacturer_code','oem_number','optional_codes','additional_codes','other_code']);
        $visibleEncoded = json_encode($visiblePayload) ?: '';
        $title = $this->sanitizedVisibleText((string) ($payload['title'] ?? $payload['name'] ?? $part->name ?? ''));

        return [
            'technical_identity_leaks_to_visible_codes' => collect([$identity['external_id'] ?? null, $identity['id_bridge'] ?? null])->filter()->contains(fn ($value): bool => $this->payloadContainsAny($visiblePayload, [(string) $value]) || $this->payloadContainsAny($visiblePayload, [$this->compactCode((string) $value)])),
            'technical_identity_leaks_to_title' => $this->containsTechnicalCode($title),
            'payload_contains_gps_part_as_visible_code' => preg_match('/gps[-_ ]*part[-_ ]*\d+/i', $visibleEncoded) === 1 || preg_match('/GPSPART\d+/i', $visibleEncoded) === 1,
            'payload_contains_gps_gmail_as_visible_code' => preg_match('/GPS[-_ ]*GMAIL[-_ ]*\d+/i', $visibleEncoded) === 1 || preg_match('/GPSGMAIL\d+/i', $visibleEncoded) === 1,
            'payload_contains_previous_ovoko_id' => $this->payloadContainsAny($visiblePayload, $this->previousOvokoIds($part)),
        ];
    }

    private function containsTechnicalCode(string $value): bool
    {
        return $this->isTechnicalVisibleCode($value) || preg_match('/gps[-_ ]*part[-_ ]*\d+|GPSPART\d+|GPS[-_ ]*GMAIL[-_ ]*\d+|GPSGMAIL\d+/i', $value) === 1;
    }

    private function isTechnicalVisibleCode(string $value): bool
    {
        $compact = $this->compactCode($value);
        return preg_match('/^GPSPART\d+$/', $compact) === 1 || preg_match('/^GPSGMAIL\d+$/', $compact) === 1 || preg_match('/^GPS-GMAIL-/i', $value) === 1 || preg_match('/^gps-part-\d+$/i', $value) === 1;
    }

    private function compactCode(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $value) ?? $value);
    }

    private function identityUsesGpsGmail(array $identity): bool
    {
        foreach (['sku', 'external_id', 'id_bridge', 'visible_code', 'external_inventory_id'] as $key) {
            if (preg_match('/^GPS-GMAIL-/i', (string) ($identity[$key] ?? '')) === 1) {
                return true;
            }
        }

        return false;
    }

    private function ovokoCandidates(mixed $payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        return array_filter([
            'external_id' => data_get($payload, 'external_id'),
            'ovoko_part_id' => data_get($payload, 'ovoko_part_id'),
            'marketplace_external_id' => data_get($payload, 'marketplace_external_id'),
            'listing_id' => data_get($payload, 'listing_id'),
            'metadata.ovoko_part_id' => data_get($payload, 'metadata.ovoko_part_id'),
            'metadata.previous_external_offer_id' => data_get($payload, 'metadata.previous_external_offer_id'),
            'metadata.previous_url' => data_get($payload, 'metadata.previous_url'),
        ], fn ($value) => filled($value));
    }
}
