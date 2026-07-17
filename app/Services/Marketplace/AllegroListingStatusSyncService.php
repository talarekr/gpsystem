<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Support\Arr;

class AllegroListingStatusSyncService
{
    public const CONFIRM = 'SYNC_LOCAL_STATUS';
    public const MODE_DRY_RUN = 'dry-run';
    public const MODE_LIVE = 'live';

    /** @return array<string, array{status: string, sync_status: string, active_indicator: bool}> */
    public function remoteToLocalMapping(): array
    {
        return [
            'ACTIVE' => ['status' => 'active', 'sync_status' => 'mapped', 'active_indicator' => true],
            'INACTIVE' => ['status' => 'inactive', 'sync_status' => 'mapped', 'active_indicator' => false],
            'ACTIVATING' => ['status' => 'publication_pending', 'sync_status' => 'mapped', 'active_indicator' => false],
            'ENDED' => ['status' => 'ended', 'sync_status' => 'ended', 'active_indicator' => false],
        ];
    }

    public function __construct(private readonly PartMarketplaceStatusResolver $resolver) {}

    public function diagnose(array $input): array
    {
        return $this->build($input, self::MODE_DRY_RUN, false);
    }

    public function dryRun(array $input): array
    {
        return $this->build($input, self::MODE_DRY_RUN, false);
    }

    public function sync(array $input): array
    {
        return $this->build($input, self::MODE_LIVE, true);
    }

    private function build(array $input, string $mode, bool $write): array
    {
        $now = now();
        $blockers = [];
        $warnings = [];
        $listing = $this->resolveListing($input, $blockers);
        $part = $listing?->part ?: (isset($input['part_id']) ? Part::query()->with('marketplaceListings.account')->find((int) $input['part_id']) : null);
        $offerId = $this->blankNull($input['offer_id'] ?? null) ?? $this->blankNull($listing?->external_offer_id) ?? $this->blankNull($listing?->external_listing_id);
        $before = $listing ? $this->snapshot($listing, $part) : null;
        $remote = $blockers === [] ? $this->remote($listing, $offerId, $blockers, $warnings) : ['request_attempted' => false, 'http_status' => null, 'publication_status' => null, 'republish' => null, 'stock_available' => null, 'error' => null];
        $mapping = $blockers === [] ? $this->mappingForRemote($remote, $blockers) : null;
        $proposed = $mapping && $listing ? [
            'status' => $mapping['status'],
            'sync_status' => $mapping['sync_status'],
            'last_api_status' => $remote['publication_status'],
            'last_error' => null,
            'last_synced_at' => $now->toISOString(),
            'active_indicator' => $mapping['active_indicator'],
        ] : null;
        $changed = $before && $proposed ? $this->changedFields($before, $proposed) : [];

        if ($mode === self::MODE_LIVE && ($input['confirm'] ?? null) !== self::CONFIRM) {
            $blockers[] = 'live_requires_confirm_SYNC_LOCAL_STATUS';
        }

        $databaseWrite = false;
        if ($write && $listing && $proposed && $blockers === [] && $changed !== []) {
            $listing->forceFill(Arr::only($proposed, ['status', 'sync_status', 'last_api_status', 'last_error', 'last_synced_at']))->save();
            $databaseWrite = true;
        }

        return [
            'input' => ['part_id' => $part?->id ?? ($input['part_id'] ?? null), 'listing_id' => $listing?->id ?? ($input['listing_id'] ?? null), 'offer_id' => $offerId, 'mode' => $mode],
            'before' => $before,
            'remote' => $remote,
            'proposed' => $proposed,
            'changed_fields' => $changed,
            'classification' => $this->classification($before['active_indicator'] ?? false, $remote['publication_status'] ?? null),
            'would_modify_database' => $mode === self::MODE_DRY_RUN ? false : ($changed !== [] && $blockers === []),
            'would_call_allegro_write_api' => false,
            'writes' => ['database' => $databaseWrite, 'allegro' => false],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'remote_to_local_mapping' => $this->remoteToLocalMapping(),
        ];
    }

