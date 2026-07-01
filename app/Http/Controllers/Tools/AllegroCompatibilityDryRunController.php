<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\AllegroCompatibilityMappingService;
use Illuminate\Http\JsonResponse;

class AllegroCompatibilityDryRunController extends Controller
{
    public function __invoke(Part $part, AllegroCompatibilityMappingService $service): JsonResponse
    {
        return response()->json($service->dryRun($part));
    }
}
