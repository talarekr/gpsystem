<?php

namespace App\Console\Commands;

use App\Services\Marketplace\ResetLocalOrdersService;
use Illuminate\Console\Command;

class ResetLocalOrders extends Command
{
    protected $signature = 'marketplace:reset-local-orders {--dry-run : Preview only; default mode} {--apply : Delete local orders} {--confirm= : Required confirmation token for apply}';
    protected $description = 'Safely reset local test orders and direct order dependencies without touching marketplace logs/listings/parts/stock.';

    public function handle(ResetLocalOrdersService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('confirm') !== 'reset-local-orders') {
            $this->error('Apply requires --confirm=reset-local-orders. No data changed.');
            return self::FAILURE;
        }

        $result = $service->run($apply);

        $this->table(['table/action', 'records'], collect($result['summary'])->map(fn ($v, $k) => [$k, $v])->all());
        $this->line($apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (default). No data changed.');
        $this->line('Order items are deleted directly; shipments are detached because shipments.order_id uses nullOnDelete; MarketplaceSyncLog is not deleted.');

        if ($apply) {
            $this->info($result['message']);
        }

        return self::SUCCESS;
    }
}
