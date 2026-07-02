<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Tools\AllegroDuplicateListingDiagnosticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllegroDuplicateCheckController extends Controller
{
    public function __invoke(Request $request, AllegroDuplicateListingDiagnosticsService $diagnostics): JsonResponse
    {
        $partId = $request->filled('part_id') ? $request->integer('part_id') : null;

        return response()->json($diagnostics->report($partId));
    }
}
