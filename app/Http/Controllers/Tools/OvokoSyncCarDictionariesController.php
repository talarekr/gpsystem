<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvokoSyncCarDictionariesController extends Controller
{
    public function __invoke(Request $request, OvokoCarDictionaryService $service): JsonResponse
    {
        if ($request->input('confirm') !== OvokoCarDictionaryService::CONFIRM) {
            return response()->json(['ok' => false, 'blocked' => true, 'reason' => 'missing_confirm_token', 'expected_confirm' => OvokoCarDictionaryService::CONFIRM], 422);
        }

        $scope = (string) $request->input('scope', 'all');
        if ($scope === 'models' && ! $request->filled('brand_id')) {
            return response()->json([
                'ok' => false,
                'blocked' => true,
                'reason' => 'models_full_sync_requires_runner',
                'message' => 'Pełny sync modeli Ovoko uruchom przez /admin/tools/ovoko/car-models-sync-runner/start.',
                'runner_endpoint' => '/admin/tools/ovoko/car-models-sync-runner/start',
            ], 422);
        }

        return response()->json(['ok' => true] + $service->sync($scope, $request->filled('brand_id') ? (string) $request->input('brand_id') : null));
    }
}
