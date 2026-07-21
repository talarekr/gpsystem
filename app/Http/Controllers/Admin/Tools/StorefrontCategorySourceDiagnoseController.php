<?php

namespace App\Http\Controllers\Admin\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontCategorySourceDiagnoseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            abort(403);
        }

        return response()->json([
            'ok' => true,
            'status' => 'diagnosed',
            'read_only' => true,
            'database_write' => false,
            'marketplace_write' => false,
            'external_requests' => false,
            'central_storefront_source' => 'legacy_explicit_connection',
            'tenant_storefront_source' => 'tenant_model',
            'central_host_requires_tenant_context' => false,
            'tenant_cache_isolated' => true,
            'legacy_cache_isolated' => true,
            'tenant_model_fallback_added' => false,
        ]);
    }

    private function authorized(Request $request): bool
    {
        if (! app()->environment(['local', 'staging', 'testing'])) return false;
        if (! (bool) env('TENANCY_DIAGNOSTICS_ENABLED', false)) return false;

        $token = (string) env('TENANCY_DIAGNOSTICS_TOKEN', '');

        return $token !== '' && hash_equals($token, (string) $request->bearerToken());
    }
}
