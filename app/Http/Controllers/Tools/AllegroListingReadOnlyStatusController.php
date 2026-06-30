<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllegroListingReadOnlyStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->query('token') !== 'gps_images_import_2026') abort(403);

        $listing = $this->listing($request);
        if (! $listing) {
            return response()->json(['ok' => false, 'status' => 'not_found', 'message' => 'No local Allegro listing found for the given identifiers.'], 404);
        }

        $account = $listing->account ?: MarketplaceAccount::query()->where('code', 'allegro_main')->first();
        if (! $account) {
            return response()->json(['ok' => false, 'status' => 'not_configured', 'listing_id' => $listing->id], 422);
        }

        $client = new AllegroApiClient('allegro_main', $account);
        $operationLocation = (string) ($request->query('operation_location') ?: data_get($listing->raw_payload, 'response_summary.operation_location', ''));
        $operation = $operationLocation !== '' ? $client->productOfferOperationStatus($operationLocation) : null;
        $offerId = (string) ($listing->external_offer_id ?: $listing->external_listing_id ?: $request->query('offer_id', ''));
        $offer = $offerId !== '' ? $client->productOffer($offerId) : null;

        $status = data_get($offer, 'json.publication.status') ?: data_get($operation, 'json.offer.publication.status') ?: $listing->status;
        $listing->forceFill([
            'status' => $status,
            'sync_status' => in_array(strtolower((string) $status), ['active', 'published'], true) ? 'published' : $listing->sync_status,
            'last_api_status' => $offer['http_status'] ?? $operation['http_status'] ?? null,
            'last_error' => ($offer && ! ($offer['ok'] ?? false)) || ($operation && ! ($operation['ok'] ?? false)) ? 'Read-only Allegro status check failed; see raw_payload.allegro_read_only_status.' : null,
            'last_synced_at' => now(),
            'raw_payload' => array_replace_recursive($listing->raw_payload ?: [], ['allegro_read_only_status' => ['checked_at' => now()->toISOString(), 'operation_location' => $operationLocation ?: null, 'operation' => $operation, 'offer' => $offer]]),
        ])->save();

        return response()->json(['ok' => true, 'write' => false, 'action' => 'read_only_status_check', 'listing_id' => $listing->id, 'offer_id' => $offerId ?: null, 'operation_location' => $operationLocation ?: null, 'status' => $status, 'operation' => $operation, 'offer' => $offer]);
    }

    private function listing(Request $request): ?MarketplaceListing
    {
        if ($request->filled('listing_id')) return MarketplaceListing::query()->where('marketplace', 'allegro')->find($request->integer('listing_id'));
        $offerId = (string) $request->query('offer_id', '');
        if ($offerId !== '') return MarketplaceListing::query()->where('marketplace', 'allegro')->where(fn ($q) => $q->where('external_offer_id', $offerId)->orWhere('external_listing_id', $offerId))->latest('id')->first();
        $partId = $request->integer('part_id');
        if ($partId > 0) return MarketplaceListing::query()->where('marketplace', 'allegro')->where('part_id', $partId)->latest('id')->first();
        return null;
    }
}
