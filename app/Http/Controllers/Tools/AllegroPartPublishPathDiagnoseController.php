<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\AllegroCategoryConsistencyGuard;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllegroPartPublishPathDiagnoseController extends Controller
{
    public function __invoke(Request $request, MarketplaceListingReadinessService $readinessService, AllegroCategoryConsistencyGuard $guard): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        $part = Part::query()->with(['category', 'images', 'marketplaceListings', 'car'])->find($partId);
        if (! $part) return response()->json(['ok' => false, 'error' => 'part_not_found', 'part_id' => $partId], 404);

        $readiness = $readinessService->checkPartReadiness($part, 'allegro_main');
        $payload = (array) ($readiness['prepared_payload_preview_safe'] ?? []);
        $diagnostics = $guard->diagnose($part, $payload, null);

        return response()->json([
            'ok' => true,
            'read_only' => true,
            'will_make_marketplace_request' => false,
            'part_id' => $part->id,
            'part_name' => $part->name,
            'channel' => 'allegro_main',
            'action' => 'createProductOffer',
            'readiness_blockers' => $readiness['blockers'] ?? [],
            'diagnostics' => $diagnostics,
        ] + $diagnostics);
    }
}
