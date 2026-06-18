<?php

namespace App\Console\Commands;

use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SplFileObject;
use Throwable;

class ImportProductImagesFromCsv extends Command
{
    protected $signature = 'gps:import-product-images-from-csv {csvPath} {--dry-run} {--limit=} {--offset=} {--product-id=} {--skip-existing} {--source-root=} {--copy-files}';

    protected $description = 'Analyze and safely import WooCommerce product images from a CSV file.';

    /** @var array<int, string> */
    private array $requiredHeaders = ['woo_product_id', 'sku', 'image_id', 'image_url', 'alt_text', 'position', 'is_primary'];

    public function handle(): int
    {
        $csvPath = (string) $this->argument('csvPath');
        $dryRun = (bool) $this->option('dry-run');
        $productId = $this->filledOption('product-id');
        $limit = $this->positiveIntOption('limit');
        $offset = $this->nonNegativeIntOption('offset') ?? 0;
        $skipExisting = (bool) $this->option('skip-existing');
        $sourceRoot = $this->sourceRootOption();
        $copyFiles = (bool) $this->option('copy-files');

        $report = $this->emptyReport();

        try {
            $rows = $this->readCsv($csvPath, $report);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if ($productId !== null) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $this->normalizeKey($row['woo_product_id'] ?? null) === $productId
            ));
        }

        $groups = $this->groupRowsByWooProduct($rows);

        if ($offset > 0 || $limit !== null) {
            $groups = array_slice($groups, $offset, $limit, true);
        }

        $partColumns = $this->partColumns();
        $matchColumns = $this->matchColumns($partColumns);
        $skuCounts = $this->skuCounts();

        $matches = [];
        $unmatched = [];
        $ambiguous = [];
        $matchedImageCount = 0;
        $skippedExistingProducts = 0;

        foreach ($groups as $wooProductId => $productRows) {
            $firstRow = $productRows[0];
            $match = $this->matchPart($firstRow, $matchColumns, $skuCounts);

            if (($match['status'] ?? null) === 'matched') {
                /** @var Part $part */
                $part = $match['part'];
                $hasExistingImages = $part->images()->exists();

                if ($skipExisting && $hasExistingImages) {
                    $skippedExistingProducts++;
                }

                $matches[] = [
                    'woo_product_id' => $wooProductId,
                    'sku' => $firstRow['sku'] ?? null,
                    'part_id' => $part->id,
                    'matched_by' => $match['matched_by'],
                    'images' => count($productRows),
                    'skip_existing' => $skipExisting && $hasExistingImages,
                ];
                $matchedImageCount += count($productRows);

                if (! ($skipExisting && $hasExistingImages)) {
                    $this->importProductImages($part, $wooProductId, $productRows, $report, $dryRun, $sourceRoot, $copyFiles);
                }

                continue;
            }

            $entry = [
                'woo_product_id' => $wooProductId,
                'sku' => $firstRow['sku'] ?? null,
                'reason' => $match['reason'] ?? 'brak dopasowania',
                'images' => count($productRows),
            ];

            if (($match['status'] ?? null) === 'ambiguous_sku') {
                $ambiguous[] = $entry;
                $report['ambiguous_sku']++;
            } else {
                $unmatched[] = $entry;
                $report['missing_match']++;
            }
        }

        $this->printSummary($rows, $groups, $matches, $unmatched, $ambiguous, $matchedImageCount, $skippedExistingProducts, $dryRun, $matchColumns, $report);

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string|null>> */
    private function readCsv(string $path, array &$report): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $report['invalid_csv']++;
            throw new \RuntimeException("Nie można odczytać CSV: {$path}");
        }

        $file = new SplFileObject($path, 'rb');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl(',', '"', '\\');

        $headers = null;
        $rows = [];

        foreach ($file as $index => $row) {
            if ($row === false || $row === [null]) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(fn (mixed $header): string => trim((string) $header), $row);
                $missing = array_diff($this->requiredHeaders, $headers);

                if ($missing !== []) {
                    $report['invalid_csv']++;
                    throw new \RuntimeException('Nieprawidłowy format CSV. Brak kolumn: '.implode(', ', $missing));
                }

                continue;
            }

            $assoc = [];
            foreach ($headers as $columnIndex => $header) {
                $assoc[$header] = isset($row[$columnIndex]) ? trim((string) $row[$columnIndex]) : null;
            }
            $assoc['_row_number'] = (string) ($index + 1);
            $rows[] = $assoc;
        }

        return $rows;
    }

    /** @param array<int, array<string, string|null>> $rows @return array<string, array<int, array<string, string|null>>> */
    private function groupRowsByWooProduct(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $wooProductId = $this->normalizeKey($row['woo_product_id'] ?? null) ?: '__missing_'.($row['_row_number'] ?? count($groups));
            $groups[$wooProductId][] = $row;
        }

        return $groups;
    }

    /** @return array<int, string> */
    private function partColumns(): array
    {
        return Schema::getColumnListing('parts');
    }

    /** @param array<int, string> $partColumns @return array<int, string> */
    private function matchColumns(array $partColumns): array
    {
        return array_values(array_filter(
            ['woo_product_id', 'external_id', 'source_product_id', 'woocommerce_id'],
            fn (string $column): bool => in_array($column, $partColumns, true)
        ));
    }

    /** @return array<string, int> */
    private function skuCounts(): array
    {
        if (! Schema::hasColumn('parts', 'sku')) {
            return [];
        }

        return Part::query()
            ->whereNotNull('sku')
            ->selectRaw('sku, COUNT(*) as aggregate')
            ->groupBy('sku')
            ->pluck('aggregate', 'sku')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @param array<string, string|null> $row @param array<int, string> $matchColumns @param array<string, int> $skuCounts @return array<string, mixed> */
    private function matchPart(array $row, array $matchColumns, array $skuCounts): array
    {
        $wooProductId = $this->normalizeKey($row['woo_product_id'] ?? null);

        foreach ($matchColumns as $column) {
            if ($wooProductId === null) {
                continue;
            }

            $parts = Part::query()->where($column, $wooProductId)->limit(2)->get();

            if ($parts->count() === 1) {
                return ['status' => 'matched', 'part' => $parts->first(), 'matched_by' => $column];
            }
        }

        $sku = $this->normalizeKey($row['sku'] ?? null);
        if ($sku !== null && Schema::hasColumn('parts', 'sku')) {
            if (($skuCounts[$sku] ?? 0) > 1) {
                return ['status' => 'ambiguous_sku', 'reason' => 'niejednoznaczny SKU'];
            }

            if (($skuCounts[$sku] ?? 0) === 1) {
                return ['status' => 'matched', 'part' => Part::query()->where('sku', $sku)->first(), 'matched_by' => 'sku'];
            }
        }

        return ['status' => 'missing_match', 'reason' => 'brak dopasowania'];
    }

    /** @param array<int, array<string, string|null>> $rows */
    private function importProductImages(Part $part, string $wooProductId, array $rows, array &$report, bool $dryRun, ?string $sourceRoot, bool $copyFiles): void
    {
        foreach ($rows as $row) {
            $url = trim((string) ($row['image_url'] ?? ''));
            if ($url === '') {
                $report['missing_url']++;
                continue;
            }

            $externalId = $this->normalizeKey($row['image_id'] ?? null) ?: md5($url);
            $relativePath = $this->destinationPath($url, $wooProductId);

            if ($this->partImageExists($externalId, $relativePath)) {
                if (! $dryRun) {
                    $this->syncImportedImageToPublicStorage($relativePath);
                }
                continue;
            }

            $localPath = $sourceRoot === null ? null : $this->localSourcePath($url, $sourceRoot);
            $localFileExists = $localPath !== null && is_file($localPath) && is_readable($localPath);

            if ($sourceRoot !== null) {
                if ($localFileExists) {
                    $report['local_files_found']++;
                } else {
                    $report['local_files_missing']++;
                    if (count($report['missing_local_file_examples']) < 20) {
                        $report['missing_local_file_examples'][] = $localPath ?? $this->unresolvedLocalPathLabel($url, $sourceRoot);
                    }
                    continue;
                }
            }

            if (! $dryRun && ! $copyFiles) {
                continue;
            }

            if ($copyFiles) {
                if ($sourceRoot === null || $localPath === null || ! $localFileExists) {
                    $report['local_files_missing']++;
                    if (count($report['missing_local_file_examples']) < 20) {
                        $report['missing_local_file_examples'][] = $localPath ?? $this->unresolvedLocalPathLabel($url, $sourceRoot);
                    }
                    continue;
                }

                if ($dryRun) {
                    $report['files_would_copy']++;
                } elseif ($this->copyLocalImage($localPath, $relativePath)) {
                    $report['files_copied']++;
                }
            }

            if ($dryRun) {
                $report['part_images_would_create']++;
                continue;
            }

            PartImage::query()->create([
                'source_system' => 'woo',
                'external_id' => $externalId,
                'part_id' => $part->id,
                'path' => $relativePath,
                'alt_text' => $row['alt_text'] ?? null,
                'sort_order' => (int) ($row['position'] ?? 0),
                'is_primary' => $this->truthy($row['is_primary'] ?? null),
            ]);
            $report['part_images_created']++;
        }
    }

    private function partImageExists(string $externalId, string $relativePath): bool
    {
        return PartImage::query()
            ->where(static function ($query) use ($externalId, $relativePath): void {
                $query->where(static function ($query) use ($externalId): void {
                    $query->where('source_system', 'woo')->where('external_id', $externalId);
                })->orWhere('path', $relativePath);
            })
            ->exists();
    }

    private function destinationPath(string $url, string $wooProductId): string
    {
        return 'parts/photos/imported/'.$wooProductId.'/'.$this->filenameFromUrl($url);
    }

    private function filenameFromUrl(string $url): string
    {
        $path = (string) parse_url($this->urlWithScheme($url), PHP_URL_PATH);
        $filename = rawurldecode(basename($path));

        if ($filename === '' || $filename === '.' || $filename === '/' || ! str_contains($filename, '.')) {
            $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';
            $filename = (Str::slug(pathinfo($path, PATHINFO_FILENAME) ?: 'image') ?: 'image').'.'.$extension;
        }

        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'image.jpg';
    }

    private function localSourcePath(string $url, string $sourceRoot): ?string
    {
        $path = (string) parse_url($this->urlWithScheme($url), PHP_URL_PATH);
        $marker = '/wp-content/uploads/';
        $position = strpos($path, $marker);

        if ($position === false) {
            return null;
        }

        $relative = ltrim(rawurldecode(substr($path, $position + strlen($marker))), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return rtrim($sourceRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function unresolvedLocalPathLabel(string $url, ?string $sourceRoot): string
    {
        return ($sourceRoot ?? '(brak --source-root)').DIRECTORY_SEPARATOR.'(nie można wyliczyć ścieżki z URL: '.$url.')';
    }

    private function urlWithScheme(string $url): string
    {
        return str_starts_with($url, '//') ? 'https:'.$url : $url;
    }

    private function copyLocalImage(string $localPath, string $relativePath): bool
    {
        $storageDestination = storage_path('app/public/'.$relativePath);
        $publicDestination = public_path('storage/'.$relativePath);
        $copied = false;

        if (! is_file($storageDestination)) {
            $this->ensureDirectory(dirname($storageDestination));
            copy($localPath, $storageDestination);
            chmod($storageDestination, 0644);
            $copied = true;
        }

        if (! is_file($publicDestination)) {
            $this->ensureDirectory(dirname($publicDestination));
            copy(is_file($storageDestination) ? $storageDestination : $localPath, $publicDestination);
            chmod($publicDestination, 0644);
            $copied = true;
        } else {
            chmod($publicDestination, 0644);
        }

        return $copied;
    }

    private function syncImportedImageToPublicStorage(string $relativePath): void
    {
        if (! Str::startsWith($relativePath, 'parts/photos/imported/')) {
            return;
        }

        $source = storage_path('app/public/'.$relativePath);
        $target = public_path('storage/'.$relativePath);

        if (! is_file($source) || is_file($target)) {
            return;
        }

        $this->ensureDirectory(dirname($target));
        copy($source, $target);
        chmod($target, 0644);
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        chmod($directory, 0755);
    }

    private function printSummary(array $rows, array $groups, array $matches, array $unmatched, array $ambiguous, int $matchedImageCount, int $skippedExistingProducts, bool $dryRun, array $matchColumns, array $report): void
    {
        $uniqueSkus = collect($rows)->pluck('sku')->filter(fn (mixed $sku): bool => trim((string) $sku) !== '')->unique()->count();
        $this->info($dryRun ? 'DRY-RUN: nie zapisano żadnych zmian.' : 'IMPORT: tryb zapisu włączony.');
        $this->line('Rekordy CSV: '.count($rows));
        $this->line('Unikalne produkty w CSV: '.count($groups));
        $this->line('Unikalne SKU: '.$uniqueSkus);
        $this->line('Zdjęcia: '.count($rows));
        $this->line('Produkty dopasowane do części: '.count($matches));
        $this->line('Produkty niedopasowane: '.(count($unmatched) + count($ambiguous)));
        $this->line('Zdjęcia dla dopasowanych produktów: '.$matchedImageCount);
        $this->line('Produkty pominięte przez --skip-existing: '.$skippedExistingProducts);
        $this->line('Pola dopasowania Woo: '.($matchColumns === [] ? 'brak w tabeli parts' : implode(', ', $matchColumns)).'; fallback: sku.');

        $this->table(['woo_product_id', 'sku', 'part_id', 'matched_by', 'images', 'skip_existing'], array_slice($matches, 0, 10));
        $this->table(['woo_product_id', 'sku', 'reason', 'images'], array_slice(array_merge($unmatched, $ambiguous), 0, 10));
        $this->table(['typ błędu', 'liczba'], collect($report)->except('missing_local_file_examples')->map(fn (int $count, string $key): array => [$key, $count])->values()->all());

        if ($report['missing_local_file_examples'] !== []) {
            $this->line('Przykładowe brakujące pliki lokalne (maks. 20):');
            foreach ($report['missing_local_file_examples'] as $missingLocalFile) {
                $this->line('- '.$missingLocalFile);
            }
        }
    }

    /** @return array<string, mixed> */
    private function emptyReport(): array
    {
        return [
            'missing_match' => 0,
            'ambiguous_sku' => 0,
            'missing_url' => 0,
            'invalid_csv' => 0,
            'local_files_found' => 0,
            'local_files_missing' => 0,
            'files_copied' => 0,
            'files_would_copy' => 0,
            'part_images_created' => 0,
            'part_images_would_create' => 0,
            'missing_local_file_examples' => [],
        ];
    }

    private function normalizeKey(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function truthy(mixed $value): bool
    {
        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 'tak'], true);
    }

    private function sourceRootOption(): ?string
    {
        $sourceRoot = $this->filledOption('source-root');
        if ($sourceRoot === null) {
            return null;
        }

        return str_starts_with($sourceRoot, DIRECTORY_SEPARATOR) ? $sourceRoot : base_path($sourceRoot);
    }

    private function filledOption(string $name): ?string
    {
        $value = $this->option($name);
        return $this->normalizeKey($value);
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    private function nonNegativeIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}
