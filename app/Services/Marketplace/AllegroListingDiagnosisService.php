<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Support\Arr;

class AllegroListingDiagnosisService
{
    public function __construct(private readonly PartMarketplaceStatusResolver $resolver) {}

    public function diagnosePart(int $partId, ?int $listingId = null, ?string $offerId = null): array
    {
        $part = Part::query()->with('marketplaceListings.account')->find($partId);

        if (! $part) {
            return $this->notFound($partId);
        }

        $listing = $this->listing($part, $listingId, $offerId);
        $resolvedOfferId = $this->blankNull($offerId) ?? $this->blankNull($listing?->external_offer_id) ?? $this->blankNull($listing?->external_listing_id);
        $remote = $this->remote($listing, $resolvedOfferId);
        $local = $this->local($part, $listing, $resolvedOfferId, $remote['publication_status']);
        $comparison = $this->comparison($local['active_indicator'], $remote['publication_status']);

        return [
            'part_id' => $part->id,
            'local' => $local,
            'remote' => $remote,
            'comparison' => $comparison,
            'writes' => ['database' => false, 'allegro' => false],
            'recommended_next_step' => $this->recommendedNextStep($comparison, $remote, $resolvedOfferId),
        ];
    }

    private function notFound(int $partId): array
    {
        return [
            'part_id' => $partId,
            'local' => ['part_status' => null, 'quantity' => null, 'sold' => null, 'listing_id' => null, 'offer_id' => null, 'listing_status' => null, 'sync_status' => null, 'last_api_status' => null, 'last_error' => null, 'last_synced_at' => null, 'active_indicator' => false, 'indicator_reasons' => []],
            'remote' => ['request_attempted' => false, 'http_status' => null, 'publication_status' => null, 'starting_at' => null, 'ending_at' => null, 'republish' => null, 'stock_available' => null, 'error' => 'part_not_found'],
            'comparison' => ['consistent' => false, 'classification' => 'part_not_found', 'local_active' => false, 'remote_active' => false],
            'writes' => ['database' => false, 'allegro' => false],
            'recommended_next_step' => 'Verify the part_id; no local Part row was found.',
        ];
    }

    private function listing(Part $part, ?int $listingId, ?string $offerId): ?MarketplaceListing
    {
        $query = $part->marketplaceListings()->whereIn('marketplace', ['allegro', 'allegro_main'])->with('account');
        if ($listingId) return (clone $query)->whereKey($listingId)->first();
        $offerId = $this->blankNull($offerId);
        if ($offerId) return (clone $query)->where(fn ($q) => $q->where('external_offer_id', $offerId)->orWhere('external_listing_id', $offerId))->latest('id')->first();
        return (clone $query)->latest('id')->first();
    }

    private function local(Part $part, ?MarketplaceListing $listing, ?string $offerId, ?string $remotePublicationStatus): array
    {
        $row = collect($this->resolver->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'allegro') ?? [];
        $active = (bool) ($row['is_active'] ?? false);

        return [
            'part_status' => $part->status,
            'quantity' => (int) $part->quantity,
            'sold' => $part->status === 'sold' || $part->adminLocalAvailability() === 'sold',
            'listing_id' => $listing?->id,
            'offer_id' => $offerId,
            'listing_status' => $listing?->status,
            'sync_status' => $listing?->sync_status,
            'last_api_status' => $listing?->last_api_status,
            'last_error' => $listing?->last_error,
            'last_synced_at' => $listing?->last_synced_at?->toISOString(),
            'active_indicator' => $active,
            'indicator_reasons' => $this->indicatorReasons($part, $listing, $remotePublicationStatus),
        ];
    }

