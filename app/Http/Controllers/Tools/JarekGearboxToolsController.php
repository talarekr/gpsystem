<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\JarekGearbox;
use App\Services\JarekGearboxes\JarekAllegroImportService;
use App\Services\JarekGearboxes\JarekGearboxEbayPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarekGearboxToolsController extends Controller
{
    public function dryRunImport(Request $request, JarekAllegroImportService $service): JsonResponse
    {
        $payload = $service->dryRun((int) $request->integer('limit', 20), (int) $request->integer('page', 1), $request->query('status'));
        return response()->json($payload, ($payload['blockers'] ?? []) === [] ? 200 : 422);
    }

    public function applyImport(Request $request, JarekAllegroImportService $service): JsonResponse
    {
        $payload = $service->apply((int) $request->integer('limit', 20), (int) $request->integer('page', 1), $request->query('status'), $request->query('confirm'));
        return response()->json($payload, ($payload['ok'] ?? false) ? 200 : 422);
    }

    public function ebayPreview(JarekGearbox $gearbox, Request $request, JarekGearboxEbayPreviewService $service): JsonResponse
    {
        $payload = $service->preview($gearbox, (string) $request->query('channel', 'ebay_de'));
        return response()->json($payload, ($payload['blockers'] ?? []) === [] ? 200 : 422);
    }
}
