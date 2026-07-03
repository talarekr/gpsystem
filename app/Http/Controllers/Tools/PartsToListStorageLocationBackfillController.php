<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\CsvUnavailableException;
use App\Services\Tools\PartsToListStorageLocationBackfillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartsToListStorageLocationBackfillController extends Controller
{
    public function dryRun(Request $request, PartsToListStorageLocationBackfillService $service): JsonResponse
    {
        try {
            return response()->json($service->dryRun($this->limit($request, 100)));
        } catch (CsvUnavailableException $e) {
            return response()->json($this->csvUnavailableResponse($e, true), $e->statusCode);
        }
    }

    public function apply(Request $request, PartsToListStorageLocationBackfillService $service): JsonResponse
    {
        try {
            return response()->json($service->apply($this->limit($request, 10), $request->query('confirm') === PartsToListStorageLocationBackfillService::CONFIRM));
        } catch (CsvUnavailableException $e) {
            return response()->json($this->csvUnavailableResponse($e, false), $e->statusCode);
        }
    }

    public function results(Request $request, PartsToListStorageLocationBackfillService $service): JsonResponse
    {
        return response()->json($service->latestResults($this->limit($request, 20)));
    }

    private function csvUnavailableResponse(CsvUnavailableException $e, bool $dryRun): array
    {
        return [
            'ok' => false,
            'message' => $e->getMessage(),
            'stage' => $e->stage,
            'correlation_id' => $e->correlationId,
            'diagnostics' => ['csv' => $e->diagnostics],
            'dry_run' => $dryRun,
            'local_update' => false,
            'marketplace_write' => false,
        ];
    }

    private function limit(Request $request, int $default): int
    {
        return max(1, min(1000, (int) $request->query('limit', $default)));
    }
}
