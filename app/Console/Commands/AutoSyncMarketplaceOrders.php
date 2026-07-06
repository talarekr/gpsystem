<?php

namespace App\Console\Commands;

use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\MarketplaceOrderTimeService;
use App\Services\Marketplace\MarketplaceOrdersImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AutoSyncMarketplaceOrders extends Command
{
    protected $signature = 'marketplace:auto-sync-orders {--dry-run : Preview only without persisting local orders} {--limit=50 : Max orders per channel request}';
    protected $description = 'Scheduled read-only marketplace order sync into local orders.';

    public function handle(MarketplaceOrdersImportService $service, ApiIntegrationLogger $logger): int
    {
        $startedAt = now(MarketplaceOrderTimeService::LOCAL_TIMEZONE);
        $enabled = (bool) config('marketplace_order_sync.enabled', false);
        $channels = $this->configuredChannels();
        $lookbackDays = max(1, (int) config('marketplace_order_sync.lookback_days', 3));
        $dateFrom = $startedAt->copy()->subDays($lookbackDays)->format('Y-m-d H:i:s');
        $dryRun = (bool) $this->option('dry-run');

        if (! $enabled && ! $dryRun) {
            $this->info('Marketplace order auto-sync is disabled. Set GPS_MARKETPLACE_ORDER_SYNC_ENABLED=true to enable it.');
            return self::SUCCESS;
        }

        $summary = $service->run([
            'channels' => implode(',', $channels),
            'since' => $dateFrom,
            'date_from' => $dateFrom,
            'date_to' => $startedAt->format('Y-m-d H:i:s'),
            'limit' => (int) $this->option('limit'),
            'dry_run' => $dryRun,
            'live_import' => ! $dryRun,
            'trigger' => 'scheduler',
        ]);

        $finishedAt = now(MarketplaceOrderTimeService::LOCAL_TIMEZONE);
        $logSummary = [
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'finished_at' => $finishedAt->format('Y-m-d H:i:s'),
            'channels' => $channels,
            'date_from' => $dateFrom,
            'orders_fetched' => (int) ($summary['orders_fetched'] ?? 0),
            'orders_created' => (int) ($summary['orders_created'] ?? 0),
            'orders_updated' => (int) ($summary['orders_updated'] ?? 0),
            'orders_skipped' => (int) ($summary['orders_skipped'] ?? 0),
            'errors' => $summary['errors'] ?? [],
            'timezone' => MarketplaceOrderTimeService::LOCAL_TIMEZONE,
            'safety_flags' => $this->safetyFlags($dryRun),
            'dry_run' => $dryRun,
        ];

        $logger->record([
            'integration' => 'marketplace_orders',
            'action' => 'scheduled_order_sync',
            'status' => ($summary['errors'] ?? []) === [] ? 'success' : 'error',
            'message' => 'Scheduled read-only marketplace order sync summary.',
            'request' => ['channels' => $channels, 'date_from' => $dateFrom, 'date_to' => $startedAt->format('Y-m-d H:i:s'), 'limit' => (int) $this->option('limit')],
            'response' => $logSummary,
        ]);

        $this->table(['metric', 'value'], collect($logSummary)->map(fn ($v, $k) => [$k, is_scalar($v) ? (string) $v : json_encode($v)])->all());

        return ($summary['errors'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }

    private function configuredChannels(): array
    {
        $channels = is_array(config('marketplace_order_sync.channels'))
            ? config('marketplace_order_sync.channels')
            : explode(',', (string) config('marketplace_order_sync.channels', 'allegro,ebay'));

        return collect($channels)
            ->map(fn ($channel): string => Str::of((string) $channel)->trim()->lower()->replace('-', '_')->toString())
            ->map(fn (string $channel): string => match ($channel) {
                'ebay_de', 'ebay_fr', 'ebay_pl' => 'ebay',
                'ovoko_main', 'ovoko_com', 'ovoko_marketplace', 'rrr' => 'ovoko',
                'allegro_main' => 'allegro',
                default => $channel,
            })
            ->intersect(['allegro', 'ebay', 'ovoko'])
            ->unique()
            ->values()
            ->all();
    }

    private function safetyFlags(bool $dryRun): array
    {
        return [
            'read_only_marketplace_api' => true,
            'no_stock_sync' => true,
            'no_listings_sync' => true,
            'no_price_sync' => true,
            'no_shipment_sync' => true,
            'no_marketplace_write' => true,
            'orders_changed' => ! $dryRun,
        ];
    }
}
