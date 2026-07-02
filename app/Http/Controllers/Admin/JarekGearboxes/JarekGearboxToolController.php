<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Http\Controllers\Controller;
use App\Models\JarekGearbox;
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
        return response()->json($service->dryRun($this->limit($request), (int) $request->query('offset', 0)));
    }

    public function apply(Request $request, AllegroJarekImportService $service): JsonResponse
    {
        if ($request->query('confirm') !== 'jarek-gearboxes-import') {
            return response()->json(['ok' => false, 'error' => 'Missing confirm=jarek-gearboxes-import', 'marketplace_write' => false], 422);
        }

        return response()->json(['ok' => true] + $service->apply($this->limit($request), (int) $request->query('offset', 0)));
    }

    public function ebayPreview(JarekGearbox $jarekGearbox, JarekGearboxEbayPreviewService $service): JsonResponse
    {
        return response()->json($service->build($jarekGearbox));
    }

    /**
     * @return array<int, string>
     */
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
}
