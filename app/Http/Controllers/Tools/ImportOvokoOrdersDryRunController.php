<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\OvokoOrdersImportDryRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportOvokoOrdersDryRunController extends Controller
{
    public function __invoke(Request $request, OvokoOrdersImportDryRunService $service): JsonResponse
    {
        if (! hash_equals(OvokoOrdersImportDryRunService::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', now()->toDateString());
        $dateError = $service->validateDates($from, $to);
        if ($dateError !== null) {
            return response()->json(['ok' => false, 'dry_run' => true, 'error_message' => $dateError], 422);
        }

        $result = $service->run($from, $to);
        $status = (int) ($result['http_response_code'] ?? 200);
        unset($result['http_response_code']);

        return response()->json($result, $status);
    }
}
