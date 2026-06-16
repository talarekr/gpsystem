<?php

namespace App\Services\ImportMigration;

use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class WooCategoryTreeImport
{
    public const DIRECTORY = 'imports/manual/woo';
    public const CSV = 'woo_category_tree.csv';
    public const JSON = 'woo_category_tree.json';
    public const SUMMARY = 'woo_category_tree_summary.json';

    public const REQUIRED_COLUMNS = [
        'term_id', 'parent_term_id', 'depth', 'sort_order', 'name', 'slug', 'full_path', 'full_slug_path',
        'product_count', 'description', 'thumbnail_url', 'source_taxonomy', 'ebay_category_id', 'ebay_category_name',
        'ebay_category_path', 'ebay_marketplace', 'ebay_mapping_source', 'ebay_category_id_de', 'ebay_category_id_fr',
        'ebay_category_name_de', 'ebay_category_name_fr', 'ebay_category_path_de', 'ebay_category_path_fr',
    ];

    public function audit(): array
    {
        $dir = storage_path('app/'.self::DIRECTORY);
        $csv = $dir.'/'.self::CSV;
        $rows = is_readable($csv) ? $this->readCsv($csv) : [];
        $columns = $rows[0] ?? [];
        $data = array_slice($rows, 1);
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));
        $report = [
            'timestamp' => now()->toIso8601String(),
            'files' => [
                self::CSV => ['exists' => is_file($csv), 'readable' => is_readable($csv)],
                self::JSON => ['exists' => is_file($dir.'/'.self::JSON), 'readable' => is_readable($dir.'/'.self::JSON)],
                self::SUMMARY => ['exists' => is_file($dir.'/'.self::SUMMARY), 'readable' => is_readable($dir.'/'.self::SUMMARY)],
            ],
            'csv_readable' => is_readable($csv),
            'required_columns_present' => $missing === [],
            'missing_columns' => $missing,
            'row_count' => count($data),
            'root_categories' => 0,
            'max_depth' => 0,
            'categories_with_products' => 0,
            'empty_categories' => 0,
            'categories_with_ebay_category_id' => 0,
            'categories_without_ebay_category_id' => 0,
            'ebay_marketplaces_detected' => [],
            'existing_woo_categories_in_part_categories' => 0,
            'parts_with_category_id' => Part::query()->whereNotNull('category_id')->count(),
            'parts_without_category_id' => Part::query()->whereNull('category_id')->count(),
        ];
        foreach ($this->assocRows($columns, $data) as $row) {
            $report['root_categories'] += trim((string) ($row['parent_term_id'] ?? '')) === '' || (string) $row['parent_term_id'] === '0' ? 1 : 0;
            $report['max_depth'] = max($report['max_depth'], (int) ($row['depth'] ?? 0));
            $count = (int) ($row['product_count'] ?? 0);
            $report[$count > 0 ? 'categories_with_products' : 'empty_categories']++;
            $hasEbay = trim((string) ($row['ebay_category_id'] ?? '')) !== '';
            $report[$hasEbay ? 'categories_with_ebay_category_id' : 'categories_without_ebay_category_id']++;
            if (trim((string) ($row['ebay_marketplace'] ?? '')) !== '') $report['ebay_marketplaces_detected'][] = trim($row['ebay_marketplace']);
        }
        $report['ebay_marketplaces_detected'] = array_values(array_unique($report['ebay_marketplaces_detected']));
        $termIds = array_values(array_filter(array_map(fn ($r) => (string) ($r['term_id'] ?? ''), $this->assocRows($columns, $data))));
        $report['existing_woo_categories_in_part_categories'] = $termIds === [] ? 0 : PartCategory::query()->woo()->whereIn('external_id', $termIds)->count();
        $this->writeJson($dir.'/category_tree_audit.json', $report);
        return $report;
    }

    public function import(): array
    {
        $started = microtime(true); $dir = storage_path('app/'.self::DIRECTORY); $log = $dir.'/category_tree_import.log';
        $stats = ['timestamp' => now()->toIso8601String(), 'created' => 0, 'updated' => 0, 'parent_assigned' => 0, 'name_conflicts' => 0, 'slug_conflicts' => 0, 'ebay_mappings_saved' => 0, 'categories_without_ebay_mapping' => 0, 'skipped_rows' => 0, 'warnings' => []];
        try {
            $this->appendLog($log, 'START '.json_encode(['timestamp' => $stats['timestamp']]));
            $rows = $this->readCsv($dir.'/'.self::CSV); $columns = $rows[0] ?? []; $data = $this->assocRows($columns, array_slice($rows, 1));
            $stats['csv_rows'] = count($data);
            foreach ($data as $row) {
                if (blank($row['term_id'] ?? null)) { $stats['skipped_rows']++; continue; }
                $category = PartCategory::query()->woo()->where('external_id', (string) $row['term_id'])->first();
                $exists = (bool) $category; $category ??= new PartCategory(['source_system' => 'woo', 'external_id' => (string) $row['term_id']]);
                $parentId = $this->parentId($row['parent_term_id'] ?? null); if ($parentId) $stats['parent_assigned']++;
                $name = $this->uniqueName((string) $row['name'], (string) $row['full_path'], $category->id, $stats);
                $slug = $this->uniqueSlug((string) $row['slug'], (string) $row['full_slug_path'], (string) $row['term_id'], $category->id, $stats);
                $payload = $category->legacy_payload ?: [];
                if ($name !== (string) $row['name']) $payload['original_name'] = (string) $row['name'];
                $payload['woo_category_tree'] = ['term_id' => (string) $row['term_id'], 'parent_term_id' => (string) ($row['parent_term_id'] ?? ''), 'depth' => (string) ($row['depth'] ?? ''), 'sort_order' => (string) ($row['sort_order'] ?? ''), 'source_taxonomy' => (string) ($row['source_taxonomy'] ?? '')];
                $payload['marketplace_mappings'] = $this->marketplaceMappings($row);
                $category->fill(['parent_id' => $parentId, 'name' => $name, 'slug' => $slug, 'sort_order' => (int) ($row['sort_order'] ?? 0), 'category_path' => $row['full_path'] ?? null, 'full_slug_path' => $row['full_slug_path'] ?? null, 'woo_product_count' => is_numeric($row['product_count'] ?? null) ? (int) $row['product_count'] : null, 'description' => $row['description'] ?: null, 'thumbnail_url' => $row['thumbnail_url'] ?: null, 'legacy_payload' => $payload]);
                $category->save(); $stats[$exists ? 'updated' : 'created']++;
                if (filled($row['ebay_category_id'] ?? null) || filled($row['ebay_category_id_de'] ?? null) || filled($row['ebay_category_id_fr'] ?? null)) $stats['ebay_mappings_saved']++; else $stats['categories_without_ebay_mapping']++;
            }
            $stats['duration_seconds'] = round(microtime(true) - $started, 3); $this->appendLog($log, 'END '.json_encode($stats, JSON_UNESCAPED_UNICODE)); return $stats;
        } catch (Throwable $e) {
            file_put_contents($dir.'/category_tree_error.log', now()->toIso8601String().' '.$e::class.' '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL, FILE_APPEND | LOCK_EX); throw $e;
        }
    }

    private function readCsv(string $path): array { $rows = []; if (($h = fopen($path, 'r')) !== false) { while (($row = fgetcsv($h)) !== false) $rows[] = $row; fclose($h); } return $rows; }
    private function assocRows(array $columns, array $rows): array { return array_map(fn ($r) => array_combine($columns, array_pad($r, count($columns), null)) ?: [], $rows); }
    private function parentId($term): ?int { $term = trim((string) $term); return ($term === '' || $term === '0') ? null : PartCategory::query()->woo()->where('external_id', $term)->value('id'); }
    private function uniqueName(string $name, string $path, ?int $id, array &$stats): string { $q = PartCategory::query()->where('name', $name); if ($id) $q->whereKeyNot($id); if (! $q->exists()) return $name; $stats['name_conflicts']++; return Str::limit($name.' — '.$path, 255, ''); }
    private function uniqueSlug(string $slug, string $full, string $term, ?int $id, array &$stats): ?string { $candidates = array_filter([$slug, str_replace('/', '-', $full), $slug.'-'.$term, 'woo-category-'.$term]); foreach ($candidates as $i => $candidate) { $candidate = Str::limit(Str::slug($candidate), 255, ''); $q = PartCategory::query()->where('slug', $candidate); if ($id) $q->whereKeyNot($id); if (! $q->exists()) { if ($i > 0) $stats['slug_conflicts']++; return $candidate; } } return null; }
    private function marketplaceMappings(array $r): array { return ['ebay' => ['category_id' => $r['ebay_category_id'] ?? null, 'category_name' => $r['ebay_category_name'] ?? null, 'category_path' => $r['ebay_category_path'] ?? null, 'marketplace' => $r['ebay_marketplace'] ?? null, 'mapping_source' => $r['ebay_mapping_source'] ?? null], 'ebay_de' => ['category_id' => $r['ebay_category_id_de'] ?? null, 'category_name' => $r['ebay_category_name_de'] ?? null, 'category_path' => $r['ebay_category_path_de'] ?? null], 'ebay_fr' => ['category_id' => $r['ebay_category_id_fr'] ?? null, 'category_name' => $r['ebay_category_name_fr'] ?? null, 'category_path' => $r['ebay_category_path_fr'] ?? null]]; }
    private function writeJson(string $path, array $data): void { if (! is_dir(dirname($path))) mkdir(dirname($path), 0755, true); file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    private function appendLog(string $path, string $line): void { if (! is_dir(dirname($path))) mkdir(dirname($path), 0755, true); file_put_contents($path, $line.PHP_EOL, FILE_APPEND | LOCK_EX); }
}
