<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\Api\OvokoApiClient;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use App\Services\Marketplace\OvokoStaleListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Schema;

class OvokoListingDiagnoseController extends Controller
{
    public function __invoke(Request $request, PartMarketplaceStatusResolver $resolver, PublishPartToMarketplacesService $publisher): JsonResponse|View
    {
        $inputPartId = trim((string) $request->query('part_id', ''));
        $wantsJson = $request->boolean('json') || $request->expectsJson();

        if ($inputPartId === '') {
            $payload = ['inputPartId' => '', 'partId' => null, 'diagnostics' => null];

            return $wantsJson
                ? response()->json(['read_only' => true, 'required_query' => 'part_id', 'example' => '/admin/tools/ovoko/listing-diagnose?part_id=7498&json=1'])
                : view('admin.tools.ovoko.listing-diagnose', $payload);
        }

        $partId = (int) $inputPartId;
        abort_if($partId <= 0, 422, 'Invalid part_id query parameter.');

        $diagnostics = $this->diagnostics($partId, $resolver, $publisher);

        if ($wantsJson) {
            return response()->json($diagnostics);
        }

        return view('admin.tools.ovoko.listing-diagnose', ['inputPartId' => $inputPartId, 'partId' => $partId, 'diagnostics' => $diagnostics]);
    }

    private function diagnostics(int $partId, PartMarketplaceStatusResolver $resolver, PublishPartToMarketplacesService $publisher): array
    {
        $part = Part::query()->with(['marketplaceListings' => fn ($query) => $query->orderBy('id')])->find($partId);
        $ovokoListings = $part?->marketplaceListings->where('marketplace', 'ovoko')->values() ?? collect();
        $account = MarketplaceAccount::query()->where('code', 'ovoko_main')->first();
        $client = $account ? new OvokoApiClient('ovoko', $account) : null;

        $apiLookups = $part ? $this->apiLookups($part, $ovokoListings, $client) : [];
        $resolverRow = $part ? (collect($resolver->rowsForPart($part))->firstWhere('key', 'ovoko') ?? []) : [];
        $localMatches = $part ? $this->potentialLocalMatches($part, $ovokoListings) : [];

        return [
            'read_only' => true,
            'safety' => [
                'get_has_no_mutations' => true,
                'ovoko_write' => false,
                'local_write' => false,
                'publish_triggered' => false,
                'no_allegro_ebay_payu_checkout_changes' => true,
            ],
            'part_id' => $partId,
            'found' => (bool) $part,
            'local_part' => $part ? $this->partPayload($part) : null,
            'marketplace_listings' => $ovokoListings->map(fn (MarketplaceListing $listing): array => $this->listingPayload($listing))->all(),
            'ovoko_api_mapping' => [
                'account_configured' => (bool) $account,
                'api_enabled' => (bool) ($account?->api_enabled),
                'lookups' => $apiLookups,
                'id_consistency' => $this->idConsistency($ovokoListings, $apiLookups),
            ],
            'potential_existing_ovoko_matches' => array_values(array_merge($localMatches, $this->potentialApiMatches($apiLookups, $ovokoListings))),
            'publish_decision_diagnostics' => $part ? $this->publishDecision($part, $ovokoListings, $publisher) : null,
            'link_resolver' => $part ? $this->linkResolver($resolverRow, $ovokoListings) : null,
            'prepared_repair_action' => $this->preparedRepairAction($partId, $localMatches, $apiLookups),
        ];
    }

    private function partPayload(Part $part): array
    {
        return [
            'part_id' => $part->id,
            'sku' => $part->sku,
            'internal_code' => $part->external_id,
            'gpsw_code' => $part->sku,
            'part_number' => $part->part_number,
            'oem_number' => $part->oem_number,
            'manufacturer_code' => $part->manufacturer_code,
            'title_name' => $part->name,
            'status' => $part->status,
            'quantity' => $part->quantity,
            'price' => $part->price,
            'ovoko_price' => $part->ovoko_price,
            'admin_local_availability' => $part->adminLocalAvailability(),
            'storefront_visibility' => [
                'is_visible_storefront' => (bool) $part->is_visible_storefront,
                'storefront_visible_query' => Part::query()->whereKey($part->id)->storefrontVisible()->exists(),
            ],
            'needs_listing' => (bool) $part->needs_listing,
            'created_at' => optional($part->created_at)->toISOString(),
            'updated_at' => optional($part->updated_at)->toISOString(),
        ];
    }

    private function listingPayload(MarketplaceListing $listing): array
    {
        return [
            'id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'channel' => $listing->marketplace,
            'status' => $listing->status,
            'sync_status' => $listing->sync_status,
            'match_status' => $listing->match_status,
            'external_offer_id' => $listing->external_offer_id,
            'external_listing_id' => $listing->external_listing_id,
            'external_inventory_id' => $listing->external_inventory_id,
            'sku' => $listing->sku,
            'url' => $listing->url,
            'sent_price' => $listing->price,
            'quantity' => $listing->quantity,
            'last_api_status' => $listing->last_api_status,
            'last_error' => $listing->last_error,
            'metadata' => $listing->raw_payload,
            'created_at' => optional($listing->created_at)->toISOString(),
            'updated_at' => optional($listing->updated_at)->toISOString(),
        ];
    }

