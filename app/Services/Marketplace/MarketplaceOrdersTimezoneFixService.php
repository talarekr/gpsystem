<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class MarketplaceOrdersTimezoneFixService
{
    public const CONFIRM = 'orders-timezone-fix';

    public function __construct(private readonly MarketplaceOrderTimeService $timeService) {}

    public function run(array $options): array
    {
        $channels = $this->channels((string) ($options['channels'] ?? 'allegro,ebay'));
        $since = Carbon::parse((string) ($options['since'] ?? '2026-06-29 00:00:00'), MarketplaceOrderTimeService::LOCAL_TIMEZONE)->format('Y-m-d H:i:s');
        $apply = (bool) ($options['apply'] ?? false);
        $confirmed = hash_equals(self::CONFIRM, (string) ($options['confirm'] ?? ''));
        $dryRun = ! ($apply && $confirmed);

        $query = Order::query()
            ->whereIn('marketplace', $channels);

        $summary = [
            'ok' => true,
            'dry_run' => $dryRun,
            'apply_requested' => $apply,
            'confirm_ok' => $confirmed,
            'timezone' => MarketplaceOrderTimeService::LOCAL_TIMEZONE,
            'channels' => $channels,
            'since' => $since,
            'orders_checked' => 0,
            'orders_would_update' => 0,
            'orders_updated' => 0,
            'sample' => [],
            'diagnostics' => [],
            'safety_flags' => [
                'read_only' => $dryRun,
                'orders_changed' => false,
                'order_items_changed' => false,
                'stock_changed' => false,
                'listings_changed' => false,
                'prices_changed' => false,
                'shipments_changed' => false,
                'marketplace_write' => false,
                'allegro_write' => false,
                'ebay_write' => false,
            ],
        ];

        $query->chunkById(100, function ($orders) use (&$summary, $dryRun): void {
            foreach ($orders as $order) {
                $diagnostic = $this->diagnostic($order);
                if (! $this->inScope($diagnostic, $since)) {
                    continue;
                }

                $summary['orders_checked']++;
                if ($diagnostic['would_update']) {
                    $summary['orders_would_update']++;
                }

                if (count($summary['sample']) < 10) {
                    $summary['sample'][] = Arr::only($diagnostic, ['order_id','marketplace','marketplace_order_id','current_ordered_at','source_utc_ordered_at','corrected_ordered_at_local','correction_method','would_update','already_corrected']);
                }
                if (count($summary['diagnostics']) < 100) {
                    $summary['diagnostics'][] = $diagnostic;
                }

                if (! $dryRun && $diagnostic['would_update'] && in_array($diagnostic['correction_method'], ['metadata_utc'], true)) {
                    $meta = is_array($order->meta) ? $order->meta : [];
                    $meta['orders_timezone_fix'] = [
                        'corrected_at' => now()->toISOString(),
                        'from' => $diagnostic['current_ordered_at'],
                        'to' => $diagnostic['corrected_ordered_at_local'],
                        'source_utc_ordered_at' => $diagnostic['source_utc_ordered_at'],
                    ];
                    $order->forceFill(['ordered_at' => $diagnostic['corrected_ordered_at_local'], 'meta' => $meta])->save();
                    $summary['orders_updated']++;
                }
            }
        });

        $summary['safety_flags']['orders_changed'] = $summary['orders_updated'] > 0;
        MarketplaceSyncLog::query()->create([
            'marketplace' => 'marketplace',
            'action' => 'orders_timezone_fix',
            'status' => $summary['orders_updated'] > 0 ? 'success' : 'dry_run',
            'message' => $dryRun ? 'Dry-run local marketplace order timezone correction.' : 'Applied local marketplace order timezone correction.',
            'payload' => Arr::except($summary, ['diagnostics']),
            'created_at' => now(),
        ]);

        return $summary;
    }

    private function diagnostic(Order $order): array
    {
        $sourceUtc = $this->sourceUtcOrderedAt($order);
        $corrected = $this->timeService->marketplaceUtcToLocalStorage($sourceUtc);
        $current = $this->timeService->localDisplay($order->ordered_at);
        $alreadyCorrected = filled(data_get($order->meta, 'orders_timezone_fix.corrected_at')) || ($corrected !== null && $current === $corrected);
        $wouldUpdate = $corrected !== null && ! $alreadyCorrected && $current !== $corrected;

        return [
            'order_id' => $order->id,
            'marketplace' => $order->marketplace,
            'marketplace_order_id' => $order->marketplace_order_id,
            'current_ordered_at' => $current,
            'source_utc_ordered_at' => $sourceUtc === null ? null : $this->timeService->marketplaceUtcIso($sourceUtc),
            'corrected_ordered_at_local' => $corrected,
            'correction_method' => $sourceUtc === null ? 'manual_offset' : 'metadata_utc',
            'would_update' => $wouldUpdate,
            'already_corrected' => $alreadyCorrected,
        ];
    }

    private function inScope(array $diagnostic, string $since): bool
    {
        $basis = $diagnostic['corrected_ordered_at_local'] ?? $diagnostic['current_ordered_at'] ?? null;

        return is_string($basis) && $basis >= $since;
    }

    private function sourceUtcOrderedAt(Order $order): ?string
    {
        $raw = is_array($order->raw_payload) ? $order->raw_payload : [];
        $keys = $order->marketplace === 'allegro'
            ? ['boughtAt', 'orderedAt', 'purchasedAt', 'createdAt', 'creationDate', 'created_at', 'checkoutCompletedAt']
            : ['boughtAt', 'orderedAt', 'purchasedAt', 'creationDate', 'createdAt', 'created_at'];

        foreach ($keys as $key) {
            if ($this->timeService->parseMarketplaceUtc($raw[$key] ?? null)) {
                return $raw[$key];
            }
        }

        if ($order->marketplace === 'allegro') {
            $items = array_values(array_filter($raw['lineItems'] ?? [], 'is_array'));
            $dates = [];
            foreach ($items as $item) {
                if ($this->timeService->parseMarketplaceUtc($item['boughtAt'] ?? null)) {
                    $dates[] = $item['boughtAt'];
                }
            }
            sort($dates);
            return $dates[0] ?? null;
        }

        return null;
    }

    private function channels(string $value): array
    {
        $channels = array_map('trim', explode(',', strtolower($value)));
        return array_values(array_intersect($channels, ['allegro', 'ebay']));
    }
}
