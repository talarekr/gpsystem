<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\OvokoStaleListingService;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class OvokoUnlinkStaleListingController extends Controller
{
    private const CONFIRM = 'unlink-stale-ovoko-listing';

    public function __invoke(Request $request, PartMarketplaceStatusResolver $resolver, PublishPartToMarketplacesService $publisher, OvokoStaleListingService $stale): JsonResponse|View
    {
        if ($request->isMethod('post')) return $this->apply($request, $resolver, $publisher, $stale);

        $payload = $this->preview($request, $resolver, $publisher, $stale);
        return $request->boolean('json') || $request->expectsJson() ? response()->json($payload) : view('admin.tools.ovoko.unlink-stale-listing', $payload);
    }

    private function apply(Request $request, PartMarketplaceStatusResolver $resolver, PublishPartToMarketplacesService $publisher, OvokoStaleListingService $stale): JsonResponse|View
    {
        abort_unless($request->input('confirm') === self::CONFIRM, 422, 'Missing confirm=unlink-stale-ovoko-listing.');
        $partId = (int) $request->input('part_id');
        abort_if($partId <= 0, 422, 'Invalid part_id.');

        $preview = $this->preview($request, $resolver, $publisher, $stale);
        $candidates = collect($preview['qualified_listings'] ?? []);
        if ($request->filled('marketplace_listing_id')) {
            $wanted = (int) $request->input('marketplace_listing_id');
            $candidates = $candidates->where('id', $wanted)->values();
            abort_if($candidates->isEmpty(), 422, 'Requested marketplace_listing_id is not qualified for unlink.');
        }
        abort_if($candidates->isEmpty(), 422, 'No qualified stale Ovoko listing found for this part_id.');

        $changed = [];
        foreach ($candidates as $candidate) {
            $listing = MarketplaceListing::query()->where('part_id', $partId)->where('marketplace', 'ovoko')->findOrFail($candidate['id']);
            $before = $listing->only(['id', 'status', 'sync_status', 'match_status', 'external_offer_id', 'external_listing_id', 'url', 'price', 'last_error']);
            $raw = $listing->raw_payload ?: [];
            Arr::set($raw, 'metadata.ovoko_unlinked_for_republish', true);
            Arr::set($raw, 'metadata.unlinked_at', now()->toISOString());
            Arr::set($raw, 'metadata.unlinked_by_admin_id', optional($request->user())->id);
            Arr::set($raw, 'metadata.previous_external_offer_id', $listing->external_offer_id ?: $listing->external_listing_id);
            Arr::set($raw, 'metadata.previous_url', $listing->url);
            Arr::set($raw, 'metadata.reason', 'stale_imported_incomplete_ovoko_listing');
            $listing->fill(['status' => 'historical', 'sync_status' => 'stale', 'raw_payload' => $raw, 'last_error' => null])->save();
            MarketplaceSyncLog::query()->create(['marketplace' => 'ovoko', 'marketplace_listing_id' => $listing->id, 'part_id' => $partId, 'action' => 'ovoko_unlink_stale_listing_for_republish', 'status' => 'success', 'external_id' => $before['external_offer_id'] ?: $before['external_listing_id'], 'message' => 'Local stale/imported Ovoko listing unlinked for republish; no Ovoko API request was sent.', 'payload' => ['before' => $before, 'after' => $listing->fresh()->only(['id', 'status', 'sync_status', 'match_status', 'external_offer_id', 'external_listing_id', 'url', 'price', 'last_error', 'raw_payload'])], 'created_at' => now()]);
            $changed[] = ['marketplace_listing_id' => $listing->id, 'previous_external_offer_id' => $before['external_offer_id'] ?: $before['external_listing_id'], 'previous_url' => $before['url']];
        }

        $after = $this->preview($request, $resolver, $publisher, $stale);
        $payload = ['applied' => true, 'changed' => $changed, 'safety' => ['ovoko_write' => false, 'remote_delete' => false, 'local_only' => true], 'after' => $after];
        return $request->boolean('json') || $request->expectsJson() ? response()->json($payload) : view('admin.tools.ovoko.unlink-stale-listing', $payload + $after);
    }

    private function preview(Request $request, PartMarketplaceStatusResolver $resolver, PublishPartToMarketplacesService $publisher, OvokoStaleListingService $stale): array
    {
        $partId = (int) $request->query('part_id', $request->input('part_id', 0));
        $part = $partId > 0 ? Part::query()->with(['marketplaceListings' => fn ($q) => $q->orderBy('id')])->find($partId) : null;
        $resolverRow = $part ? (collect($resolver->rowsForPart($part))->firstWhere('key', 'ovoko') ?? []) : [];
        $listings = $part?->marketplaceListings->where('marketplace', 'ovoko')->values() ?? collect();
        $rows = $listings->map(function (MarketplaceListing $listing) use ($part, $resolverRow, $stale): array {
            $resolverExternalId = (string) ($resolverRow['external_offer_id'] ?? '');
            $listingExternalId = (string) ($listing->external_offer_id ?: $listing->external_listing_id);
            $active = (bool) ($resolverRow['is_active'] ?? false) && $resolverExternalId !== '' && $resolverExternalId === $listingExternalId;
            $q = $part ? $stale->qualifies($part, $listing, $active) : ['qualifies' => false, 'reasons' => [], 'safety_blockers' => ['part_missing']];
            return ['id' => $listing->id, 'marketplace' => $listing->marketplace, 'status' => $listing->status, 'sync_status' => $listing->sync_status, 'match_status' => $listing->match_status, 'external_offer_id' => $listing->external_offer_id, 'external_listing_id' => $listing->external_listing_id, 'url' => $listing->url, 'sent_price' => $listing->price, 'active_sale_by_local_logic' => $active, 'ignored_for_publish' => $stale->ignoredForPublish($listing), 'qualifies_for_unlink' => $q['qualifies'], 'reasons' => $q['reasons'], 'safety_blockers' => $q['safety_blockers'], 'metadata' => $listing->raw_payload];
        })->values();
        $preview = $part ? ($publisher->preview($part, ['ovoko'], false)['channels']['ovoko'] ?? []) : [];
        $guardBlocks = $listings->contains(fn (MarketplaceListing $l) => ! $stale->ignoredForPublish($l) && (filled($l->external_offer_id) || filled($l->external_listing_id)) && ! in_array(strtolower((string) $l->status), ['historical', 'stale', 'unlinked', 'ended', 'failed', 'deleted', 'archived'], true));
        $qualified = $rows->where('qualifies_for_unlink', true)->values()->all();
        return ['read_only' => true, 'confirm_required' => self::CONFIRM, 'part_id' => $partId ?: null, 'found' => (bool) $part, 'local_part' => $part ? ['part_id' => $part->id, 'status' => $part->status, 'quantity' => $part->quantity, 'price' => $part->price, 'ovoko_price' => $part->ovoko_price, 'needs_listing' => (bool) $part->needs_listing, 'admin_local_availability' => $part->adminLocalAvailability()] : null, 'marketplace_listings' => $rows->all(), 'qualified_listings' => $qualified, 'duplicate_guard_currently_would_block' => $guardBlocks, 'what_changes_after_apply' => ['status' => 'historical', 'sync_status' => 'stale', 'metadata_flag' => 'metadata.ovoko_unlinked_for_republish=true', 'external_id_and_url_preserved' => true, 'ovoko_api_requests' => false], 'decision_after_apply' => ['existing_ovoko_listing_detected' => false, 'stale_history_listing_detected' => count($qualified) > 0, 'ignored_for_publish' => true, 'decision_if_clicked_publish_now' => ($preview['success'] ?? false) ? 'create_new_ovoko_ready' : 'create_new_ovoko_after_readiness_fixes', 'will_create_new_listing' => (bool) ($preview['success'] ?? false), 'will_update_existing_ovoko_listing' => false, 'duplicate_guard_blocks' => false], 'readiness_preview' => $preview];
    }
}
