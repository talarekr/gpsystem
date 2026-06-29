<?php

namespace App\Console\Commands;

use App\Services\Marketplace\OvokoListingUrlBackfillService;
use Illuminate\Console\Command;

class BackfillOvokoListingUrls extends Command
{
    protected $signature = 'marketplace:backfill-ovoko-listing-urls
        {--dry-run : Preview only; no database writes (default unless --apply is used)}
        {--apply : Persist resolved URLs locally}
        {--part-id= : Restrict to one local part ID}
        {--limit=100 : Maximum listings to inspect}
        {--force : Overwrite existing marketplace_listings.url}
        {--csv= : CSV with local_part_id, ovoko_part_id, shop_url and optional external_id}';

    protected $description = 'Safely backfill local Ovoko listing URLs from local data, read-only Ovoko API, or CSV.';

    public function handle(OvokoListingUrlBackfillService $backfill): int
    {
        try {
            $result = $backfill->run(
                apply: (bool) $this->option('apply'),
                force: (bool) $this->option('force'),
                partId: $this->option('part-id') !== null ? (int) $this->option('part-id') : null,
                limit: max(1, (int) $this->option('limit')),
                csvPath: $this->option('csv') ? (string) $this->option('csv') : null,
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->table(
            ['local_part_id', 'marketplace_listing_id', 'existing_ovoko_id', 'existing_url', 'resolved_shop_url', 'source', 'action'],
            $result['results'],
        );
        $this->line(json_encode(['mode' => $result['mode'], 'summary' => $result['summary']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
