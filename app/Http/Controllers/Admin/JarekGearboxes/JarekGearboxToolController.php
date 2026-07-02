<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Http\Controllers\Controller;
use App\Models\JarekGearbox;
use App\Services\JarekGearboxes\AllegroJarekImportService;
use App\Services\JarekGearboxes\JarekGearboxEbayPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    private function limit(Request $request): int
    {
        return max(1, min((int) $request->query('limit', 20), 100));
    }
}
