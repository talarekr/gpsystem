<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Http\Controllers\Controller;
use App\Models\JarekGearbox;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Services\JarekGearboxes\AllegroJarekImportService;
use App\Services\JarekGearboxes\JarekGearboxEbayPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class JarekGearboxToolController extends Controller
{
    public function ping(AllegroJarekImportService $service): JsonResponse
    {
        try {
            $config = $service->configStatus();
            return response()->json([
                'ok' => true,
                'module' => 'Skrzynie Jarka',
                'table_exists' => Schema::hasTable('jarek_gearboxes'),
                'expected_columns_missing' => $this->missingExpectedColumns(),
                'migration_entry_exists' => $this->migrationEntryExists(),
                'config_present' => $config['present'],
                'missing_config_keys' => $config['missing'],
                'marketplace_write' => false,
            ]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'module' => 'Skrzynie Jarka', 'error' => $e->getMessage(), 'marketplace_write' => false], 200);
        }
    }

    public function dryRun(Request $request, AllegroJarekImportService $service): JsonResponse
    {
        return response()->json($service->dryRun($this->limit($request), $this->offset($request)));
    }

    public function partsImportDryRun(Request $request): JsonResponse
    {
        $summary = $this->buildPartsImportDryRun($this->limit($request), $this->offset($request));

        if (Schema::hasTable('marketplace_sync_logs')) {
            MarketplaceSyncLog::query()->create([
                'marketplace' => 'admin',
                'action' => 'jarek_gearboxes_parts_import_dry_run',
                'status' => 'success',
                'message' => 'Dry-run importu Skrzyń Jarka do parts; bez zapisu marketplace i bez tworzenia parts.',
                'payload' => $summary,
                'created_at' => now(),
            ]);
        }

        return response()->json($summary);
    }

    public function apply(Request $request, AllegroJarekImportService $service): JsonResponse
    {
        if ($request->query('confirm') !== 'jarek-gearboxes-import') {
            return response()->json(['ok' => false, 'error' => 'Missing confirm=jarek-gearboxes-import', 'marketplace_write' => false], 422);
        }

        return response()->json(['ok' => true] + $service->apply($this->limit($request), $this->offset($request)));
    }

    public function partsImportApply(Request $request): JsonResponse
    {
        if ($request->query('confirm') !== 'jarek-to-parts') {
            $response = ['ok' => false, 'error' => 'Missing confirm=jarek-to-parts', 'marketplace_write' => false];
            $this->logPartsImportApply('blocked', 'Odmowa importu Skrzyń Jarka do parts: brak wymaganego confirm.', $response);

            return response()->json($response, 422);
        }

        $limit = max(1, min(5, (int) $request->query('limit', 1)));
        $result = $this->applyPartsImport($limit, $this->offset($request));
        $this->logPartsImportApply('success', 'Apply importu Skrzyń Jarka do parts; tylko lokalny draft, marketplace_write=false.', $result);

        return response()->json($result);
    }

    public function status(AllegroJarekImportService $service): JsonResponse
    {
        return response()->json($service->status());
    }

    public function runner(): \Illuminate\View\View
    {
        return view('admin.jarek-gearboxes.import-runner', [
            'defaultBatchSize' => 100,
            'maxApplyBatchSize' => 200,
        ]);
    }

    public function ebayPreview(JarekGearbox $jarekGearbox, JarekGearboxEbayPreviewService $service): JsonResponse
    {
        return response()->json($service->build($jarekGearbox));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPartsImportDryRun(int $limit, int $offset): array
    {
        $requiredTables = ['jarek_gearboxes', 'parts'];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));
        $partsColumns = Schema::hasTable('parts') ? Schema::getColumnListing('parts') : [];
        $jarekColumns = Schema::hasTable('jarek_gearboxes') ? Schema::getColumnListing('jarek_gearboxes') : [];
        $requiredPartColumns = ['sku', 'name', 'price', 'currency', 'quantity', 'status', 'needs_listing'];
        $missingPartColumns = array_values(array_filter($requiredPartColumns, fn (string $column): bool => ! in_array($column, $partsColumns, true)));

        $base = [
            'ok' => $missingTables === [],
            'dry_run' => true,
            'local_write' => false,
            'marketplace_write' => false,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'parts_changed' => false,
            'recommended_identifier_field' => 'sku',
            'recommended_identifier_format' => 'JAREK-{allegro_offer_id}',
            'recommended_identifier_reason' => 'parts.id is an autoincrement integer; parts.sku is nullable but unique and is already searchable in admin. parts.external_id can store the source key as an additional guard.',
            'target_parts_state' => ['status' => 'draft', 'needs_listing' => true, 'is_visible_storefront' => false, 'source_system' => 'jarek'],
            'safety' => ['no_ovoko_write', 'no_allegro_main_write', 'no_ebay_live_write', 'no_publish_relist_end_update'],
            'missing_tables' => $missingTables,
            'parts_columns' => $partsColumns,
            'jarek_gearboxes_columns' => $jarekColumns,
            'missing_required_part_columns' => $missingPartColumns,
            'total' => 0,
            'eligible_to_create' => 0,
            'potential_duplicates' => 0,
            'blocked_by_reason' => array_fill_keys(['missing_title', 'missing_price', 'missing_images', 'missing_quantity', 'duplicate_sku', 'invalid_status', 'missing_required_part_field'], 0),
            'sample_to_create' => [],
            'sample_blocked' => [],
        ];

        if ($missingTables !== []) {
            $base['blocked_by_reason']['missing_required_part_field'] = 1;
            return $base;
        }

        $base['total'] = DB::table('jarek_gearboxes')->count();
        $rows = DB::table('jarek_gearboxes')->orderBy('id')->get();
        $allowedStatuses = ['ACTIVE', 'ENDED', 'INACTIVE', 'ACTIVATING'];

        foreach ($rows as $row) {
            $sku = filled($row->allegro_offer_id ?? null) ? 'JAREK-'.$row->allegro_offer_id : null;
            $reasons = [];
            if (! filled($row->title ?? null)) $reasons[] = 'missing_title';
            if (! is_numeric($row->price ?? null) || (float) $row->price <= 0) $reasons[] = 'missing_price';
            if (! $this->jarekRowHasImages($row)) $reasons[] = 'missing_images';
            if (! is_numeric($row->quantity ?? null) || (int) $row->quantity < 1) $reasons[] = 'missing_quantity';
            if (! filled($row->allegro_status ?? null) || ! in_array(strtoupper((string) $row->allegro_status), $allowedStatuses, true)) $reasons[] = 'invalid_status';
            if ($missingPartColumns !== []) $reasons[] = 'missing_required_part_field';
            if ($sku === null || $this->partDuplicateExists($sku, (string) ($row->allegro_offer_id ?? ''))) $reasons[] = 'duplicate_sku';

            $item = [
                'jarek_gearbox_id' => $row->id,
                'allegro_offer_id' => $row->allegro_offer_id,
                'sku' => $sku,
                'title' => $row->title,
                'price' => $row->price,
                'currency' => $row->currency,
                'quantity' => $row->quantity,
                'allegro_status' => $row->allegro_status,
                'category_id' => $row->category_id,
                'category_name' => $row->category_name,
            ];

            if ($reasons === []) {
                $base['eligible_to_create']++;
                if (count($base['sample_to_create']) < $limit && $base['eligible_to_create'] > $offset) $base['sample_to_create'][] = $item;
            } else {
                $uniqueReasons = array_values(array_unique($reasons));
                if (in_array('duplicate_sku', $uniqueReasons, true)) $base['potential_duplicates']++;
                foreach ($uniqueReasons as $reason) $base['blocked_by_reason'][$reason]++;
                if (count($base['sample_blocked']) < 20) $base['sample_blocked'][] = $item + ['reasons' => $uniqueReasons];
            }
        }

        return $base;
    }


    /**
     * @return array<string, mixed>
     */
    private function applyPartsImport(int $limit, int $offset): array
    {
        $created = [];
        $skipped = [];
        $duplicates = [];
        $seenEligible = 0;

        $rows = DB::table('jarek_gearboxes')->orderBy('id')->get();

        foreach ($rows as $row) {
            $sku = filled($row->allegro_offer_id ?? null) ? 'JAREK-'.$row->allegro_offer_id : null;
            $offerId = (string) ($row->allegro_offer_id ?? '');

            if ($sku === null || $offerId === '') {
                $skipped[] = ['source_jarek_gearbox_id' => $row->id, 'reason' => 'missing_allegro_offer_id'];
                continue;
            }

            if ($this->partDuplicateExists($sku, $offerId)) {
                $duplicates[] = ['source_jarek_gearbox_id' => $row->id, 'sku' => $sku, 'external_id' => $offerId];
                continue;
            }

            $seenEligible++;
            if ($seenEligible <= $offset) {
                $skipped[] = ['source_jarek_gearbox_id' => $row->id, 'sku' => $sku, 'reason' => 'offset'];
                continue;
            }

            if (count($created) >= $limit) {
                break;
            }

            $created[] = DB::transaction(function () use ($row, $sku, $offerId): array {
                $category = $this->safeCategoryMatch($row);
                $part = Part::query()->create(array_filter([
                    'source_system' => 'jarek',
                    'external_id' => $offerId,
                    'sku' => $sku,
                    'name' => (string) $row->title,
                    'description' => $row->description ?: $row->plain_description,
                    'short_description' => $row->plain_description,
                    'price' => $row->price,
                    'currency' => $row->currency ?: 'PLN',
                    'quantity' => (int) $row->quantity,
                    'status' => 'draft',
                    'needs_listing' => true,
                    'is_visible_storefront' => false,
                    'category_id' => $category?->id,
                    'suggested_category_id' => $category?->id,
                    'category_confidence' => $category ? 0.80 : null,
                    'category_suggestion_reason' => $category ? 'Bezpieczne dopasowanie lokalnej kategorii na podstawie kategorii Skrzyń Jarka.' : null,
                    'category_needs_review' => $category !== null,
                    'internal_note' => 'Rekord pochodzi ze Skrzyń Jarka. Pierwotny status Allegro Jarka: '.($row->allegro_status ?: 'brak').'. Źródłowy jarek_gearboxes.id: '.$row->id.'.',
                    'legacy_url' => $row->allegro_offer_url,
                    'legacy_payload' => $this->jarekLegacyPayload($row),
                ], fn ($value): bool => $value !== null));

                foreach ($this->jarekImageUrls($row) as $index => $url) {
                    PartImage::query()->create([
                        'source_system' => 'jarek',
                        'external_id' => $offerId.':'.$index,
                        'part_id' => $part->id,
                        'path' => $url,
                        'alt_text' => (string) $row->title,
                        'sort_order' => $index,
                        'is_primary' => $index === 0,
                        'legacy_payload' => ['source' => 'jarek_gearboxes', 'jarek_gearbox_id' => $row->id, 'marketplace_write' => false],
                    ]);
                }

                return ['created_part_id' => $part->id, 'created_sku' => $part->sku, 'source_jarek_gearbox_id' => $row->id];
            });
        }

        return [
            'ok' => true,
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'duplicate_count' => count($duplicates),
            'created' => $created,
            'duplicates' => $duplicates,
            'skipped' => $skipped,
            'marketplace_write' => false,
        ];
    }

    private function safeCategoryMatch(object $row): ?PartCategory
    {
        if (! Schema::hasTable('part_categories')) return null;
        $name = trim((string) ($row->category_name ?? ''));
        if ($name === '') return null;

        $matches = PartCategory::query()->where('name', $name)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return array<int, string> */
    private function jarekImageUrls(object $row): array
    {
        $images = [];
        if (filled($row->main_image_url ?? null)) $images[] = trim((string) $row->main_image_url);
        $decoded = json_decode((string) ($row->images ?? ''), true);
        if (is_array($decoded)) {
            foreach ($decoded as $image) {
                $url = is_array($image) ? ($image['url'] ?? null) : $image;
                if (filled($url)) $images[] = trim((string) $url);
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    /** @return array<string, mixed> */
    private function jarekLegacyPayload(object $row): array
    {
        return [
            'source' => 'jarek_gearboxes',
            'source_note' => 'Rekord pochodzi ze Skrzyń Jarka.',
            'jarek_gearbox_id' => $row->id,
            'allegro_offer_id' => $row->allegro_offer_id,
            'allegro_status_original' => $row->allegro_status,
            'allegro_offer_url' => $row->allegro_offer_url,
            'category_id' => $row->category_id,
            'category_name' => $row->category_name,
            'category_path' => $row->category_path,
            'marketplace_write' => false,
        ];
    }

    private function logPartsImportApply(string $status, string $message, array $payload): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'admin',
            'action' => 'jarek_gearboxes_parts_import_apply',
            'status' => $status,
            'message' => $message,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    private function jarekRowHasImages(object $row): bool
    {
        if (filled($row->main_image_url ?? null)) return true;
        $images = json_decode((string) ($row->images ?? ''), true);
        return is_array($images) && count(array_filter($images)) > 0;
    }

    private function partDuplicateExists(?string $sku, string $offerId): bool
    {
        if (! $sku) {
            return true;
        }

        return Part::query()
            ->where('sku', $sku)
            ->when(
                $offerId !== '' && Schema::hasColumn('parts', 'source_system') && Schema::hasColumn('parts', 'external_id'),
                fn ($query) => $query->orWhere(fn ($query) => $query->where('source_system', 'jarek')->where('external_id', $offerId)),
            )
            ->exists();
    }

    private function missingExpectedColumns(): array
    {
        $table = 'jarek_gearboxes';

        if (! Schema::hasTable($table)) {
            return $this->expectedColumns();
        }

        return array_values(array_filter(
            $this->expectedColumns(),
            fn (string $column): bool => ! Schema::hasColumn($table, $column),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function expectedColumns(): array
    {
        return [
            'id',
            'source_account',
            'allegro_account',
            'allegro_offer_id',
            'allegro_offer_url',
            'title',
            'description',
            'plain_description',
            'price',
            'currency',
            'quantity',
            'allegro_status',
            'main_image_url',
            'images',
            'category_id',
            'category_name',
            'category_path',
            'category_payload',
            'parameters',
            'raw_payload',
            'import_status',
            'imported_at',
            'updated_from_allegro_at',
            'ebay_status',
            'ebay_listing_id',
            'ebay_offer_id',
            'ebay_inventory_sku',
            'ebay_payload_snapshot',
            'ebay_published_at',
            'created_at',
            'updated_at',
        ];
    }

    private function migrationEntryExists(): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        return DB::table('migrations')
            ->where('migration', '2026_07_02_100000_create_jarek_gearboxes_table')
            ->exists();
    }

    private function limit(Request $request): int
    {
        return max(1, (int) $request->query('limit', 20));
    }

    private function offset(Request $request): int
    {
        return max(0, (int) $request->query('offset', 0));
    }
}
