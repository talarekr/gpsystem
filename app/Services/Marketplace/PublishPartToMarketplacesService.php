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
        private readonly MarketplacePublishGate $publishGate,
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
        $selected = $this->normalizeChannels($channels);
        // This is the live path used by the parts panel.  Keep the local preview
        // available, but fail an actual eBay apply before readiness, a database
        // transaction, or an adapter can perform any work.
        if (! $dryRun && $confirm && in_array('ebay', $selected, true)) {
            app(EbayConnectionGate::class)->assertEbayEnabledForWrite('parts_panel_publish');
        }
        $enabled = collect($selected)->every(fn (string $channel): bool => $this->publishGate->allows($channel));
        $base = $this->responseSkeleton($dryRun, $confirm);
        if ($dryRun || ! $confirm || ! $enabled) return $base + ['part_id' => $part->id, 'blocked' => true, 'blockers' => $dryRun || ! $confirm ? ['marketplace_publish_not_confirmed'] : $this->blockingFlags($selected), 'channels' => [], 'publish_gates' => $this->publishGates($selected), 'readiness_ok' => false];

        $preview = $this->preview($part, $selected);
        $ready = array_keys(array_filter($preview['channels'], fn (array $result): bool => (bool) ($result['success'] ?? false)));
        $skipped = array_diff_key($preview['channels'], array_flip($ready));

        if ($ready === []) {
            return $base + ['part_id' => $part->id, 'blocked' => true, 'blockers' => ['readiness_failed'], 'channels' => $preview['channels'], 'ready_channels' => [], 'skipped_channels' => $this->skippedChannels($skipped), 'readiness_ok' => false];
        }

        $command = new MarketplacePublishCommand($dryRun, $confirm, $ready, $enabled);
        $results = [];
        DB::transaction(function () use ($part, $ready, $command, &$results, $skipped): void {
            foreach ($ready as $channel) $results[$channel] = $this->adapter($channel)->publish($part, $command)->data;
            foreach ($skipped as $channel => $data) $results[$channel] = $this->skippedResult($channel, $data);
            if ($this->allSelectedSuccessful($results, $ready) && $skipped === []) $this->prepareService->markLocallyListed($part);
        });

        $published = array_values(array_filter($ready, fn (string $channel): bool => (bool) ($results[$channel]['success'] ?? false)));
        $success = $published !== [];
        return array_merge($base, ['part_id' => $part->id, 'blocked' => ! $success, 'channels' => $results, 'ready_channels' => $ready, 'published_channels' => $published, 'skipped_channels' => $this->skippedChannels($skipped), 'readiness_ok' => $skipped === [], 'needs_listing_changed' => $success && $skipped === [], 'products_changed' => $success && $skipped === [], 'offers_changed' => collect($results)->contains(fn ($r) => (bool) ($r['write'] ?? false)), 'marketplace_write' => collect($results)->contains(fn ($r) => (bool) ($r['write'] ?? false)), 'allegro_write' => (bool) ($results['allegro']['write'] ?? false), 'ovoko_write' => (bool) ($results['ovoko']['write'] ?? false), 'ebay_write' => (bool) ($results['ebay']['write'] ?? false)]);
    }

    public function normalizeChannels(array|string $channels): array
    {
        $value = is_array($channels) ? implode(',', $channels) : $channels;
        if ($value === 'all' || blank($value)) return self::CHANNELS;
        return array_values(array_intersect(self::CHANNELS, array_map('trim', explode(',', $value))));
    }

    private function adapter(string $channel): object { return match ($channel) { 'allegro' => $this->allegro, 'ovoko' => $this->ovoko, 'ebay' => $this->ebay }; }
    private function allSuccessful(array $results): bool { return $results !== [] && collect($results)->every(fn ($r) => (bool) ($r['success'] ?? false)); }
    private function allSelectedSuccessful(array $results, array $selected): bool { return $selected !== [] && collect($selected)->every(fn (string $channel): bool => (bool) ($results[$channel]['success'] ?? false)); }
    private function skippedChannels(array $skipped): array { return collect($skipped)->map(fn (array $data): array => ['status' => 'blocked', 'reasons' => $data['errors'] ?? $data['readiness']['blockers'] ?? []])->all(); }
    private function skippedResult(string $channel, array $data): array { return ['channel' => $data['channel'] ?? $channel, 'marketplace' => $data['marketplace'] ?? $channel, 'success' => false, 'blocked' => true, 'status' => 'skipped_blocked_readiness', 'errors' => $data['errors'] ?? $data['readiness']['blockers'] ?? [], 'warnings' => $data['warnings'] ?? [], 'write' => false, 'readiness' => $data['readiness'] ?? []]; }
    private function publishGates(array $channels): array { return collect($channels)->mapWithKeys(fn (string $channel): array => [$channel => $this->publishGate->decision($channel)])->all(); }
    private function blockingFlags(array $channels): array { return collect($this->publishGates($channels))->flatMap(fn (array $gate): array => $gate['blocking_flags'] ?? [])->unique()->values()->all(); }
    private function responseSkeleton(bool $dryRun, bool $confirm): array { return ['dry_run' => $dryRun, 'confirm' => $confirm, 'marketplace_publish_enabled' => (bool) config('marketplace.publish_enabled', false), 'marketplace_write' => false, 'allegro_write' => false, 'ovoko_write' => false, 'ebay_write' => false, 'products_changed' => false, 'offers_changed' => false, 'stock_changed' => false, 'prices_changed' => false, 'images_changed' => false, 'needs_listing_changed' => false]; }
}
