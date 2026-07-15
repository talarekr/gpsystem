<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\Api\EbayApiClient;
use App\Services\Marketplace\EbayListingStatusNormalizer;
use App\Services\Marketplace\EbayEndedListingLocalCleanupRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EbayListingStatusDiagnoseController extends Controller
{
    public const MARKER = 'ebay_listing_status_single_diagnose_v1';

    public function __invoke(Request $request, EbayListingStatusNormalizer $normalizer, EbayEndedListingLocalCleanupRunnerService $cleanupRunner): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        $part = Part::query()->with('marketplaceListings')->find($partId);
        abort_if(! $part, 404, 'Part not found.');

        $listing = $part->marketplaceListings->whereIn('marketplace', ['ebay_de', 'ebay'])->sortByDesc('id')->first();
        $itemId = $this->itemId($listing);
        $localBlocks = $listing && filled($itemId) && in_array(strtolower((string) $listing->status), ['active', 'published', 'live'], true)
            && ! in_array(strtolower((string) $listing->last_api_status), ['ended', 'failed', 'deleted', 'archived', 'not_found', 'inactive', 'unavailable', 'error'], true)
            && $listing->not_seen_in_active_api_at === null;

        $api = ['request_performed' => false, 'blockers' => []];
        if ($listing && $itemId) {
            $channel = in_array($listing->marketplace, ['ebay_de', 'ebay_fr'], true) ? $listing->marketplace : 'ebay_de';
            $account = MarketplaceAccount::query()->where('code', $channel)->first();
            $api = (new EbayApiClient($channel, $account))->getListingStatusByItemId($itemId);
            $api['request_performed'] = true;
        }

        $normalized = $normalizer->normalize($api);

        $cleanupDiagnostic = $cleanupRunner->diagnosePart($partId);

        return response()->json([
            'ok' => true,
            'read_only' => true,
            'marker' => self::MARKER,
            'normalization_marker' => EbayListingStatusNormalizer::MARKER,
            'part_id' => $part->id,
            'local' => [
                'marketplace_listing_id' => $listing?->id,
                'marketplace' => $listing?->marketplace,
                'ebay_item_id' => $itemId,
                'external_offer_id' => $listing?->external_offer_id,
                'external_listing_id' => $listing?->external_listing_id,
                'local_status' => $listing?->status,
                'local_last_api_status' => $listing?->last_api_status,
                'local_sync_status' => $listing?->sync_status,
                'currently_blocks_relisting' => (bool) $localBlocks,
            ],
            'ebay' => [
                'request_performed' => (bool) ($api['request_performed'] ?? false),
                'item_found' => $normalized['item_found'],
                'http_status' => $api['http_status'] ?? null,
                'raw_status' => $normalized['raw_status'],
                'normalized_status' => $normalized['normalized_status'],
                'start_time' => $normalized['start_time'],
                'end_time' => $normalized['end_time'],
                'end_reason' => $normalized['end_reason'],
                'error_type' => $normalized['error_type'],
                'safe_raw' => $this->safeRaw($api),
            ],
            'decision' => [
                'is_really_active' => $normalized['is_really_active'],
                'should_show_checkmark' => $normalized['should_show_checkmark'],
                'should_allow_relisting' => $normalized['should_allow_relisting'],
            ],
            'cleanup_diagnostic' => $cleanupDiagnostic,
            'would_cleanup' => (bool) ($cleanupDiagnostic['would_cleanup'] ?? false),
            'cleanup_reason' => $cleanupDiagnostic['cleanup_reason'] ?? null,
            'proposed_cleanup_fields' => $cleanupDiagnostic['proposed_cleanup_fields'] ?? null,
            'safety_flags' => [
                'read_only' => true,
                'fresh_recheck_required_before_live_cleanup' => true,
                'api_errors_are_skipped' => true,
                'active_remote_listings_are_skipped' => true,
            ],
            'no_mutation' => true,
        ]);
    }

    private function itemId(?MarketplaceListing $listing): ?string
    {
        foreach ([$listing?->external_listing_id, $listing?->external_offer_id] as $value) {
            if (is_string($value) && preg_match('/\d{6,}/', $value, $m)) return $m[0];
        }
        if ($listing && preg_match('#/itm/(\d+)#', (string) $listing->url, $m)) return $m[1];
        return null;
    }

    private function safeRaw(array $api): array
    {
        return [
            'api_endpoint_family' => 'buy_browse_item_lookup',
            'http_status' => $api['http_status'] ?? null,
            'top_level_keys' => $api['safe_top_level_keys'] ?? [],
            'item_web_url' => $api['item_web_url'] ?? null,
            'availability_status' => $api['availability_status'] ?? null,
            'blockers' => $api['blockers'] ?? [],
            'warnings' => $api['warnings'] ?? [],
        ];
    }
}