    private function apiLookups(Part $part, $listings, ?OvokoApiClient $client): array
    {
        if (! $client) return [['lookup_by' => 'account', 'found' => false, 'error' => 'ovoko_main account missing']];
        $lookups = collect();
        foreach ($listings as $listing) foreach (['external_offer_id', 'external_listing_id'] as $field) if (filled($listing->{$field})) $lookups->push([$field, (string) $listing->{$field}, $listing->sku]);
        foreach ([$part->sku, $part->external_id, $part->part_number, $part->oem_number, $part->manufacturer_code] as $code) if (filled($code)) $lookups->push(['sku_or_code', (string) $code, (string) $code]);

        return $lookups->unique(fn ($row) => $row[0].':'.$row[1])->values()->map(function (array $lookup) use ($client): array {
            [$by, $id, $externalId] = $lookup;
            $result = $client->fetchPartRawByLookup($id, $externalId, 2);
            $normalized = $result['normalized'] ?? [];
            return [
                'lookup_by' => $by,
                'lookup_value' => $id,
                'found' => (bool) ($result['api_ok'] ?? false),
                'ovoko_id' => $normalized['external_offer_id'] ?? $result['matched_candidate_id'] ?? null,
                'ovoko_status' => $normalized['status'] ?? null,
                'ovoko_price' => $normalized['price'] ?? null,
                'has_price' => filled($normalized['price'] ?? null) && (float) $normalized['price'] > 0,
                'quantity_availability' => $normalized['quantity'] ?? null,
                'url_from_api' => $normalized['url'] ?? $result['matched_candidate_shop_url'] ?? null,
                'generated_url' => filled($normalized['external_offer_id'] ?? null) ? 'https://ovoko.pl/czesci-samochodowe/hgf'.$normalized['external_offer_id'] : null,
                'state_flags' => $this->ovokoStateFlags($normalized),
                'api_diagnostics' => collect($result)->except(['raw'])->all(),
            ];
        })->all();
    }

    private function potentialLocalMatches(Part $part, $ownListings): array
    {
        $codes = array_values(array_filter(array_unique(array_map('strval', [$part->sku, $part->external_id, $part->part_number, $part->oem_number, $part->manufacturer_code]))));
        if ($codes === [] || ! Schema::hasTable('marketplace_listings')) return [];
        $ownIds = $ownListings->pluck('id')->all();
        return MarketplaceListing::query()->where('marketplace', 'ovoko')->whereNotIn('id', $ownIds)->where(function ($q) use ($codes) {
            $q->whereIn('sku', $codes)->orWhereIn('external_inventory_id', $codes)->orWhereIn('external_offer_id', $codes)->orWhereIn('external_listing_id', $codes);
        })->limit(20)->get()->map(fn (MarketplaceListing $l): array => [
            'potential_existing_ovoko_match' => true,
            'confidence' => 'medium',
            'reason' => 'matched local marketplace_listings by SKU/code/external id, but listing is not attached to requested part_id',
            'ovoko_id' => $l->external_offer_id ?: $l->external_listing_id,
            'ovoko_url' => $l->url,
            'ovoko_status' => $l->status,
            'ovoko_price' => $l->price,
            'local_part_id' => $l->part_id,
            'marketplace_listing_id' => $l->id,
        ])->all();
    }

    private function potentialApiMatches(array $apiLookups, $ownListings): array
    {
        $ownOvokoIds = $ownListings->flatMap(fn ($l) => [$l->external_offer_id, $l->external_listing_id])->filter()->map(fn ($v) => (string) $v)->all();
        return collect($apiLookups)->filter(fn ($r) => ($r['found'] ?? false) && filled($r['ovoko_id'] ?? null) && ! in_array((string) $r['ovoko_id'], $ownOvokoIds, true))->map(fn ($r): array => [
            'potential_existing_ovoko_match' => true,
            'confidence' => $r['lookup_by'] === 'sku_or_code' ? 'high' : 'medium',
            'reason' => 'Ovoko API returned a product for a local SKU/code, but requested part has no local listing with that Ovoko ID',
            'ovoko_id' => $r['ovoko_id'],
            'ovoko_url' => $r['url_from_api'] ?: $r['generated_url'],
            'ovoko_status' => $r['ovoko_status'],
            'ovoko_price' => $r['ovoko_price'],
            'local_part_id' => null,
        ])->values()->all();
    }