    private function indicatorReasons(Part $part, ?MarketplaceListing $listing, ?string $remotePublicationStatus): array
    {
        $sold = $part->status === 'sold' || $part->adminLocalAvailability() === 'sold';
        $hasReference = $this->blankNull($listing?->external_offer_id) !== null || $this->blankNull($listing?->external_listing_id) !== null || $this->blankNull($listing?->url) !== null;
        $blockingError = in_array(strtolower((string) $listing?->sync_status), ['sync_error', 'error', 'failed'], true) || filled($listing?->last_error);
        $status = strtolower((string) $listing?->status);
        $apiStatus = strtolower((string) $listing?->last_api_status);
        $listingActive = $listing !== null && in_array($status, ['active'], true) && ! in_array($apiStatus, ['error', 'failed'], true);

        return [
            ['condition' => 'part.status === ready', 'actual' => $part->status, 'passed' => $part->status === 'ready'],
            ['condition' => 'quantity > 0', 'actual' => (int) $part->quantity, 'passed' => (int) $part->quantity > 0],
            ['condition' => 'part is not sold', 'actual' => $sold ? 'sold' : 'not_sold', 'passed' => ! $sold],
            ['condition' => 'listing reference exists', 'actual' => $hasReference, 'passed' => $hasReference],
            ['condition' => 'listing has no blocking sync error', 'actual' => $blockingError ? ($listing?->last_error ?: $listing?->sync_status) : null, 'passed' => ! $blockingError],
            ['condition' => 'listing.status === active', 'actual' => $listing?->status, 'passed' => $listingActive],
            ['condition' => 'remote publication status', 'actual' => $remotePublicationStatus, 'used_by_current_indicator' => false],
        ];
    }

    private function remote(?MarketplaceListing $listing, ?string $offerId): array
    {
        $base = ['request_attempted' => false, 'http_status' => null, 'publication_status' => null, 'starting_at' => null, 'ending_at' => null, 'republish' => null, 'stock_available' => null, 'error' => null];
        if ($offerId === null) return $base;
        $account = $listing?->account ?: MarketplaceAccount::query()->where('code', 'allegro_main')->first() ?: MarketplaceAccount::query()->where('marketplace', 'allegro')->first();
        if (! $account) return array_merge($base, ['request_attempted' => false, 'error' => 'missing_allegro_account']);

        try {
            $response = (new AllegroApiClient('allegro_main', $account))->productOffer($offerId);
        } catch (\Throwable $e) {
            return array_merge($base, ['request_attempted' => true, 'error' => 'allegro_get_failed: '.$e->getMessage()]);
        }

        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        return [
            'request_attempted' => true,
            'http_status' => $response['http_status'] ?? null,
            'publication_status' => Arr::get($json, 'publication.status'),
            'starting_at' => Arr::get($json, 'publication.startingAt'),
            'ending_at' => Arr::get($json, 'publication.endingAt'),
            'republish' => Arr::get($json, 'publication.republish'),
            'stock_available' => Arr::get($json, 'stock.available'),
            'error' => ($response['ok'] ?? false) ? null : (Arr::get($json, 'message') ?? Arr::get($json, 'error') ?? 'allegro_get_non_success'),
        ];
    }

    private function comparison(bool $localActive, ?string $publicationStatus): array
    {
        $remoteActive = strtoupper((string) $publicationStatus) === 'ACTIVE';
        $consistent = $localActive === $remoteActive;
        $classification = match (true) {
            $localActive && strtoupper((string) $publicationStatus) === 'ENDED' => 'remote_ended_local_active',
            $localActive && ! $remoteActive => 'remote_inactive_local_active',
            ! $localActive && $remoteActive => 'remote_active_local_inactive',
            $consistent => 'consistent',
            default => 'unknown',
        };
        return ['consistent' => $consistent, 'classification' => $classification, 'local_active' => $localActive, 'remote_active' => $remoteActive];
    }

    private function recommendedNextStep(array $comparison, array $remote, ?string $offerId): string
    {
        if ($offerId === null) return 'No Allegro offer_id is available locally; verify or repair the local mapping before any remote diagnosis.';
        if (($remote['error'] ?? null) !== null) return 'Resolve the Allegro GET error or retry diagnose later; do not sync local status from this result.';
        return match ($comparison['classification'] ?? null) {
            'remote_ended_local_active' => 'Review the listing, then use an explicit sync/repair action if local state should be updated.',
            'remote_active_local_inactive' => 'Review local mapping/status and decide whether an explicit local synchronization is appropriate.',
            'consistent' => 'No status repair is recommended from this diagnostic result.',
            default => 'Review local and remote fields before running any mutating sync action.',
        };
    }

    private function blankNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
