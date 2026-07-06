<?php

namespace App\Console\Commands;

use App\Services\Marketplace\OvokoListingUrlBackfillService;
use Illuminate\Console\Command;

class BackfillOvokoLinks extends Command
{
    protected $signature = 'marketplace:backfill-ovoko-links
        {--dry-run : Preview only; no database writes (default unless --apply is used)}
        {--apply : Persist resolved URLs and missing part Ovoko prices locally}
        {--part-id= : Restrict to one local part ID}
        {--listing-id= : Restrict to one marketplace_listings ID}
        {--limit=100 : Maximum listings to inspect}
        {--force : Overwrite existing marketplace_listings.url}
        {--csv= : CSV with local_part_id, ovoko_part_id, shop_url and optional external_id}';

    protected $description = 'Backfill missing local Ovoko links and part Ovoko prices from existing Ovoko marketplace listings.';

    public function handle(OvokoListingUrlBackfillService $backfill): int
    {
        try {
            $result = $backfill->run(
                apply: (bool) $this->option('apply'),
                force: (bool) $this->option('force'),
                partId: $this->option('part-id') !== null ? (int) $this->option('part-id') : null,
                limit: max(1, (int) $this->option('limit')),
                csvPath: $this->option('csv') ? (string) $this->option('csv') : null,
                listingId: $this->option('listing-id') !== null ? (int) $this->option('listing-id') : null,
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->table(
            ['local_part_id', 'marketplace_listing_id', 'existing_ovoko_id', 'existing_url', 'resolved_shop_url', 'source', 'action', 'price_backfill_action', 'listing_price', 'part_ovoko_price'],
            collect($result['results'])->map(fn (array $row): array => collect($row)->only(['local_part_id', 'marketplace_listing_id', 'existing_ovoko_id', 'existing_url', 'resolved_shop_url', 'source', 'action', 'price_backfill_action', 'listing_price', 'part_ovoko_price'])->all())->all(),
        );
        $this->line(json_encode(['mode' => $result['mode'], 'summary' => $result['summary']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
