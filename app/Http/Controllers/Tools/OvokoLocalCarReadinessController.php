<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvokoLocalCarReadinessController extends Controller
{
    public function __invoke(Request $request, OvokoCarDictionaryService $service): JsonResponse
    {
        $car = Car::query()->findOrFail((int) $request->query('car_id'));
        return response()->json($service->readiness($car));
    }
}
