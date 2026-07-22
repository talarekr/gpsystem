<?php

namespace App\Console\Commands;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\PriceSync\PriceNormalizer;
use Illuminate\Console\Command;

class RepairPart8212OvokoPriceSyncLog extends Command
{
    protected $signature = 'marketplace:repair-part-8212-ovoko-price-log {--apply} {--confirm=}';
    protected $description = 'Dry-run/apply local-only repair for the existing part #8212 Ovoko price sync log; no external requests and no marketplace writes.';

    public function handle(PriceNormalizer $normalizer): int
    {
        $token = 'repair-8212-ovoko-140-pln';
        $log = MarketplaceSyncLog::query()->where('part_id', 8212)->where('marketplace', 'ovoko')->where('action', 'part_price_sync')->latest('id')->first();
        $listing = MarketplaceListing::query()->find(23107);
        $payload = (array) ($log?->payload ?? []);
        $read = (array) data_get($payload, 'read_after_write', []);
        $row = (array) data_get($read, 'list.0.0', []);
        $confirmed = strtoupper((string)($row['original_currency'] ?? '')) === 'PLN' ? $normalizer->normalize($row['original_price'] ?? null) : null;
        $ok = $log && $listing && $confirmed === '140.00';
        $preview = ['dry_run'=>!$this->option('apply'),'external_requests'=>false,'marketplace_write'=>false,'log_id'=>$log?->id,'listing_id'=>$listing?->id,'part_id'=>8212,'checks'=>['has_log'=>(bool)$log,'has_listing'=>(bool)$listing,'original_price'=>$row['original_price'] ?? null,'original_currency'=>$row['original_currency'] ?? null,'confirmed_price'=>$confirmed,'can_apply'=>$ok],'planned_updates'=>$ok ? ['marketplace_sync_logs.status'=>'success','payload.final_success'=>true,'payload.remote_confirmed_price'=>'140.00','payload.confirmed_remote_price'=>'140.00','marketplace_listings.23107.price'=>'140.00','marketplace_listings.23107.currency'=>'PLN'] : []];
        $this->line(json_encode($preview, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        if (! $this->option('apply')) return self::SUCCESS;
        if ($this->option('confirm') !== $token || ! $ok) { $this->error('Apply blocked. Required --confirm='.$token.' and valid sanitized read_after_write.'); return self::FAILURE; }
        $payload['final_success'] = true; $payload['remote_confirmed_price'] = '140.00'; $payload['confirmed_remote_price'] = '140.00'; $payload['blocker'] = null;
        $log->forceFill(['status'=>'success','message'=>'Local repair from saved Ovoko read_after_write; no external request.','payload'=>$payload])->save();
        $listing->forceFill(['price'=>'140.00','currency'=>'PLN'])->save();
        $this->info('Applied local-only repair.');
        return self::SUCCESS;
    }
}
