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
            $request->string('brand_search')->toString(),
            $request->string('brand_id')->toString(),
            $request->integer('models_limit', 5),
            $request->boolean('include_raw'),
            $request->boolean('include_model_groups'),
            $request->string('model_group_search')->toString(),
            $request->integer('model_groups_limit', $request->integer('models_limit', 5)),
            $request->string('dictionary')->toString(),
        ));
    }
}
