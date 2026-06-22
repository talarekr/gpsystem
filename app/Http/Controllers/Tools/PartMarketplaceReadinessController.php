<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartMarketplaceReadinessController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __construct(private readonly MarketplaceListingReadinessService $readinessService) {}

    public function check(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $part = Part::query()->findOrFail((int) $request->query('part_id'));
        $result = $this->readinessService->checkAll($part);

        return response()->json(['ok' => true, 'part_id' => $part->id, 'part_name' => $part->name] + $result);
    }

    public function payload(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $part = Part::query()->findOrFail((int) $request->query('part_id'));
        $channel = (string) $request->query('channel', 'allegro_main');
        $readiness = $this->readinessService->checkPartReadiness($part, $channel);

        return response()->json([
            'ok' => true,
            'part_id' => $part->id,
            'part_name' => $part->name,
            'channel' => $readiness['channel'],
            'payload_preview_safe' => $readiness['prepared_payload_preview_safe'],
            'readiness' => $readiness,
        ]);
    }

    private function validToken(Request $request): bool
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', ''));
    }

    private function invalidTokenResponse(): JsonResponse
    {
        return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }
}
