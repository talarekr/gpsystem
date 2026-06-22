<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class MarketplaceApiSettingsDiagnosticsController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function allegro(Request $request): JsonResponse { return $this->settings($request, ['allegro_main' => ['admin' => '/admin/allegro-settings', 'need_site' => false]]); }
    public function ebay(Request $request): JsonResponse { return $this->settings($request, ['ebay_de' => ['admin' => '/admin/ebay-settings', 'need_site' => true], 'ebay_fr' => ['admin' => '/admin/ebay-settings', 'need_site' => true]]); }
    public function testAllegro(Request $request): JsonResponse { return $this->test($request, ['allegro_main']); }
    public function testEbay(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $channel = (string) $request->query('channel', 'ebay_de');
        if (! in_array($channel, ['ebay_de', 'ebay_fr'], true)) return response()->json(['ok' => false, 'error_message' => 'Invalid eBay channel.'], 422);
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $channel)->first() : null;
        $client = new EbayApiClient($channel, $account);
        $readiness = $client->getAccountReadiness();
        if ($readiness['blockers'] !== []) return response()->json(['ok' => false, 'channel' => $channel, 'blockers' => $readiness['blockers']], 422);
        try {
            $payload = $client->readOnlyDiagnostics();
            return response()->json($payload, $payload['ok'] ? 200 : 422);
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'channel' => $channel, 'error_message_safe' => 'Read-only eBay API diagnostics failed without exposing credentials.'], 422);
        }
    }


    public function checkEbayBusinessPolicies(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();

        $payload = [];
        foreach (['ebay_de', 'ebay_fr'] as $channel) {
            $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $channel)->first() : null;
            $client = new EbayApiClient($channel, $account);

            try {
                $payload[$channel] = $client->businessPoliciesDiagnostics();
            } catch (\Throwable) {
                $settings = is_array($account?->api_settings) ? $account->api_settings : [];
                $payload[$channel] = [
                    'ok' => false,
                    'channel' => $channel,
                    'marketplace_id' => $settings['marketplace_id'] ?? null,
                    'api_mode' => $account?->api_mode,
                    'fulfillment_policies_count' => 0,
                    'payment_policies_count' => 0,
                    'return_policies_count' => 0,
                    'fulfillment_policies' => [],
                    'payment_policies' => [],
                    'return_policies' => [],
                    'blockers' => ['Read-only eBay business policies diagnostics failed without exposing credentials.'],
                    'warnings' => [],
                    'read_only' => true,
                ];
            }
        }

        return response()->json($payload, collect($payload)->every(fn (array $row) => (bool) ($row['ok'] ?? false)) ? 200 : 422);
    }

    public function ebayReadiness(Request $request): JsonResponse { return $this->settings($request, ['ebay_de' => ['admin' => '/admin/ebay-settings', 'need_site' => true], 'ebay_fr' => ['admin' => '/admin/ebay-settings', 'need_site' => true]]); }

    public function ebayOAuthRoutes(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();

        $redirectExists = Route::has('admin.ebay.oauth.redirect');
        $callbackExists = Route::has('admin.ebay.oauth.callback');
        $blockers = [];
        $warnings = [];

        if (! $redirectExists) $blockers[] = 'eBay OAuth redirect route is not registered.';
        if (! $callbackExists) $blockers[] = 'eBay OAuth callback route is not registered.';

        $expectedCallbackUrl = 'https://gpswiss.pl/admin/ebay/oauth/callback';
        $callbackUrl = $callbackExists ? route('admin.ebay.oauth.callback') : null;

        if ($callbackUrl !== null && $callbackUrl !== $expectedCallbackUrl) {
            $warnings[] = 'Generated callback URL differs from the expected production eBay Developer URL.';
        }

        return response()->json([
            'ok' => $blockers === [],
            'redirect_route_exists' => $redirectExists,
            'callback_route_exists' => $callbackExists,
            'callback_url' => $callbackUrl,
            'expected_accepted_url_for_ebay_developer' => $expectedCallbackUrl,
            'expected_declined_url_for_ebay_developer' => $expectedCallbackUrl,
            'redirect_url_ebay_de' => $redirectExists ? route('admin.ebay.oauth.redirect', ['channel' => 'ebay_de']) : null,
            'redirect_url_ebay_fr' => $redirectExists ? route('admin.ebay.oauth.redirect', ['channel' => 'ebay_fr']) : null,
            'admin_ebay_settings_url' => url('/admin/ebay-settings'),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ], $blockers === [] ? 200 : 500);
    }

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
        $credentialsConfigured = filled($credentials['client_id'] ?? null) && filled($credentials['client_secret'] ?? null) && filled($credentials['dev_id'] ?? null) && filled($credentials['ru_name'] ?? null) && filled($credentials['refresh_token'] ?? null);
        $payload = [
            'account_exists' => $account !== null,
            'api_enabled' => (bool) ($account?->api_enabled ?? false),
            'api_base_url' => $account?->api_base_url,
            'api_mode' => $account?->api_mode,
            'marketplace_id' => $settings['marketplace_id'] ?? null,
            'site_id' => $settings['site_id'] ?? null,
            'client_id_configured' => filled($credentials['client_id'] ?? null),
            'client_secret_configured' => filled($credentials['client_secret'] ?? null),
            'dev_id_configured' => filled($credentials['dev_id'] ?? null),
            'runame_configured' => filled($credentials['ru_name'] ?? null),
            'access_token_configured' => filled($credentials['access_token'] ?? null),
            'refresh_token_configured' => filled($credentials['refresh_token'] ?? null),
            'expires_at' => $credentials['expires_at'] ?? null,
            'credentials_configured' => $credentialsConfigured,
            'last_connection_check_at' => $account?->last_connection_check_at?->toISOString(),
            'last_connection_status' => $account?->last_connection_status,
            'admin_url' => url($definition['admin']),
        ];
        $payload['blockers'] = [];
        $payload['warnings'] = [];
        foreach (['api_enabled' => 'API is disabled', 'api_base_url' => 'API base URL missing', 'marketplace_id' => 'Marketplace ID missing', 'site_id' => 'Site ID missing', 'client_id_configured' => 'Client ID missing', 'client_secret_configured' => 'Client secret missing', 'dev_id_configured' => 'Dev ID missing', 'runame_configured' => 'RuName missing', 'refresh_token_configured' => 'OAuth refresh token missing'] as $key => $message) {
            if (empty($payload[$key])) $payload['blockers'][] = $message;
        }
        if (empty($payload['access_token_configured'])) $payload['warnings'][] = 'Access token missing or not connected yet.';
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
