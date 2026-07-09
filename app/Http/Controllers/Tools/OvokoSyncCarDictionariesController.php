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

        return response()->json(['ok' => true] + $service->sync((string) $request->input('scope', 'all'), $request->filled('brand_id') ? (string) $request->input('brand_id') : null));
    }
}
