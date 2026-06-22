<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\AllegroProductMappingCandidatesService;
use Illuminate\Http\Request;

class ExportAllegroProductMappingCandidatesController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request, AllegroProductMappingCandidatesService $service)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        return $service->csvResponse(
            (int) $request->query('limit', 5000),
            (string) $request->query('status', 'ACTIVE'),
            (string) $request->query('include_existing', '1') !== '0',
        );
    }
}
