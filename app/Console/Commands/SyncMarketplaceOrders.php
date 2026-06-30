<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceOrdersImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncMarketplaceOrders extends Command
{
    protected $signature = 'marketplace:sync-orders {--channels=allegro,ebay_de,ebay_fr : Comma-separated channels} {--since= : Since timestamp in Europe/Warsaw} {--dry-run : Preview only; default mode} {--apply : Persist local orders} {--confirm= : Required confirmation token for apply} {--limit=50 : Max orders per channel request}';
    protected $description = 'Manually import/sync read-only Allegro and eBay orders into local orders.';

    public function handle(MarketplaceOrdersImportService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('confirm') !== 'sync-orders') {
            $this->error('Apply requires --confirm=sync-orders. No data changed.');
            return self::FAILURE;
        }

        $since = (string) ($this->option('since') ?: '2026-06-29 00:00:00');
        try {
            Carbon::parse($since, 'Europe/Warsaw');
        } catch (\Throwable) {
            $this->error('Invalid --since timestamp. Use e.g. "2026-06-29 00:00:00".');
            return self::FAILURE;
        }

        $summary = $service->run([
            'channels' => (string) $this->option('channels'),
            'since' => $since,
            'limit' => (int) $this->option('limit'),
            'dry_run' => ! $apply,
            'live_import' => true,
        ]);

        $this->table(['metric', 'value'], collect($summary)->only(['dry_run','date_from','orders_fetched','orders_created','orders_updated','orders_skipped','items_created','items_updated'])->map(fn ($v, $k) => [$k, is_scalar($v) ? $v : json_encode($v)])->all());
        foreach (($summary['marketplaces'] ?? []) as $channel => $data) {
            $this->line($channel.': fetched='.(int) ($data['orders_fetched'] ?? 0).', errors='.count($data['errors'] ?? []).', warnings='.count($data['warnings'] ?? []));
        }
        if (($summary['errors'] ?? []) !== []) {
            $this->warn('Technical errors were logged in MarketplaceSyncLog / Administracja marketplace → Logi.');
        }

        return ($summary['errors'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}
