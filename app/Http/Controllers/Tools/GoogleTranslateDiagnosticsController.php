<?php

namespace App\Http\Controllers\Tools;

use App\Services\Marketplace\GoogleTranslateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleTranslateDiagnosticsController
{
    private const TOKEN = 'gps_images_import_2026';

    public function readiness(Request $request, GoogleTranslateService $service): JsonResponse
    {
        if (! $this->validToken($request)) {
            return $this->invalidToken();
        }

        return response()->json($service->readiness());
    }

    public function test(Request $request, GoogleTranslateService $service): JsonResponse
    {
        if (! $this->validToken($request)) {
            return $this->invalidToken();
        }

        return response()->json($service->translate(
            (string) $request->query('text', 'Oryginalna używana część samochodowa'),
            (string) $request->query('target', 'fr'),
            (string) $request->query('source', 'pl')
        ));
    }

    public function dryRunProduct(Request $request, GoogleTranslateService $service): JsonResponse
    {
        if (! $this->validToken($request)) {
            return $this->invalidToken();
        }

        return response()->json($service->dryRunProduct(
            (int) $request->query('part_id', 0),
            (string) $request->query('target', 'fr'),
            (string) $request->query('source', 'pl')
        ));
    }

    private function validToken(Request $request): bool
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', ''));
    }

    private function invalidToken(): JsonResponse
    {
        return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }
}
