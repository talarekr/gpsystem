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
        return response()->json($service->diagnostics());
    }
}
