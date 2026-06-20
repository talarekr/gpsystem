<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use Illuminate\Console\Command;

class ImportOvokoManualMapping extends Command
{
    protected $signature = 'marketplace:import-ovoko-manual-mapping {--file= : Path to filled ovoko unmapped CSV} {--dry-run : Preview without writing}';
    protected $description = 'Import manually filled Ovoko part mappings from CSV.';

    public function handle(): int
    {
        $file = (string) $this->option('file');
        $dryRun = (bool) $this->option('dry-run');

        if ($file === '' || ! is_file($file) || ! is_readable($file)) {
            $this->error('Readable --file path is required.');
            return self::FAILURE;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            $this->error('Cannot open CSV file.');
            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            $this->error('CSV header is missing.');
            fclose($handle);
            return self::FAILURE;
        }

        $indexes = array_flip($header);
        foreach (['part_id', 'new_ovoko_part_id'] as $requiredColumn) {
            if (! array_key_exists($requiredColumn, $indexes)) {
                $this->error('Missing required CSV column: '.$requiredColumn);
                fclose($handle);
                return self::FAILURE;
            }
        }

        $summary = ['dry_run' => $dryRun, 'rows' => 0, 'filled' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'conflicts' => 0, 'warnings' => []];
        $account = $dryRun ? null : MarketplaceAccount::query()->firstOrCreate(
            ['code' => 'ovoko_main'],
            ['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'status' => 'active']
        );

        while (($row = fgetcsv($handle)) !== false) {
            $summary['rows']++;
            $partId = trim((string) ($row[$indexes['part_id']] ?? ''));
            $ovokoId = trim((string) ($row[$indexes['new_ovoko_part_id']] ?? ''));

            if ($partId === '' || $ovokoId === '') {
                $summary['skipped']++;
                continue;
            }
            $summary['filled']++;

            $existingByOffer = MarketplaceListing::query()->where('marketplace', 'ovoko')->where('external_offer_id', $ovokoId)->first();
            if ($existingByOffer && $existingByOffer->part_id !== null && (string) $existingByOffer->part_id !== $partId) {
                $summary['conflicts']++;
                $summary['warnings'][] = "Ovoko ID {$ovokoId} is already mapped to part {$existingByOffer->part_id}; CSV row requested part {$partId}.";
                continue;
            }

            $existingCertainForPart = MarketplaceListing::query()
                ->where('marketplace', 'ovoko')
                ->where('part_id', $partId)
                ->where('external_offer_id', '!=', $ovokoId)
                ->whereIn('match_status', ['confirmed', 'manual_matched'])
                ->first();

            if ($existingCertainForPart) {
                $summary['conflicts']++;
                $summary['warnings'][] = "Part {$partId} already has certain Ovoko mapping {$existingCertainForPart->external_offer_id}; CSV requested {$ovokoId}.";
                continue;
            }

            if ($existingByOffer) {
                $summary['updated']++;
            } else {
                $summary['created']++;
            }

            if ($dryRun) {
                continue;
            }

            $listing = MarketplaceListing::query()->updateOrCreate(
                ['marketplace' => 'ovoko', 'external_offer_id' => $ovokoId],
                [
                    'marketplace_account_id' => $account?->id,
                    'part_id' => (int) $partId,
                    'sync_status' => 'mapped',
                    'match_status' => 'manual_matched',
                    'match_confidence' => 100,
                    'match_reason' => 'manual_csv_import',
                    'raw_payload' => ['source' => 'manual_csv_import', 'file' => $file],
                    'last_error' => null,
                    'last_synced_at' => now(),
                ]
            );

            MarketplaceSyncLog::query()->create([
                'marketplace' => 'ovoko',
                'marketplace_listing_id' => $listing->id,
                'part_id' => $listing->part_id,
                'action' => 'import_ovoko_manual_mapping',
                'status' => 'success',
                'message' => 'manual_csv_import',
                'payload' => ['external_offer_id' => $ovokoId, 'part_id' => (int) $partId, 'file' => $file],
                'created_at' => now(),
            ]);
        }

        fclose($handle);
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $summary['conflicts'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
