<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvokoCarDictionariesDiagnoseController extends Controller
{
    public function __invoke(Request $request, OvokoCarDictionaryService $service): JsonResponse
    {
        return response()->json($service->diagnostics(
            $request->filled('brand_search') ? (string) $request->query('brand_search') : null,
            $request->filled('brand_id') ? (string) $request->query('brand_id') : null,
        ));
    }
}
