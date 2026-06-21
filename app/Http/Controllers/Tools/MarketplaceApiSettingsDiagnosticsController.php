<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class MarketplaceApiSettingsDiagnosticsController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function allegro(Request $request): JsonResponse { return $this->settings($request, ['allegro_main' => ['admin' => '/admin/allegro-settings', 'need_site' => false]]); }
    public function ebay(Request $request): JsonResponse { return $this->settings($request, ['ebay_de' => ['admin' => '/admin/ebay-settings', 'need_site' => true], 'ebay_fr' => ['admin' => '/admin/ebay-settings', 'need_site' => true]]); }
    public function testAllegro(Request $request): JsonResponse { return $this->test($request, ['allegro_main']); }
    public function testEbay(Request $request): JsonResponse { return $this->test($request, ['ebay_de', 'ebay_fr']); }

    private function settings(Request $request, array $definitions): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $payload = [];
        foreach ($definitions as $code => $definition) $payload[$code] = $this->accountPayload($code, $definition);
        return response()->json(count($payload) === 1 ? reset($payload) : $payload);
    }

    private function test(Request $request, array $codes): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $checkedAt = now();
        $payload = [];
        foreach ($codes as $code) {
            $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $code)->first() : null;
            $message = $this->readinessMessage($code, $account);
            if ($account) {
                $account->forceFill(['last_connection_check_at' => $checkedAt, 'last_connection_status' => 'not_ready', 'last_connection_message' => $message])->save();
                MarketplaceSyncLog::query()->create(['marketplace' => $account->marketplace, 'action' => 'api_connection_test', 'status' => 'not_ready', 'message' => $message, 'payload' => ['dry_run' => true, 'credentials_logged' => false], 'created_at' => $checkedAt]);
            }
            $payload[$code] = ['ok' => false, 'dry_run' => true, 'connection_ok' => false, 'status' => 'not_ready_for_connection_test', 'message' => $message, 'last_connection_check_at' => $checkedAt->toISOString()];
        }
        return response()->json(count($payload) === 1 ? reset($payload) : $payload, 422);
    }

    private function accountPayload(string $code, array $definition): array
    {
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $code)->first() : null;
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $credentialsConfigured = filled($credentials['client_id'] ?? null) && filled($credentials['client_secret'] ?? null) && filled($credentials['access_token'] ?? null) && filled($credentials['refresh_token'] ?? null);
        $payload = [
            'account_exists' => $account !== null,
            'api_enabled' => (bool) ($account?->api_enabled ?? false),
            'api_base_url' => $account?->api_base_url,
            'api_mode' => $account?->api_mode,
            'client_id_configured' => filled($credentials['client_id'] ?? null),
            'client_secret_configured' => filled($credentials['client_secret'] ?? null),
            'access_token_configured' => filled($credentials['access_token'] ?? null),
            'refresh_token_configured' => filled($credentials['refresh_token'] ?? null),
            'credentials_configured' => $credentialsConfigured,
            'last_connection_check_at' => $account?->last_connection_check_at?->toISOString(),
            'last_connection_status' => $account?->last_connection_status,
            'admin_url' => url($definition['admin']),
        ];
        if ($definition['need_site'] ?? false) $payload['marketplace_site_configured'] = filled($settings['marketplace_id'] ?? null) || filled($settings['site_id'] ?? null);
        return $payload;
    }

    private function readinessMessage(string $code, ?MarketplaceAccount $account): string
    {
        if (! $account) return "Marketplace account {$code} was not found.";
        if (! $account->api_enabled) return "API is not enabled for {$code}.";
        if (blank($account->api_base_url)) return "API base URL is missing for {$code}.";
        if ($account->api_mode !== 'dry_run') return "Connection test is allowed only in dry_run mode for {$code}.";
        return 'not_ready_for_connection_test: OAuth/token validation call is intentionally not enabled in this foundation step.';
    }

    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }
    private function invalidToken(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
}
