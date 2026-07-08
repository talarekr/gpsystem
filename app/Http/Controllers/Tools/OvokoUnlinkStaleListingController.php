<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class OvokoUnlinkStaleListingController extends Controller
{
    private const CONFIRM = 'unlink-stale-ovoko-listing';

    public function __invoke(Request $request, PartMarketplaceStatusResolver $resolver, PublishPartToMarketplacesService $publisher): JsonResponse
    {
        $partId = (int) $request->input('part_id');
        abort_if($partId <= 0, 422, 'Invalid part_id parameter.');

        if ($request->isMethod('post')) {
            abort_if((string) $request->input('confirm') !== self::CONFIRM, 422, 'Missing confirm=unlink-stale-ovoko-listing.');
            return response()->json($this->apply($request, $partId, $resolver, $publisher));
        }

        return response()->json($this->preview($partId, $request->integer('marketplace_listing_id') ?: null, $resolver, $publisher));
    }

    private function preview(int $partId, ?int $listingId, PartMarketplaceStatusResolver $resolver, PublishPartToMarketplacesService $publisher): array
    {
        $part = Part::query()->with(['marketplaceListings' => fn ($q) => $q->orderBy('id')])->find($partId);
        abort_if(! $part, 404, 'Part not found.');

        $listings = $part->marketplaceListings->where('marketplace', 'ovoko')->values();
        if ($listingId) $listings = $listings->where('id', $listingId)->values();
        $resolverRow = collect($resolver->rowsForPart($part))->firstWhere('key', 'ovoko') ?: [];
        $publishBefore = $this->publishDecision($part, $publisher);
        $rows = $listings->map(fn (MarketplaceListing $listing): array => $this->candidateRow($part, $listing, $resolverRow))->values()->all();
        $qualifiedIds = collect($rows)->where('qualifies_for_unlink', true)->pluck('id')->values()->all();

        return [
            'ok' => true,
            'read_only' => true,
            'ovoko_write' => false,
            'local_write' => false,
            'part_id' => $partId,
            'local_part' => $this->partPayload($part),
            'marketplace_listings' => $rows,
            'qualified_marketplace_listing_ids' => $qualifiedIds,
            'duplicate_guard_currently_blocks' => in_array(data_get($publishBefore, 'duplicate_guard'), ['candidate_for_update_existing_ovoko', 'active_listing_blocks_duplicate_create'], true),
            'publish_decision_before_apply' => $publishBefore,
            'planned_change_after_apply' => [
                'listing_status' => 'historical',
                'listing_sync_status' => 'historical',
                'metadata.ovoko_unlinked_for_republish' => true,
                'no_ovoko_api_request' => true,
                'expected_publish_route' => $qualifiedIds === [] ? 'unchanged_no_qualified_listing' : 'create_new_ovoko_after_readiness_passes',
            ],
            'bulk_preview' => $this->bulkPreview(),
        ];
    }

    private function apply(Request $request, int $partId, PartMarketplaceStatusResolver $resolver, PublishPartToMarketplacesService $publisher): array
    {
        $listingId = $request->integer('marketplace_listing_id') ?: null;
        $preview = $this->preview($partId, $listingId, $resolver, $publisher);
        $ids = $preview['qualified_marketplace_listing_ids'];
        abort_if($ids === [], 422, 'No qualified stale Ovoko listing to unlink.');

        $adminId = optional($request->user())->id;
        $changed = [];
        DB::transaction(function () use ($ids, $adminId, &$changed): void {
            foreach (MarketplaceListing::query()->whereIn('id', $ids)->lockForUpdate()->get() as $listing) {
                $raw = is_array($listing->raw_payload) ? $listing->raw_payload : [];
                $metadata = (array) ($raw['metadata'] ?? []);
                $metadata = array_merge($metadata, [
                    'ovoko_unlinked_for_republish' => true,
                    'unlinked_at' => now()->toISOString(),
                    'unlinked_by_admin_id' => $adminId,
                    'previous_external_offer_id' => $listing->external_offer_id,
                    'previous_external_listing_id' => $listing->external_listing_id,
                    'previous_url' => $listing->url,
                    'reason' => 'stale_imported_incomplete_ovoko_listing',
                ]);
                $raw['metadata'] = $metadata;
                $listing->forceFill(['status' => 'historical', 'sync_status' => 'historical', 'match_status' => 'historical_unlinked', 'raw_payload' => $raw, 'last_error' => null])->save();
                MarketplaceSyncLog::query()->create(['marketplace' => 'ovoko', 'marketplace_listing_id' => $listing->id, 'part_id' => $listing->part_id, 'action' => 'unlink_stale_ovoko_listing_for_republish', 'status' => 'success', 'external_id' => $metadata['previous_external_offer_id'] ?: $metadata['previous_external_listing_id'], 'message' => 'Local stale/imported Ovoko listing unlinked for future republish; no Ovoko API request was sent.', 'payload' => ['metadata' => $metadata, 'ovoko_write' => false], 'created_at' => now()]);
                $changed[] = $listing->id;
            }
        });

        $part = Part::query()->with('marketplaceListings')->findOrFail($partId);
        $after = $this->publishDecision($part, $publisher);

        return ['ok' => true, 'local_write' => true, 'ovoko_write' => false, 'changed_marketplace_listing_ids' => $changed, 'publish_decision_after_apply' => $after, 'diagnostics_expectation' => ['existing_ovoko_listing_detected' => false, 'stale_history_listing_detected' => true, 'ignored_for_publish' => true, 'decision_if_clicked_publish_now' => ($after['can_publish_later'] ?? false) ? 'create_new_ovoko_ready' : 'create_new_ovoko_after_readiness_passes', 'will_create_new_listing' => (bool) ($after['will_create_new_listing'] ?? false), 'will_update_existing_ovoko_listing' => false, 'duplicate_guard' => $after['duplicate_guard'] ?? null]];
    }

    private function candidateRow(Part $part, MarketplaceListing $listing, array $resolverRow): array
    {
        $status = strtolower((string) $listing->status); $sync = strtolower((string) $listing->sync_status); $match = strtolower((string) $listing->match_status);
        $hasRef = filled($listing->external_offer_id) || filled($listing->external_listing_id) || filled($listing->url);
        $historical = (bool) data_get($listing->raw_payload, 'metadata.ovoko_unlinked_for_republish', false);
        $resolverMatchesListing = ((string) ($resolverRow['external_offer_id'] ?? '') !== '' && in_array((string) $resolverRow['external_offer_id'], array_filter([(string) $listing->external_offer_id, (string) $listing->external_listing_id]), true))
            || ((string) ($resolverRow['url'] ?? '') !== '' && (string) $resolverRow['url'] === (string) $listing->url);
        $activeByResolver = $resolverMatchesListing && (bool) ($resolverRow['is_active'] ?? false);
        $partToList = (bool) $part->needs_listing || ! in_array($part->status, ['ready'], true) || (int) $part->quantity <= 0;
        $staleState = in_array($status, ['imported','mapped','draft','incomplete','stale'], true) || $sync === 'mapped';
        $matched = in_array($match, ['confirmed','matched'], true);
        $priceMissingOrHistorical = $listing->price === null || in_array($status, ['imported','draft','incomplete','stale'], true);
        $blockers = [];
        foreach ([
            [! $hasRef, 'missing_external_offer_id_url'],
            [! $staleState, 'status_sync_not_stale_imported_mapped'],
            [! $matched, 'match_status_not_confirmed_or_matched'],
            [$activeByResolver, 'active_sale_by_resolver'],
            [! $partToList, 'part_ready_with_stock_or_not_needs_listing'],
            [! $priceMissingOrHistorical, 'sent_price_present_and_not_historical'],
            [$historical, 'already_unlinked'],
        ] as [$condition, $reason]) {
            if ($condition) $blockers[] = $reason;
        }
        return ['id' => $listing->id, 'marketplace' => $listing->marketplace, 'status' => $listing->status, 'sync_status' => $listing->sync_status, 'match_status' => $listing->match_status, 'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id, 'url' => $listing->url, 'sent_price' => $listing->price, 'quantity' => $listing->quantity, 'metadata' => $listing->raw_payload, 'active_sale_by_resolver' => $activeByResolver, 'has_external_offer_id_or_url' => $hasRef, 'qualifies_for_unlink' => $blockers === [], 'reason' => $blockers === [] ? 'stale_imported_incomplete_ovoko_listing' : null, 'safety_blockers' => $blockers, 'changes_after_apply' => ['status' => 'historical', 'sync_status' => 'historical', 'match_status' => 'historical_unlinked', 'metadata.ovoko_unlinked_for_republish' => true]];
    }

    private function partPayload(Part $part): array { return ['part_id' => $part->id, 'status' => $part->status, 'quantity' => $part->quantity, 'price' => $part->price, 'ovoko_price' => $part->ovoko_price, 'needs_listing' => (bool) $part->needs_listing, 'admin_local_availability' => $part->adminLocalAvailability()]; }
    private function publishDecision(Part $part, PublishPartToMarketplacesService $publisher): array { $r = $publisher->preview($part, ['ovoko'], false)['channels']['ovoko']['readiness'] ?? []; return Arr::only($r, ['can_publish_later','existing_ovoko_listing_detected','update_existing_ovoko','create_new_ovoko','will_update_existing_ovoko_listing','will_create_new_listing','duplicate_guard','ovoko_update_target_id','local_listing_id','blockers','notes']); }
    private function bulkPreview(): array { return ['enabled' => false, 'apply_available' => false, 'note' => 'Bulk apply intentionally not implemented. Add a separate reviewed decision before enabling writes.']; }
}
