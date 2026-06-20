<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class OvokoUnmappedExportService
{
    public function __construct(private readonly OvokoPartIdExtractor $extractor)
    {
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        if (! Schema::hasTable('parts')) {
            throw new \RuntimeException('Required table parts must exist.');
        }

        Storage::disk('local')->makeDirectory('exports');
        $relativePath = 'exports/ovoko_unmapped_'.now()->format('Ymd_His').'.csv';
        $absolutePath = Storage::disk('local')->path($relativePath);
        $handle = fopen($absolutePath, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open export file for writing: '.$absolutePath);
        }

        $header = ['part_id', 'name', 'sku', 'part_number', 'oem_number', 'manufacturer_code', 'external_id', 'source_system', 'price', 'quantity', 'category_id', 'current_ovoko_part_id', 'new_ovoko_part_id', 'reason', 'conflict_ovoko_part_id', 'conflict_part_ids'];
        fputcsv($handle, $header);

        $columns = $this->availablePartColumns();
        $rowsByOvokoId = [];
        $missingRows = [];

        DB::table('parts')
            ->select(array_values($columns))
            ->orderBy('id')
            ->chunkById(500, function ($parts) use (&$rowsByOvokoId, &$missingRows): void {
                foreach ($parts as $part) {
                    $ovokoId = $this->extractor->extract($part->legacy_payload ?? null);
                    if ($ovokoId === null) {
                        $missingRows[] = $part;
                        continue;
                    }

                    $rowsByOvokoId[$ovokoId][] = $part;
                }
            });

        $rowsCount = 0;
        foreach ($missingRows as $part) {
            fputcsv($handle, $this->csvRow($part, 'missing_ovoko_id'));
            $rowsCount++;
        }

        foreach ($rowsByOvokoId as $ovokoId => $parts) {
            if (count($parts) < 2) {
                continue;
            }

            $conflictPartIds = implode('|', array_map(fn ($part): string => (string) $part->id, $parts));
            foreach ($parts as $part) {
                fputcsv($handle, $this->csvRow($part, 'conflict', $ovokoId, $conflictPartIds));
                $rowsCount++;
            }
        }

        fclose($handle);

        return [
            'ok' => true,
            'file' => $absolutePath,
            'download_url' => url('/storage/'.$relativePath),
            'rows_count' => $rowsCount,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    private function availablePartColumns(): array
    {
        $wanted = ['id', 'name', 'sku', 'part_number', 'oem_number', 'manufacturer_code', 'external_id', 'source_system', 'price', 'quantity', 'category_id', 'legacy_payload'];

        return collect($wanted)
            ->filter(fn (string $column): bool => Schema::hasColumn('parts', $column))
            ->mapWithKeys(fn (string $column): array => [$column => $column])
            ->all();
    }

    /** @return array<int, mixed> */
    private function csvRow(object $part, string $reason, ?string $conflictOvokoId = null, ?string $conflictPartIds = null): array
    {
        return [
            $part->id ?? '', $part->name ?? '', $part->sku ?? '', $part->part_number ?? '', $part->oem_number ?? '', $part->manufacturer_code ?? '',
            $part->external_id ?? '', $part->source_system ?? '', $part->price ?? '', $part->quantity ?? '', $part->category_id ?? '',
            '', '', $reason, $conflictOvokoId ?? '', $conflictPartIds ?? '',
        ];
    }
}
