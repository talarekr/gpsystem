<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Support\Arr;

class AllegroListingStatusRefreshService
{
    public function refresh(MarketplaceListing $listing, ?string $offerId = null, bool $postPublishSafe = false): array
    {
        $offerId = $this->offerId($listing, $offerId);
        $before = $this->snapshot($listing);

        if ($offerId === null) {
            return ['ok' => false, 'changed' => false, 'listing_id' => $listing->id, 'offer_id' => null, 'before' => $before, 'after' => $before, 'changes' => [], 'api' => ['error' => 'missing_offer_id']];
        }

        $account = $listing->account ?: MarketplaceAccount::query()->where('code', 'allegro_main')->first() ?: MarketplaceAccount::query()->where('marketplace', 'allegro')->first();
        if (! $account) {
            return ['ok' => false, 'changed' => false, 'listing_id' => $listing->id, 'offer_id' => $offerId, 'before' => $before, 'after' => $before, 'changes' => [], 'api' => ['error' => 'missing_allegro_account']];
        }

        $response = (new AllegroApiClient('allegro_main', $account))->productOffer($offerId);
        $json = $response['json'] ?? [];
        $publicationStatus = (string) Arr::get($json, 'publication.status', '');
        $stockAvailable = Arr::get($json, 'stock.available');
        $apiOk = (bool) ($response['ok'] ?? false);
        $isActiveWithStock = $apiOk && strtoupper($publicationStatus) === 'ACTIVE' && is_numeric($stockAvailable) && (int) $stockAvailable > 0;

        $updates = [
            'last_api_status' => $publicationStatus !== '' ? $publicationStatus : ($response['http_status'] ?? null),
            'last_error' => null,
            'last_synced_at' => now(),
        ];

        if ($isActiveWithStock) {
            $updates['status'] = 'active';
        } elseif (! $apiOk) {
            $updates['last_error'] = $json['message'] ?? $json['error'] ?? 'Allegro status refresh failed.';
        } elseif (! $postPublishSafe && strtoupper($publicationStatus) === 'ENDED') {
            $updates['status'] = 'ended';
        } elseif (strtoupper($publicationStatus) === 'ACTIVE') {
            $updates['last_error'] = 'Allegro offer is ACTIVE but stock.available is not greater than 0.';
        }

        $listing->forceFill($updates)->save();
        $listing->refresh();
        $after = $this->snapshot($listing);

        return [
            'ok' => $apiOk,
            'changed' => $before !== $after,
            'listing_id' => $listing->id,
            'offer_id' => $offerId,
            'api' => [
                'http_status' => $response['http_status'] ?? null,
                'publication_status' => $publicationStatus ?: null,
                'stock_available' => $stockAvailable,
                'is_active_with_stock' => $isActiveWithStock,
                'request_id' => $response['request_id'] ?? null,
                'error' => $apiOk ? null : ($json['message'] ?? $json['error'] ?? 'allegro_api_lookup_failed'),
            ],
            'before' => $before,
            'after' => $after,
            'changes' => $this->changes($before, $after),
        ];
    }

    private function offerId(MarketplaceListing $listing, ?string $offerId): ?string
    {
        foreach ([$offerId, $listing->external_offer_id, $listing->external_listing_id] as $value) {
            $id = trim((string) ($value ?? ''));
            if ($id !== '') return $id;
        }

        return null;
    }

    private function snapshot(MarketplaceListing $listing): array
    {
        return [
            'status' => $listing->status,
            'last_api_status' => $listing->last_api_status,
            'last_error' => $listing->last_error,
        ];
    }

    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changes[$key] = ['before' => $before[$key] ?? null, 'after' => $value];
            }
        }

        return $changes;
    }
}
