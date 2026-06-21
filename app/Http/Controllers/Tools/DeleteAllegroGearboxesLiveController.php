<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\DeleteAllegroGearboxesService;
use Illuminate\Http\Request;

class DeleteAllegroGearboxesLiveController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request, DeleteAllegroGearboxesService $service)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        try {
            return response()->json($service->live((string) $request->query('confirm', '')));
        } catch (\Throwable $exception) {
            return response()->json([
                'ok' => false,
                'dry_run' => false,
                'error_message' => $exception->getMessage(),
            ], 422);
        }
    }
}
