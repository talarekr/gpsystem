<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CheckOvokoApiSettingsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'error_message' => 'Invalid diagnostics token.',
            ], 403);
        }

        $account = Schema::hasTable('marketplace_accounts')
            ? MarketplaceAccount::query()->where('code', 'ovoko_main')->first()
            : null;

        $credentials = $account?->api_credentials ?? [];
        $credentialsConfigured = filled($credentials['username'] ?? null)
            && filled($credentials['password'] ?? null)
            && filled($credentials['user_token'] ?? null);

        return response()->json([
            'account_exists' => $account !== null,
            'api_enabled' => (bool) ($account?->api_enabled ?? false),
            'api_base_url' => $account?->api_base_url,
            'api_mode' => $account?->api_mode,
            'username_configured' => filled($credentials['username'] ?? null),
            'password_configured' => filled($credentials['password'] ?? null),
            'user_token_configured' => filled($credentials['user_token'] ?? null),
            'credentials_configured' => $credentialsConfigured,
        ]);
    }
}