    private function publishDecision(Part $part, $listings, PublishPartToMarketplacesService $publisher): array
    {
        $preview = $publisher->preview($part, ['ovoko'], false)['channels']['ovoko'] ?? [];
        $hasExisting = $listings->contains(fn ($l) => ! app(OvokoStaleListingService::class)->ignoredForPublish($l) && (filled($l->external_offer_id) || filled($l->external_listing_id)));
        $hasStaleHistory = $listings->contains(fn ($l) => app(OvokoStaleListingService::class)->ignoredForPublish($l));
        return [
            'current_flow' => 'PublishPartToMarketplacesService -> OvokoPublishAdapter -> crm/importPart',
            'will_detect_existing_local_listing' => $hasExisting,
            'existing_ovoko_listing_detected' => $hasExisting,
            'stale_history_listing_detected' => $hasStaleHistory,
            'ignored_for_publish' => $hasStaleHistory && ! $hasExisting,
            'decision_if_clicked_publish_now' => $hasExisting ? 'blocked_by_duplicate_guard_existing_listing' : ((bool) ($preview['success'] ?? false) ? 'create_new_ovoko_ready' : 'create_or_import_via_crm_importPart'),
            'will_update_existing_ovoko_listing' => false,
            'will_create_new_listing' => ! $hasExisting && (bool) ($preview['success'] ?? false),
            'duplicate_guard' => $hasExisting ? 'would_block_before_api_call' : 'no_local_ovoko_listing_reference_found',
            'url_to_be_saved' => $hasExisting ? ($listings->first()?->url) : 'generated from Ovoko part_id returned by crm/importPart, if API returns part_id',
            'why' => $hasExisting ? 'Base publish adapter only skips when a local Ovoko listing already has an external ID; it does not search Ovoko by SKU before publish.' : 'No local external Ovoko ID was found, so the live adapter would call crm/importPart after readiness passes.',
            'readiness_preview' => $preview,
        ];
    }

    private function linkResolver(array $row, $listings): array
    {
        $source = $listings->first(fn ($l) => ! app(OvokoStaleListingService::class)->ignoredForPublish($l) && (filled($l->external_offer_id) || filled($l->external_listing_id) || filled($l->url)));
        return [
            'source_listing_id' => $source?->id,
            'source_url' => $row['url'] ?? $source?->url,
            'source_external_offer_id' => $row['external_offer_id'] ?? $source?->external_offer_id,
            'historical_or_current' => $source && $source->updated_at && $source->last_synced_at && $source->updated_at->gt($source->last_synced_at) ? 'possibly_historical_after_local_edits' : 'current_local_resolver_value',
            'treated_as_active_imported_mapped' => (bool) ($row['is_active'] ?? false),
            'reason' => $row['reason'] ?? null,
            'why_icon_link_shows_this' => 'PartMarketplaceStatusResolver chooses the first mapped Ovoko listing with an external ID or URL and then uses marketplace_listings.url, or generates https://ovoko.pl/czesci-samochodowe/hgf{external_id}.',
            'resolver_row' => $row,
        ];
    }

    private function idConsistency($listings, array $apiLookups): array
    {
        $local = $listings->flatMap(fn ($l) => [$l->external_offer_id, $l->external_listing_id])->filter()->map(fn ($v) => (string) $v)->unique()->values()->all();
        $remote = collect($apiLookups)->pluck('ovoko_id')->filter()->map(fn ($v) => (string) $v)->unique()->values()->all();
        return ['local_ids' => $local, 'remote_ids' => $remote, 'matching_ids' => array_values(array_intersect($local, $remote)), 'all_local_ids_seen_in_api' => $local !== [] && count(array_diff($local, $remote)) === 0];
    }

    private function ovokoStateFlags(array $normalized): array
    {
        $status = strtolower((string) ($normalized['status'] ?? ''));
        $price = $normalized['price'] ?? null;
        return ['active' => in_array($status, ['active', 'published', 'in_stock', 'in-stock', 'for_sale'], true), 'draft_or_incomplete' => in_array($status, ['draft', 'incomplete', 'imported'], true), 'missing_price' => ! filled($price) || (float) $price <= 0, 'imported' => $status === 'imported', 'mapped' => filled($normalized['external_offer_id'] ?? null)];
    }

    private function preparedRepairAction(int $partId, array $localMatches, array $apiLookups): array
    {
        $ovokoId = collect($localMatches)->pluck('ovoko_id')->merge(collect($apiLookups)->pluck('ovoko_id'))->filter()->first();
        return ['available' => filled($ovokoId), 'not_executed' => true, 'method' => 'POST', 'csrf_required' => true, 'confirm_required' => true, 'endpoint_proposal' => '/admin/tools/ovoko/listing-repair-map', 'payload' => ['part_id' => $partId, 'ovoko_id' => $ovokoId, 'confirm' => 'map-existing-ovoko-listing'], 'scope' => 'single part_id + single ovoko_id; local mapping only; no Ovoko product creation; no URL deletion; no Allegro/eBay changes'];
    }
}
