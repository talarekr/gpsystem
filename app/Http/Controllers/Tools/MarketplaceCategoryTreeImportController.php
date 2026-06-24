<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceCategoryTreeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceCategoryTreeImportController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function dryRunImport(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->previewOrImport(false));
    }

    public function import(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing local import without confirm=1.', 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false], 422);
        return response()->json($service->previewOrImport(true));
    }

    public function dryRunBackfill(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->backfillEbayDe(false));
    }

    public function backfill(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing local backfill without confirm=1.', 'local_update' => false], 422);
        return response()->json($service->backfillEbayDe(true));
    }

    private function denyBadToken(Request $request): ?JsonResponse
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', '')) ? null : response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }
}
