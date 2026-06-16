<?php

namespace App\Services\ImportMigration;

use App\Models\Car;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Models\StorageLocation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class WooProductImport
{
    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_CREATE_ONLY = 'create_only';
    public const MODE_UPDATE_EXISTING = 'update_existing';

    public function __construct(private CsvReader $csvReader) {}

    public function import(string $productsPath, array $paths = [], string $mode = self::MODE_DRY_RUN): ImportReport
    {
        $report = $this->makeReport();
        $startedAt = microtime(true);

        return $this->importBatch($productsPath, $paths, $mode, 0, null, $report, $startedAt);
    }

    public function countProductRows(string $productsPath): int
    {
        $count = 0;

        foreach ($this->csvReader->rows($productsPath) as $_row) {
            $count++;
        }

        return $count;
    }

    public function makeReport(): ImportReport
    {
        return new ImportReport([
            'created' => 0,
            'updated' => 0,
            'skipped_existing' => 0,
            'skipped_duplicates' => 0,
            'skipped' => 0,
            'images_linked' => 0,
            'categories_created' => 0,
            'categories_matched' => 0,
            'processed_rows' => 0,
            'failed_rows' => 0,
            'category_warning_count' => 0,
        ]);
    }

    public function importBatch(
        string $productsPath,
        array $paths = [],
        string $mode = self::MODE_DRY_RUN,
        int $offset = 0,
        ?int $limit = null,
        ?ImportReport $report = null,
        ?float $startedAt = null,
        ?string $runId = null,
    ): ImportReport {
        $result = $this->importBatchFromRow($productsPath, $paths, $mode, $offset + 2, $limit ?? PHP_INT_MAX, $report, $startedAt, $runId);

        return $result['report'];
    }

    /** @return array{report: ImportReport, processed: int, next_row: int, end_of_file: bool} */
    public function importBatchFromRow(
        string $productsPath,
        array $paths = [],
        string $mode = self::MODE_DRY_RUN,
        int $currentRow = 2,
        int $limit = 25,
        ?ImportReport $report = null,
        ?float $startedAt = null,
        ?string $runId = null,
    ): array {
        $report ??= $this->makeReport();
        $startedAt ??= microtime(true);
        $batchStartedAt = microtime(true);
        $rows = [];
        $wooIds = [];
        $lastRowNumber = max(1, $currentRow - 1);
        $endOfFile = true;

        foreach ($this->csvReader->rowsFromLine($productsPath, $currentRow) as $line => $row) {
            if (count($rows) >= $limit) {
                $endOfFile = false;
                break;
            }

            $rows[$line] = $row;
            $lastRowNumber = $line;
            $woo = (string) ($row['woo_product_id'] ?? '');
            if ($woo !== '') {
                $wooIds[$woo] = true;
            }
        }

        $ids = array_keys($wooIds);
        $cats = $this->groupForIds($paths['categories'] ?? null, 'woo_product_id', $ids);
        $meta = $this->groupForIds($paths['meta'] ?? null, 'woo_product_id', $ids);
        $attrs = $this->groupForIds($paths['attributes'] ?? null, 'woo_product_id', $ids);
        $processedInBatch = 0;

        foreach ($rows as $line => $row) {
            $processedInBatch++;
            $this->importRow($row, $line, [], $cats, $meta, $attrs, $mode, $report, $runId);
        }

        $report->counters['last_batch_rows'] = $processedInBatch;
        $report->counters['last_batch_seconds'] = round(microtime(true) - $batchStartedAt, 3);
        $report->counters['last_batch_first_row'] = $currentRow;
        $report->counters['last_batch_last_row'] = $lastRowNumber;
        $this->addDiagnostics($report, $startedAt);

        return [
            'report' => $report,
            'processed' => $processedInBatch,
            'next_row' => $processedInBatch > 0 ? $lastRowNumber + 1 : $currentRow,
            'end_of_file' => $endOfFile,
        ];
    }

    private function importRow(array $row, int $line, array $images, array $cats, array $meta, array $attrs, string $mode, ImportReport $report, ?string $runId): void
    {
        $report->inc('total_rows');
        $report->inc('processed_rows');
        $woo = (string) ($row['woo_product_id'] ?? '');

        if ($woo === '') {
            $report->error("Wiersz {$line}: brak woo_product_id.");
            $this->recordSkippedProduct($runId, $line, $row, 'missing_external_id', [
                'existing_part_id' => null,
            ]);

            return;
        }

        $existing = Part::query()->where('source_system', 'woo')->where('external_id', $woo)->first();
        $sku = trim((string) ($row['sku'] ?? ''));
        $skuConflict = $sku !== ''
            ? Part::query()->where('sku', $sku)->where(function ($query) use ($woo) {
                $query->where('source_system', '!=', 'woo')->orWhere('external_id', '!=', $woo)->orWhereNull('external_id');
            })->first()
            : null;

        if ($skuConflict && ! $existing) {
            $report->inc('skipped_duplicates');
            $report->warning("Wiersz {$line}: SKU {$sku} istnieje przy innej części; pominięto produkt Woo {$woo}.");
            $this->recordSkippedProduct($runId, $line, $row, 'sku_conflict', [
                'conflicting_part_id' => $skuConflict->id,
                'conflicting_part_source_system' => $skuConflict->source_system,
                'conflicting_part_external_id' => $skuConflict->external_id,
                'existing_part_id' => null,
            ]);

            return;
        }

        $category = $this->category($cats[$woo][0] ?? null, $report, $mode, $woo);
        $carId = $this->carId($row, $report, $line);
        $payload = $this->map($row, $meta[$woo] ?? [], $attrs[$woo] ?? [], $category?->id, $carId);

        if ($mode === self::MODE_DRY_RUN) {
            $status = $existing ? 'would_update' : 'would_create';
            $existing ? $report->inc('would_update') : $report->inc('would_create');
            $existing ? $report->inc('skipped_existing') : $report->inc('created');
            $partNumber = trim((string) ($payload['part_number'] ?? ''));
            $oemNumber = trim((string) ($payload['oem_number'] ?? ''));
            $hasName = trim((string) ($row['name'] ?? '')) !== '';
            if (! $hasName || ($partNumber === '' && $oemNumber === '')) {
                $status = 'skipped';
                $report->inc('skipped');
                $this->recordSkippedProduct($runId, $line, $row, ! $hasName ? 'missing_name' : 'missing_part_number_or_oem', [
                    'existing_part_id' => $existing?->id,
                    'has_part_number' => $partNumber !== '',
                    'has_oem_number' => $oemNumber !== '',
                    'dry_run' => true,
                ]);
            }
            $report->warnings[] = sprintf(
                'Dry run wiersz %d: external_id=%s, existing_part=%s, ovoko_car_id=%s, found_car_id=%s, has_name=%s, has_part_number_or_oem=%s, status=%s.',
                $line,
                $woo,
                $existing ? 'yes' : 'no',
                trim((string) ($row['ovoko_car_id'] ?? '')) !== '' ? 'yes' : 'no',
                $carId !== null ? 'yes' : 'no',
                $hasName ? 'yes' : 'no',
                ($partNumber !== '' || $oemNumber !== '') ? 'yes' : 'no',
                $status,
            );

            return;
        }

        if ($existing) {
            if ($mode === self::MODE_UPDATE_EXISTING) {
                $existing->fill($payload)->save();
                $part = $existing;
                $report->inc('updated');
            } else {
                $part = $existing;
                $report->inc('skipped_existing');
                $this->recordSkippedProduct($runId, $line, $row, 'already_exists', [
                    'existing_part_id' => $existing->id,
                    'existing_part_status' => $existing->status,
                ]);
            }
        } else {
            $part = Part::query()->create($payload);
            $report->inc('created');
        }

        $this->images($part, $images[$woo] ?? [], $report);

        if (empty($images[$woo])) {
            $report->inc('products_missing_images');
        }

        if (empty($cats[$woo])) {
            $report->inc('products_missing_categories');
        }
    }

    private function addDiagnostics(ImportReport $report, float $startedAt): void
    {
        $report->counters['elapsed_seconds'] = round(microtime(true) - $startedAt, 3);
        $report->counters['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $report->counters['memory_current_mb'] = round(memory_get_usage(true) / 1024 / 1024, 2);
    }

    private function group(?string $path, string $key): array { return $this->groupForIds($path, $key, []); }
    private function groupForIds(?string $path, string $key, array $ids): array { $out=[]; if(!$path||!is_file($path)) return $out; $filter = array_fill_keys($ids, true); foreach($this->csvReader->rows($path) as $r) { $id=(string)($r[$key]??''); if($ids !== [] && !isset($filter[$id])) continue; $out[$id][]=$r; } return $out; }
    private function map(array $r,array $meta,array $attrs,?int $categoryId,?int $carId): array {
        $legacyJson=null; if (filled($r['legacy_payload_json']??null)) { try { $legacyJson=json_decode($r['legacy_payload_json'], true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) { $legacyJson=['legacy_payload_json_malformed'=>true]; } }
        $metaMap=[]; foreach($meta as $m) $metaMap[$m['meta_key']??'']=$m['meta_value']??null;
        return ['source_system'=>'woo','external_id'=>(string)$r['woo_product_id'],'sku'=>blank($r['sku']??null)?null:$r['sku'],'name'=>$r['name'] ?: ('Woo produkt '.$r['woo_product_id']),'slug'=>blank($r['slug']??null)?null:Str::limit($r['slug'],255,''),'legacy_slug'=>$r['slug']??null,'legacy_url'=>$r['permalink']??null,'short_description'=>$r['short_description']??null,'description'=>$r['description']??null,'price'=>(float)($r['price']?:0)?:null,'currency'=>$r['currency']?:'PLN','quantity'=>(int)($r['quantity']?:1),'status'=>$this->status($r),'part_number'=>$r['part_number'] ?: ($metaMap['_part_number']??null),'oem_number'=>$r['oem_number'] ?: ($metaMap['_oem_number']??null),'manufacturer_code'=>$r['manufacturer_code']??null,'condition_notes'=>$r['condition']??null,'category_id'=>$categoryId,'car_id'=>$carId,'storage_location_id'=>$this->locationId($r['storage_location_name']??null),'is_visible_storefront'=>false,'legacy_payload'=>['woo_product'=>$r,'legacy_payload_json'=>$legacyJson,'meta'=>$meta,'attributes'=>$attrs,'brand'=>$r['brand']??null,'manufacturer'=>$r['manufacturer']??null,'donor_car_id'=>$r['donor_car_id']??null,'source_car_id'=>$r['car_id']??null,'vehicle_id'=>$r['vehicle_id']??null]];
    }
    private function status(array $r): string { $s=strtolower((string)($r['status']??'')); $p=(string)($r['published']??''); return $s==='trash'?'archived':(($s==='publish'||$p==='1')?'ready':'draft'); }
    private function carId(array $r,ImportReport $report,int $line): ?int { $id=(int)($r['ovoko_car_id']??0); if($id<=0){$report->inc('products_without_ovoko_car_id'); return null;} $report->inc('products_with_ovoko_car_id'); if(Car::query()->whereKey($id)->exists()){ $report->inc('products_linked_to_imported_car'); return $id;} $report->inc('products_with_missing_car_reference'); $report->warning("Wiersz {$line}: brak lokalnego samochodu dla ovoko_car_id {$id}."); return null; }
    private function locationId(?string $name): ?int { return filled($name) ? StorageLocation::query()->where('name',$name)->value('id') : null; }

    private function recordSkippedProduct(?string $runId, int $line, array $row, string $reason, array $diagnostics = []): void
    {
        $directory = storage_path('app/imports/manual/woo');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $wooProductId = trim((string) ($row['woo_product_id'] ?? ''));
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'csv_row_number' => $line,
            'woo_product_id' => $wooProductId !== '' ? $wooProductId : null,
            'external_id' => $wooProductId !== '' ? $wooProductId : null,
            'sku' => $row['sku'] ?? null,
            'name' => $row['name'] ?? null,
            'reason' => $reason,
            'diagnostics' => array_merge([
                'woo_status' => $row['status'] ?? null,
                'published' => $row['published'] ?? null,
                'quantity' => $row['quantity'] ?? null,
                'price' => $row['price'] ?? null,
                'product_type' => $row['type'] ?? ($row['product_type'] ?? null),
            ], $diagnostics),
        ];

        file_put_contents($directory.'/skipped_products.log', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function category(?array $r, ImportReport $report, string $mode, string $wooProductId): ?PartCategory
    {
        if (! $r) {
            return null;
        }

        $categoryId = trim((string) ($r['category_id'] ?? ''));
        $name = trim((string) ($r['category_name'] ?? ''));
        $path = trim((string) ($r['category_path'] ?? ''));
        $slug = trim((string) ($r['slug'] ?? ''));
        $slug = $slug !== '' ? $slug : Str::slug($name !== '' ? $name : $path);
        $name = $name !== '' ? $name : basename(str_replace('>', '/', $path !== '' ? $path : 'Kategoria Woo'));
        $payload = ['woo_category' => $r];

        $cat = $categoryId !== ''
            ? PartCategory::query()->where('source_system', 'woo')->where('external_id', $categoryId)->first()
            : null;

        if ($cat) {
            $this->updateWooCategoryMetadata($cat, $categoryId, $path, $payload, $slug);
            $report->inc('categories_matched');

            return $cat;
        }

        $cat = $this->findCategoryFallback($slug, $path, $name);

        if ($cat) {
            $action = $cat->name === $name ? 'matched_existing_name' : 'matched_existing_slug_or_path';
            $this->updateWooCategoryMetadata($cat, $categoryId, $path, $payload, $slug);
            $report->inc('categories_matched');

            if ($cat->name === $name && ($cat->source_system !== 'woo' || (string) $cat->external_id !== $categoryId)) {
                $this->recordCategoryWarning($report, $wooProductId, $r, $action, 'Woo category reused existing part_categories.name fallback.');
            }

            return $cat;
        }

        if ($this->nameExists($name)) {
            $cat = PartCategory::query()->where('name', $name)->first();

            if ($cat) {
                $this->updateWooCategoryMetadata($cat, $categoryId, $path, $payload, $slug);
                $report->inc('categories_matched');
                $this->recordCategoryWarning($report, $wooProductId, $r, 'fallback_existing_name_before_insert', 'part_categories.name already exists; reused existing category instead of inserting duplicate.');

                return $cat;
            }
        }

        if ($mode === self::MODE_DRY_RUN) {
            $report->inc('categories_created');

            return null;
        }

        try {
            $report->inc('categories_created');

            return PartCategory::query()->create([
                'source_system' => 'woo',
                'external_id' => $categoryId !== '' ? $categoryId : null,
                'name' => $name,
                'slug' => $slug !== '' ? $slug : null,
                'category_path' => $path !== '' ? $path : null,
                'legacy_payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->fallbackAfterCategoryException($report, $wooProductId, $r, $slug, $path, $name, $categoryId, $payload, $exception, 'fallback_after_unique_exception');
        } catch (QueryException $exception) {
            return $this->fallbackAfterCategoryException($report, $wooProductId, $r, $slug, $path, $name, $categoryId, $payload, $exception, 'fallback_after_query_exception');
        }
    }

    private function fallbackAfterCategoryException(ImportReport $report, string $wooProductId, array $row, string $slug, string $path, string $name, string $categoryId, array $payload, QueryException $exception, string $action): ?PartCategory
    {
        $fallback = $this->findCategoryFallback($slug, $path, $name);

        if ($fallback) {
            $this->updateWooCategoryMetadata($fallback, $categoryId, $path, $payload, $slug);
            $report->inc('categories_matched');
            $this->recordCategoryWarning($report, $wooProductId, $row, $action, $exception->getMessage());

            return $fallback;
        }

        $this->recordCategoryWarning($report, $wooProductId, $row, 'category_skipped_after_exception', $exception->getMessage());

        return null;
    }

    private function findCategoryFallback(string $slug, string $path, string $name): ?PartCategory
    {
        if ($slug !== '') {
            $cat = PartCategory::query()->where('slug', $slug)->first();
            if ($cat) {
                return $cat;
            }
        }

        if ($path !== '') {
            $cat = PartCategory::query()->where('category_path', $path)->first();
            if ($cat) {
                return $cat;
            }
        }

        return $name !== '' ? PartCategory::query()->where('name', $name)->first() : null;
    }

    private function updateWooCategoryMetadata(PartCategory $cat, string $categoryId, string $path, array $payload, string $slug): void
    {
        $updates = [];

        if (blank($cat->source_system)) {
            $updates['source_system'] = 'woo';
        }
        if (blank($cat->external_id) && $categoryId !== '') {
            $updates['external_id'] = $categoryId;
        }
        if (blank($cat->category_path) && $path !== '') {
            $updates['category_path'] = $path;
        }
        if (blank($cat->slug) && $slug !== '' && ! PartCategory::query()->where('slug', $slug)->whereKeyNot($cat->getKey())->exists()) {
            $updates['slug'] = $slug;
        }

        $legacyPayload = is_array($cat->legacy_payload) ? $cat->legacy_payload : [];
        if (! array_key_exists('woo_category', $legacyPayload)) {
            $updates['legacy_payload'] = array_merge($legacyPayload, $payload);
        }

        if ($updates !== []) {
            $cat->fill($updates)->save();
        }
    }

    private function nameExists(string $name): bool
    {
        return $name !== '' && PartCategory::query()->where('name', $name)->exists();
    }

    private function recordCategoryWarning(ImportReport $report, string $wooProductId, array $row, string $action, string $message): void
    {
        $report->inc('category_warning_count');
        $report->warning(sprintf(
            'Woo category warning: product=%s category=%s action=%s message=%s',
            $wooProductId,
            (string) ($row['category_id'] ?? ''),
            $action,
            $message,
        ));

        $directory = storage_path('app/imports/manual/woo');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $line = json_encode([
            'timestamp' => now()->toIso8601String(),
            'woo_product_id' => $wooProductId,
            'category_id' => $row['category_id'] ?? null,
            'category_name' => $row['category_name'] ?? null,
            'category_slug' => $row['slug'] ?? null,
            'category_path' => $row['category_path'] ?? null,
            'fallback_action' => $action,
            'exception_message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents($directory.'/category_warning.log', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function images(Part $part,array $rows,ImportReport $report): void { foreach($rows as $r){ $url=$r['image_url']??null; if(blank($url)){ $report->warning('Pominięto obraz bez URL dla części '.$part->id); continue;} $img=PartImage::query()->firstOrCreate(['part_id'=>$part->id,'source_system'=>'woo','external_id'=>(string)($r['image_id'] ?: md5($url))],['path'=>$url,'alt_text'=>$r['alt_text']??null,'sort_order'=>(int)($r['position']??0),'is_primary'=>((string)($r['is_primary']??''))==='1'||((int)($r['position']??0)===0)]); if($img->wasRecentlyCreated) $report->inc('images_linked'); } }
}
