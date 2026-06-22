<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayCategoryShippingPolicyCsvImportService;
use Illuminate\Http\Request;

class EbayCategoryShippingPolicyCsvImportController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __construct(private readonly EbayCategoryShippingPolicyCsvImportService $service) {}

    public function dryRun(Request $request)
    {
        if (! $this->authorized($request)) return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        return response()->json($this->service->dryRun());
    }

    public function live(Request $request)
    {
        if (! $this->authorized($request)) return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'mode' => 'live', 'blockers' => ['confirm=1 is required for live import.']], 422);
        return response()->json($this->service->live());
    }

    public function coverage(Request $request)
    {
        if (! $this->authorized($request)) return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        return response()->json($this->service->coverage());
    }

    private function authorized(Request $request): bool
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', ''));
    }
}
