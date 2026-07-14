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

class OvokoPartMappingResetController extends Controller
{
    private const CONFIRM = 'reset-ovoko-part-mapping-for-recreate';
    private const MODE = 'detach_ovoko_mapping_for_recreate';
    private const MARKER = 'ovoko_part_mapping_reset_for_recreate_v1';

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
        $payloadSku = $payload['sku'] ?? $part->sku ?? ('gps-part-'.$part->id);
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
            'marker' => 'ovoko_part_recreate_rematched_existing_11582_audit_v1',
            'part_id' => $part->id,
            'read_only' => true,
            'publish_path' => [
                'would_choose' => $snapshot['publish_decision']['would_choose'] ?? null,
                'duplicate_guard' => $snapshot['publish_decision'],
                'endpoint' => 'POST /crm/importPart',
                'endpoint_source' => 'OvokoPublishAdapter::performLivePublish always calls OvokoApiClient::importPart after readiness and duplicate guard pass.',
            ],
            'payload_identity' => [
                'external_id' => $payloadSku,
                'visible_code' => $payloadSku,
                'sku' => $part->sku,
                'part_code' => $part->part_number,
                'manufacturer_code' => $part->manufacturer_code,
                'oem_number' => $part->oem_number,
                'contains_old_ovoko_id' => $this->payloadContainsAny((array) $payload, $this->previousOvokoIds($part)),
                'previous_external_offer_id_is_archival' => true,
            ],
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
            'recommendation' => $this->publishPathRecommendation($responseOvokoId, $payloadSku),
            'safety_flags' => ['read_only' => true, 'no_mutation' => true, 'no_ovoko_request' => true, 'no_publish' => true, 'single_part_only' => true],
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
                    Arr::set($raw, 'metadata.previous_external_offer_id', $listing->external_offer_id);
                    Arr::set($raw, 'metadata.previous_external_listing_id', $listing->external_listing_id);
                    Arr::set($raw, 'metadata.previous_external_inventory_id', $listing->external_inventory_id);
                    Arr::set($raw, 'metadata.previous_url', $listing->url);
                    Arr::forget($raw, ['external_id', 'ovoko_part_id', 'marketplace_external_id', 'listing_id', 'metadata.ovoko_part_id']);

                    $listing->fill([
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
            'recommended_fix' => 'When an Ovoko mapping has been explicitly reset for recreate, keep the operator-facing SKU unchanged but send a fresh importPart external_id/id_bridge value such as gps-part-{part_id}-recreate-{timestamp} to avoid Ovoko-side deduplication on the old SKU/external_id.',
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
