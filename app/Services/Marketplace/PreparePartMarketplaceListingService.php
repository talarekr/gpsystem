<?php

namespace App\Services\Marketplace;

use App\Models\Part;

class PreparePartMarketplaceListingService
{
    public const MARKETPLACE_CHANNELS = ['allegro_main', 'ebay_de', 'ebay_fr', 'ovoko'];

    public function __construct(private readonly MarketplaceListingReadinessService $readinessService) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Part $part, bool $dryRun = true): array
    {
        $part->refresh();

        $storefront = $this->readinessService->checkPartReadiness($part, 'storefront');
        $channels = [];

        foreach (self::MARKETPLACE_CHANNELS as $channel) {
            $channels[$channel] = $this->readinessService->checkPartReadiness($part, $channel);
        }

        return [
            'dry_run' => $dryRun,
            'will_make_marketplace_request' => false,
            'storefront' => $storefront,
            'channels' => $channels,
            'summary' => [
                'storefront_ready' => (bool) ($storefront['can_prepare'] ?? false),
                'ready_channels' => array_keys(array_filter($channels, fn (array $result): bool => (bool) ($result['can_prepare'] ?? false))),
                'blocked_channels' => array_keys(array_filter($channels, fn (array $result): bool => ($result['blockers'] ?? []) !== [])),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function localPublishBlockers(Part $part): array
    {
        $preview = $this->preview($part);

        return array_values(array_unique(array_merge(
            (array) ($preview['storefront']['missing_fields'] ?? []),
            (array) ($preview['storefront']['blockers'] ?? []),
        )));
    }

    public function markLocallyListed(Part $part): Part
    {
        $part->forceFill([
            'status' => 'ready',
            'is_visible_storefront' => true,
            'needs_listing' => false,
        ])->save();

        return $part->refresh();
    }
}
