<?php

namespace App\Services\Tools;

use App\Filament\Resources\PartResource;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\StorageLocation;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PartsToListStorageLocationBackfillService
{
    public const CSV_PATH = 'imports/gps-gmail-import-audit-full-20260611-102746.csv';
    public const CONFIRM = 'parts-to-list-storage-backfill';

    public function dryRun(int $limit = 100): array
    {
        $csv = $this->readCsv();
        $analysis = $this->analyse($csv, $limit);
        $this->log('dry_run', 'success', $analysis['metrics'], $analysis);

        return $analysis + ['csv_path' => 'storage/app/'.self::CSV_PATH, 'dry_run' => true, 'local_update' => false, 'marketplace_write' => false];
    }

    public function apply(int $limit = 10, bool $confirmed = false): array
    {
        if (! $confirmed) {
            return ['ok' => false, 'message' => 'Missing confirm='.self::CONFIRM, 'dry_run' => false, 'local_update' => false, 'marketplace_write' => false];
        }

        $analysis = $this->analyse($this->readCsv(), $limit);
        $updated = [];
        $failed = 0;
        $skippedAlready = 0;

        foreach (array_slice($analysis['would_update'], 0, $limit) as $row) {
            try {
                $part = Part::query()->with('storageLocation')->whereKey($row['local_part_id'])->where('needs_listing', true)->first();

                if (! $part) {
                    $failed++;
                    continue;
                }

                if (filled($part->storageLocation?->name)) {
                    $skippedAlready++;
                    continue;
                }

                $location = $this->findOrCreateLocation($row['new_storage_location']);
                $part->forceFill(['storage_location_id' => $location->id])->save();
                $updated[] = $row + ['storage_location_id' => $location->id];
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $result = [
            'ok' => true,
            'dry_run' => false,
            'local_update' => true,
            'marketplace_write' => false,
            'attempted_count' => min($limit, $analysis['metrics']['would_update_count']),
            'updated_count' => count($updated),
            'skipped_already_has_storage_count' => $skippedAlready,
            'skipped_no_match_count' => $analysis['metrics']['no_match_count'],
            'skipped_ambiguous_count' => $analysis['metrics']['ambiguous_count'],
            'failed_count' => $failed,
            'updated' => $updated,
            'metrics' => $analysis['metrics'],
        ];

        $this->log('apply', $failed > 0 ? 'partial' : 'success', ['updated_count' => count($updated), 'failed_count' => $failed], $result);

        return $result;
    }

    public function latestResults(int $limit = 20): array
    {
        return ['logs' => MarketplaceSyncLog::query()->where('marketplace', 'local')->where('action', 'parts_to_list_storage_location_backfill')->latest('created_at')->limit($limit)->get(['id','status','message','payload','created_at'])];
    }

    public function normalizeStorageLocation(?string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value);
        do {
            $previous = $value;
            $value = preg_replace('/^(?:fwd?|fw|re)\s*:\s*/iu', '', $value) ?? $value;
            $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        } while ($value !== $previous);

        return $value;
    }

    private function analyse(array $csvRows, int $limit): array
    {
        $csvById = collect($csvRows)->groupBy('staging_item_id');
        $metrics = ['total_csv_rows' => count($csvRows), 'csv_rows_with_storage_raw' => 0, 'csv_rows_with_storage_normalized' => 0, 'target_parts_to_list_total' => PartResource::adminPartsToListQuery()->count(), 'target_parts_to_list_missing_storage' => 0, 'parts_to_list_with_gps_gmail_number' => 0, 'matched_by_gps_gmail_staging_id_count' => 0, 'would_update_count' => 0, 'already_has_storage_count' => 0, 'no_match_count' => 0, 'ambiguous_count' => 0, 'skipped_empty_storage_count' => 0, 'skipped_non_gps_gmail_number_count' => 0, 'failed_count' => 0];
        foreach ($csvRows as $row) {
            if (filled(trim((string) ($row['storage_location'] ?? '')))) $metrics['csv_rows_with_storage_raw']++;
            if ($this->normalizeStorageLocation($row['storage_location'] ?? '') !== '') $metrics['csv_rows_with_storage_normalized']++;
        }

        $would = []; $blocked = [];
        PartResource::adminPartsToListQuery()->with('storageLocation')->orderBy('id')->chunkById(500, function ($parts) use (&$metrics, &$would, &$blocked, $csvById, $limit): void {
            foreach ($parts as $part) {
                $hasStorage = filled($part->storageLocation?->name);
                if ($hasStorage) $metrics['already_has_storage_count']++; else $metrics['target_parts_to_list_missing_storage']++;
                $normalized = $this->normalizePanelGpsGmailId($part->part_number);
                if ($normalized === null) { $metrics['skipped_non_gps_gmail_number_count']++; if (!$hasStorage && count($blocked) < $limit) $blocked[] = $this->blocked($part, null, 'non_gps_gmail_number', 0); continue; }
                $metrics['parts_to_list_with_gps_gmail_number']++;
                if ($hasStorage) continue;
                $matches = $csvById->get($normalized, collect());
                if ($matches->count() === 0) { $metrics['no_match_count']++; if (count($blocked) < $limit) $blocked[] = $this->blocked($part, $normalized, 'no_match', 0); continue; }
                if ($matches->count() > 1) { $metrics['ambiguous_count']++; if (count($blocked) < $limit) $blocked[] = $this->blocked($part, $normalized, 'ambiguous', $matches->count()); continue; }
                $metrics['matched_by_gps_gmail_staging_id_count']++;
                $csv = $matches->first(); $new = $this->normalizeStorageLocation($csv['storage_location'] ?? '');
                if ($new === '') { $metrics['skipped_empty_storage_count']++; if (count($blocked) < $limit) $blocked[] = $this->blocked($part, $normalized, 'empty_storage_after_normalization', 1); continue; }
                $metrics['would_update_count']++;
                if (count($would) < max(50, $limit)) $would[] = ['local_part_id' => $part->id, 'panel_number' => $part->part_number, 'normalized_panel_gps_gmail_id' => $normalized, 'csv_staging_item_id' => $csv['staging_item_id'], 'current_storage_location' => null, 'raw_csv_storage_location' => $csv['storage_location'] ?? null, 'new_storage_location' => $new, 'match_method' => 'gps_gmail_staging_id'];
            }
        });

        return ['ok' => true, 'metrics' => $metrics, 'would_update' => $would, 'blocked' => $blocked, 'diagnostics' => ['part_number_field' => 'parts.part_number', 'storage_location_field' => 'parts.storage_location_id -> storage_locations.name', 'target_filter' => 'PartResource::adminPartsToListQuery(): parts.needs_listing = true']];
    }

    private function normalizePanelGpsGmailId(?string $number): ?string
    {
        $number = trim((string) $number);
        return preg_match('/^GPS-GMAIL-(.+)$/i', $number, $m) ? trim($m[1]) : null;
    }

    private function blocked(Part $part, ?string $normalized, string $reason, int $count): array
    { return ['local_part_id' => $part->id, 'panel_number' => $part->part_number, 'normalized_panel_gps_gmail_id' => $normalized, 'reason' => $reason, 'candidate_count_csv' => $count, 'current_storage_location' => $part->storageLocation?->name]; }

    private function readCsv(): array
    {
        if (! Storage::disk('local')->exists(self::CSV_PATH)) throw new RuntimeException('CSV not found at storage/app/'.self::CSV_PATH);
        $handle = fopen(Storage::disk('local')->path(self::CSV_PATH), 'r');
        $header = fgetcsv($handle) ?: [];
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) $rows[] = array_combine($header, array_pad($data, count($header), null));
        fclose($handle);
        return $rows;
    }

    private function findOrCreateLocation(string $name): StorageLocation
    {
        $display = StorageLocation::displayName($name);
        $normalized = StorageLocation::normalizeName($display);
        $existing = StorageLocation::query()->get()->first(fn (StorageLocation $location): bool => StorageLocation::normalizeName($location->name) === $normalized);
        return $existing ?: StorageLocation::query()->create(['name' => $display, 'is_active' => true]);
    }

    private function log(string $mode, string $status, array $summary, array $payload): void
    {
        MarketplaceSyncLog::query()->create(['marketplace' => 'local', 'action' => 'parts_to_list_storage_location_backfill', 'status' => $status, 'message' => 'Parts to list storage location backfill '.$mode.'; no marketplace write.', 'payload' => ['summary' => $summary, 'payload' => $payload], 'created_at' => now()]);
    }
}
