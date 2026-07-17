<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\AllegroListingDiagnosisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllegroListingReadOnlyStatusController extends Controller
{
    public function __invoke(Request $request, AllegroListingDiagnosisService $diagnostics): JsonResponse
    {
        $partId = $request->integer('part_id');
        abort_unless($partId > 0, 422, 'part_id is required.');

        return response()->json($diagnostics->diagnosePart(
            $partId,
            $request->filled('listing_id') ? $request->integer('listing_id') : null,
            $request->filled('offer_id') ? (string) $request->query('offer_id') : null,
        ));
    }
}
