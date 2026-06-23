<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayListingDryRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EbayListingDryRunController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __construct(private readonly EbayListingDryRunService $service) {}

    public function readiness(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $payload = $this->service->readiness((int) $request->integer('part_id'), (string) $request->query('channel', 'ebay_de'));
        return response()->json($payload, ($payload['ready'] ?? false) ? 200 : 422);
    }

    public function dryRunPayload(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $payload = $this->service->dryRunPayload((int) $request->integer('part_id'), (string) $request->query('channel', 'ebay_de'));
        return response()->json($payload, ($payload['blockers'] ?? []) === [] ? 200 : 422);
    }

    public function readinessAll(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $payload = $this->service->readinessAll((int) $request->integer('part_id'));
        return response()->json($payload, ($payload['overall_ready'] ?? false) ? 200 : 422);
    }

    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }
    private function invalidToken(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
}
