<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\PurgeAllegroGearboxesService;
use Illuminate\Http\Request;

class PurgeAllegroGearboxesDryRunController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request, PurgeAllegroGearboxesService $service)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        return response()->json($service->dryRun());
    }
}
