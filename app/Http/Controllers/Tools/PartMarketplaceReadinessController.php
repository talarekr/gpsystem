<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PartMarketplaceReadinessController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __construct(private readonly MarketplaceListingReadinessService $readinessService) {}

    public function check(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        try {
            $part = Part::query()->find((int) $request->query('part_id'));

            if (! $part) {
                return response()->json([
                    'ok' => false,
                    'blocker' => 'part_not_found',
                    'blockers' => ['part_not_found'],
                ], 404);
            }

            $result = $this->readinessService->checkAll($part);

            return response()->json(['ok' => true, 'part_id' => $part->id, 'part_name' => $part->name] + $result);
        } catch (\Throwable $e) {
            return $this->safeExceptionResponse($e, (int) $request->query('part_id'));
        }
    }

    public function payload(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        try {
            $part = Part::query()->find((int) $request->query('part_id'));

            if (! $part) {
                return response()->json([
                    'ok' => false,
                    'blocker' => 'part_not_found',
                    'blockers' => ['part_not_found'],
                ], 404);
            }

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
        } catch (\Throwable $e) {
            return $this->safeExceptionResponse($e, (int) $request->query('part_id'));
        }
    }

    private function validToken(Request $request): bool
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', ''));
    }

    private function invalidTokenResponse(): JsonResponse
    {
        return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }

    private function safeExceptionResponse(\Throwable $e, int $partId): JsonResponse
    {
        Log::warning('Part marketplace readiness diagnostics failed.', [
            'part_id' => $partId,
            'exception' => $e::class,
        ]);

        return response()->json([
            'ok' => false,
            'error_message_safe' => 'Marketplace readiness diagnostics could not be completed safely.',
            'blockers' => ['readiness_diagnostics_exception'],
        ], 200);
    }
}
