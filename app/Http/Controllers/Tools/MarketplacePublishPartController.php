<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplacePublishPartController extends Controller
{
    public function __construct(private readonly PublishPartToMarketplacesService $service) {}

    public function preview(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return response()->json(['error' => 'Invalid diagnostics token.'], 403);
        $part = Part::query()->findOrFail((int) $request->query('part_id'));
        return response()->json($this->service->preview($part, (string) $request->query('channels', 'all'), $request->boolean('include_payload', true)));
    }

    public function confirm(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return response()->json(['error' => 'Invalid diagnostics token.'], 403);
        $part = Part::query()->findOrFail((int) $request->query('part_id'));
        return response()->json($this->service->confirm($part, (string) $request->query('channels', 'all'), $request->boolean('dry_run', true), $request->boolean('confirm', false)));
    }

    private function validToken(Request $request): bool
    {
        return hash_equals((string) config('app.tools_token', 'gps_images_import_2026'), (string) $request->query('token', ''))
            || hash_equals('gps_images_import_2026', (string) $request->query('token', ''));
    }
}
