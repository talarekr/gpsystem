<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class CategoryMappingExportController extends Controller
{
    private const CHANNELS = [
        'allegro' => ['allegro_main', 'allegro'],
        'ovoko' => ['ovoko'],
        'ebay_de' => ['ebay_de', 'ebay'],
    ];

    private const HEADER = [
        'shop_category_id', 'shop_category_name', 'shop_category_path',
        'allegro_channel', 'allegro_category_id', 'allegro_category_name', 'allegro_category_path',
        'ovoko_channel', 'ovoko_category_id', 'ovoko_category_name', 'ovoko_category_path',
        'ebay_de_channel', 'ebay_de_category_id', 'ebay_de_category_name', 'ebay_de_category_path',
        'mapping_status', 'exported_at',
    ];

    public function __invoke(): JsonResponse
    {
        abort_unless(
            Schema::hasTable('part_categories') && Schema::hasTable('marketplace_category_mappings'),
            500,
            'Required category mapping tables are missing.'
        );

        $exportedAt = now();
        $relativePath = 'exports/tools/category_mapping_export_'.$exportedAt->format('Ymd_His').'.csv';
        $disk = Storage::disk('public');
        $disk->makeDirectory('exports/tools');
        $handle = fopen($disk->path($relativePath), 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Cannot create the category mapping export.');
        }

        // UTF-8 BOM keeps Polish category names readable in desktop spreadsheet programs.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::HEADER);

        $counts = [
            'total_shop_categories' => 0,
            'complete_mapping_count' => 0,
            'missing_allegro_count' => 0,
            'missing_ovoko_count' => 0,
            'missing_ebay_count' => 0,
            'missing_all_count' => 0,
        ];

        try {
            DB::table('part_categories')->orderBy('id')->chunkById(500, function ($categories) use ($handle, $exportedAt, &$counts): void {
                $mappings = DB::table('marketplace_category_mappings')
                    ->whereIn('local_category_id', $categories->pluck('id'))
                    ->whereIn('channel', array_merge(...array_values(self::CHANNELS)))
                    ->get()
                    ->groupBy('local_category_id');

                $catalog = $this->marketplaceCatalog($mappings->flatten());

                foreach ($categories as $category) {
                    $categoryMappings = $mappings->get($category->id, collect());
                    $resolved = [];
                    foreach (self::CHANNELS as $key => $channels) {
                        $resolved[$key] = $this->preferredMapping($categoryMappings, $channels);
                    }

                    $missing = array_keys(array_filter($resolved, fn ($mapping): bool => $mapping === null || blank($mapping->external_category_id)));
                    $status = $this->status($missing);
                    $counts['total_shop_categories']++;
                    if ($status === 'complete') $counts['complete_mapping_count']++;
                    if (in_array('allegro', $missing, true)) $counts['missing_allegro_count']++;
                    if (in_array('ovoko', $missing, true)) $counts['missing_ovoko_count']++;
                    if (in_array('ebay_de', $missing, true)) $counts['missing_ebay_count']++;
                    if ($status === 'missing_all') $counts['missing_all_count']++;

                    $row = [$category->id, $category->name, $category->category_path ?? null];
                    foreach (['allegro', 'ovoko', 'ebay_de'] as $key) {
                        $mapping = $resolved[$key];
                        $catalogEntry = $mapping ? ($catalog[$mapping->channel.'|'.$mapping->external_category_id] ?? null) : null;
                        array_push($row,
                            $mapping->channel ?? null,
                            $mapping->external_category_id ?? null,
                            $catalogEntry->name ?? $mapping->external_category_name ?? null,
                            $catalogEntry->full_path ?? $mapping->external_category_path ?? null,
                        );
                    }
                    $row[] = $status;
                    $row[] = $exportedAt->toIso8601String();
                    fputcsv($handle, array_map($this->escapeSpreadsheetFormula(...), $row));
                }
            }, 'id');
        } finally {
            fclose($handle);
        }

        return response()->json([
            'ok' => true,
            ...$counts,
            'file_relative_path' => $relativePath,
            'public_file_path' => $disk->path($relativePath),
            'download_url' => $disk->url($relativePath),
        ]);
    }

    private function marketplaceCatalog($mappings): array
    {
        if (! Schema::hasTable('marketplace_categories') || $mappings->isEmpty()) return [];

        return DB::table('marketplace_categories')
            ->where(function ($query) use ($mappings): void {
                foreach ($mappings->groupBy('channel') as $channel => $items) {
                    $query->orWhere(fn ($query) => $query->where('channel', $channel)->whereIn('external_category_id', $items->pluck('external_category_id')->filter()->unique()));
                }
            })
            ->get()
            ->keyBy(fn ($category): string => $category->channel.'|'.$category->external_category_id)
            ->all();
    }

    private function preferredMapping($mappings, array $channels): ?object
    {
        foreach ($channels as $channel) {
            $mapping = $mappings->first(fn ($mapping): bool => $mapping->channel === $channel);
            if ($mapping !== null) return $mapping;
        }

        return null;
    }

    private function status(array $missing): string
    {
        if ($missing === []) return 'complete';
        if (count($missing) === count(self::CHANNELS)) return 'missing_all';

        return implode('|', array_map(fn (string $channel): string => 'missing_'.str_replace('_de', '', $channel), $missing));
    }

    private function escapeSpreadsheetFormula(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[=+\-@]/', $value) === 1) return "'".$value;

        return $value;
    }
}
