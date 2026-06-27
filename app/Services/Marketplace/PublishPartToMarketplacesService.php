<?php

namespace App\Services\Marketplace;

use App\Models\Part;
use App\Services\Marketplace\Publishing\AllegroPublishAdapter;
use App\Services\Marketplace\Publishing\EbayPublishAdapter;
use App\Services\Marketplace\Publishing\MarketplacePublishCommand;
use App\Services\Marketplace\Publishing\OvokoPublishAdapter;
use Illuminate\Support\Facades\DB;

class PublishPartToMarketplacesService
{
    public const CHANNELS = ['allegro', 'ovoko', 'ebay'];

    public function __construct(
        private readonly PreparePartMarketplaceListingService $prepareService,
        private readonly AllegroPublishAdapter $allegro,
        private readonly OvokoPublishAdapter $ovoko,
        private readonly EbayPublishAdapter $ebay,
    ) {}

    public function preview(Part $part, array|string $channels = 'all', bool $includePayload = true): array
    {
        $selected = $this->normalizeChannels($channels);
        $results = [];
        foreach ($selected as $channel) $results[$channel] = $this->adapter($channel)->preview($part)->data;
        return $this->responseSkeleton(true, false) + ['part_id' => $part->id, 'channels' => $results, 'readiness_ok' => $this->allSuccessful($results), 'include_payload' => $includePayload];
    }

    public function confirm(Part $part, array|string $channels, bool $dryRun, bool $confirm): array
    {
        $enabled = (bool) config('marketplace.publish_enabled', false);
        $selected = $this->normalizeChannels($channels);
        $base = $this->responseSkeleton($dryRun, $confirm);
        if ($dryRun || ! $confirm || ! $enabled) return $base + ['part_id' => $part->id, 'blocked' => true, 'blockers' => ['marketplace_publish_disabled_or_not_confirmed'], 'channels' => [], 'readiness_ok' => false];

        $preview = $this->preview($part, $selected);
        if (! $preview['readiness_ok']) return $base + ['part_id' => $part->id, 'blocked' => true, 'blockers' => ['readiness_failed'], 'channels' => $preview['channels'], 'readiness_ok' => false];

        $command = new MarketplacePublishCommand($dryRun, $confirm, $selected, $enabled);
        $results = [];
        DB::transaction(function () use ($part, $selected, $command, &$results): void {
            foreach ($selected as $channel) $results[$channel] = $this->adapter($channel)->publish($part, $command)->data;
            if ($this->allSuccessful($results)) $this->prepareService->markLocallyListed($part);
        });

        $success = $this->allSuccessful($results);
        return array_merge($base, ['part_id' => $part->id, 'blocked' => ! $success, 'channels' => $results, 'readiness_ok' => true, 'needs_listing_changed' => $success, 'products_changed' => $success, 'offers_changed' => collect($results)->contains(fn ($r) => (bool) ($r['write'] ?? false)), 'marketplace_write' => collect($results)->contains(fn ($r) => (bool) ($r['write'] ?? false)), 'allegro_write' => (bool) ($results['allegro']['write'] ?? false), 'ovoko_write' => (bool) ($results['ovoko']['write'] ?? false), 'ebay_write' => (bool) ($results['ebay']['write'] ?? false)]);
    }

    public function normalizeChannels(array|string $channels): array
    {
        $value = is_array($channels) ? implode(',', $channels) : $channels;
        if ($value === 'all' || blank($value)) return self::CHANNELS;
        return array_values(array_intersect(self::CHANNELS, array_map('trim', explode(',', $value))));
    }

    private function adapter(string $channel): object { return match ($channel) { 'allegro' => $this->allegro, 'ovoko' => $this->ovoko, 'ebay' => $this->ebay }; }
    private function allSuccessful(array $results): bool { return $results !== [] && collect($results)->every(fn ($r) => (bool) ($r['success'] ?? false)); }
    private function responseSkeleton(bool $dryRun, bool $confirm): array { return ['dry_run' => $dryRun, 'confirm' => $confirm, 'marketplace_publish_enabled' => (bool) config('marketplace.publish_enabled', false), 'marketplace_write' => false, 'allegro_write' => false, 'ovoko_write' => false, 'ebay_write' => false, 'products_changed' => false, 'offers_changed' => false, 'stock_changed' => false, 'prices_changed' => false, 'images_changed' => false, 'needs_listing_changed' => false]; }
}