    private function resolveListing(array $input, array &$blockers): ?MarketplaceListing
    {
        if (isset($input['listing_id']) && (int) $input['listing_id'] > 0) {
            $listing = MarketplaceListing::query()->with('part.marketplaceListings.account', 'account')->find((int) $input['listing_id']);
            if (! $listing) { $blockers[] = 'listing_not_found'; return null; }
            if (! in_array($listing->marketplace, ['allegro', 'allegro_main'], true)) { $blockers[] = 'listing_is_not_allegro'; }
            return $listing;
        }
        $partId = (int) ($input['part_id'] ?? 0);
        if ($partId <= 0) { $blockers[] = 'part_id_or_listing_id_required'; return null; }
        $candidates = MarketplaceListing::query()->with('part.marketplaceListings.account', 'account')->where('part_id', $partId)->whereIn('marketplace', ['allegro', 'allegro_main'])->get();
        if ($candidates->isEmpty()) { $blockers[] = 'allegro_listing_not_found_for_part'; return null; }
        $offerId = $this->blankNull($input['offer_id'] ?? null);
        if ($offerId) $candidates = $candidates->filter(fn (MarketplaceListing $l) => in_array($offerId, [$l->external_offer_id, $l->external_listing_id], true))->values();
        if ($candidates->count() !== 1) { $blockers[] = 'ambiguous_allegro_listing_for_part'; return null; }
        return $candidates->first();
    }

    private function remote(?MarketplaceListing $listing, ?string $offerId, array &$blockers, array &$warnings): array
    {
        $base = ['request_attempted' => false, 'http_status' => null, 'publication_status' => null, 'republish' => null, 'stock_available' => null, 'error' => null];
        if ($offerId === null) { $blockers[] = 'missing_offer_id'; return $base; }
        $account = $listing?->account ?: MarketplaceAccount::query()->where('code', 'allegro_main')->first() ?: MarketplaceAccount::query()->where('marketplace', 'allegro')->first();
        if (! $account) { $blockers[] = 'missing_allegro_account'; return $base; }
        try { $response = (new AllegroApiClient('allegro_main', $account))->productOffer($offerId); }
        catch (\Throwable $e) { $blockers[] = 'allegro_get_failed'; return array_merge($base, ['request_attempted' => true, 'error' => $e->getMessage()]); }
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $status = Arr::get($json, 'publication.status');
        if (! ($response['ok'] ?? false)) $blockers[] = 'allegro_get_non_success_'.$response['http_status'];
        if (($response['ok'] ?? false) && ! is_string($status)) $blockers[] = 'missing_publication_status';
        return ['request_attempted' => true, 'http_status' => $response['http_status'] ?? null, 'publication_status' => $status, 'republish' => Arr::get($json, 'publication.republish'), 'stock_available' => Arr::get($json, 'stock.available'), 'error' => ($response['ok'] ?? false) ? null : (Arr::get($json, 'message') ?? Arr::get($json, 'error') ?? 'allegro_get_non_success')];
    }

    private function mappingForRemote(array $remote, array &$blockers): ?array
    {
        if (($remote['http_status'] ?? null) !== 200) return null;
        $status = strtoupper((string) ($remote['publication_status'] ?? ''));
        $map = $this->remoteToLocalMapping();
        if (! isset($map[$status])) { $blockers[] = 'unsupported_publication_status'; return null; }
        return $map[$status];
    }

    private function snapshot(MarketplaceListing $listing, ?Part $part): array
    {
        $row = $part ? collect($this->resolver->rowsForPart($part->fresh('marketplaceListings')))->firstWhere('key', 'allegro') : [];
        return ['status' => $listing->status, 'sync_status' => $listing->sync_status, 'last_api_status' => $listing->last_api_status, 'last_error' => $listing->last_error, 'last_synced_at' => $listing->last_synced_at?->toISOString(), 'active_indicator' => (bool) ($row['is_active'] ?? false)];
    }

    private function changedFields(array $before, array $proposed): array
    {
        $fields = [];
        foreach (['status', 'sync_status', 'last_api_status', 'last_error'] as $field) if (($before[$field] ?? null) !== ($proposed[$field] ?? null)) $fields[] = $field;
        return $fields;
    }

    private function classification(bool $localActive, ?string $publicationStatus): string
    {
        $remoteActive = strtoupper((string) $publicationStatus) === 'ACTIVE';
        return match (true) {
            $localActive && strtoupper((string) $publicationStatus) === 'ENDED' => 'remote_ended_local_active',
            $localActive && ! $remoteActive => 'remote_inactive_local_active',
            ! $localActive && $remoteActive => 'remote_active_local_inactive',
            $localActive === $remoteActive => 'consistent',
            default => 'unknown',
        };
    }

    private function blankNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
