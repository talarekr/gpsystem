<?php

namespace App\Console\Commands;

use App\Services\Marketplace\PurgeMarketplaceOrdersService;
use Illuminate\Console\Command;
use Throwable;

class PurgeMarketplaceOrders extends Command
{
    protected $signature = 'marketplace:orders:purge
        {--marketplaces=allegro,ebay,ovoko : Comma-separated marketplaces limited to allegro, ebay, ovoko}
        {--dry-run : Preview only; default mode}
        {--apply : Execute purge after backup export}
        {--confirm= : Required confirmation token for apply}
        {--only-test-import : Limit purge to orders with test_import=1 when the column exists}';

    protected $description = 'Safely export and purge imported marketplace orders for Allegro, eBay and Ovoko.';

    public function handle(PurgeMarketplaceOrdersService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('confirm') !== 'purge-marketplace-orders') {
            $this->error('Apply requires --confirm=purge-marketplace-orders. No data changed.');
            return self::FAILURE;
        }

        $marketplaces = explode(',', (string) $this->option('marketplaces'));

        try {
            $result = $service->run($marketplaces, $apply, (bool) $this->option('only-test-import'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->line($apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (default). No data changed.');
        $this->line('Marketplaces: '.implode(', ', $result['marketplaces']));
        $this->line('Backup/export: '.$result['export_path']);
        $this->table(['table/action', 'records'], collect($result['summary'])->map(fn ($v, $k) => [$k, $v])->all());

        foreach ($result['notes'] as $note) {
            $this->line('- '.$note);
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
