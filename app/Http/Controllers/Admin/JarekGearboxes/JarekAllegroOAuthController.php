<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceSyncLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class JarekAllegroOAuthController extends Controller
{
    private const SCOPES = 'allegro:api:sale:offers:read allegro:api:sale:settings:read';
    private const REDIRECT_URI = 'https://gpswiss.pl/admin/tools/jarek-gearboxes/allegro-oauth/callback';

    public function start(Request $request)
    {
        $clientId = config('services.allegro_jarek.client_id');
        if (blank($clientId)) throw new RuntimeException('Missing .env/config key for Allegro Jarek: ALLEGRO_JAREK_CLIENT_ID');
        $state = Str::random(40);
        $request->session()->put('jarek_allegro_oauth_state', $state);

        return redirect()->away('https://allegro.pl/auth/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT_URI, 'scope' => self::SCOPES, 'state' => $state,
        ]));
    }

    public function callback(Request $request)
    {
        $sessionState = (string) $request->session()->get('jarek_allegro_oauth_state', '');
        $requestState = (string) $request->query('state', '');
        $stateMatches = $sessionState !== '' && $requestState !== '' && hash_equals($sessionState, $requestState);

        if (! $stateMatches) {
            $reason = $sessionState === ''
                ? 'missing_session_state'
                : ($requestState === '' ? 'missing_request_state' : 'state_mismatch');

            $this->logOAuthCallback($request, $stateMatches, $reason);

            abort(403, 'Allegro Jarek OAuth callback rejected: '.$reason);
        }

        $request->session()->forget('jarek_allegro_oauth_state');

        foreach (['client_id' => 'ALLEGRO_JAREK_CLIENT_ID', 'client_secret' => 'ALLEGRO_JAREK_CLIENT_SECRET'] as $key => $env) {
            if (blank(config("services.allegro_jarek.{$key}"))) {
                $this->logOAuthCallback($request, $stateMatches, 'missing_config_'.$key);

                throw new RuntimeException('Missing .env/config key for Allegro Jarek: '.$env);
            }
        }

        if (! $request->filled('code')) {
            $this->logOAuthCallback($request, $stateMatches, 'missing_code');

            abort(403, 'Allegro Jarek OAuth callback rejected: missing_code');
        }

        $response = Http::asForm()->withBasicAuth(config('services.allegro_jarek.client_id'), config('services.allegro_jarek.client_secret'))
            ->post('https://allegro.pl/auth/oauth/token', ['grant_type' => 'authorization_code', 'code' => $request->query('code'), 'redirect_uri' => self::REDIRECT_URI]);

        if (! $response->successful()) {
            $this->logOAuthCallback($request, $stateMatches, 'token_exchange_failed', $response->status());

            abort(502, 'Allegro Jarek OAuth token exchange failed.');
        }

        $this->logOAuthCallback($request, $stateMatches, 'success', $response->status());

        $json = $response->json();

        return response("ALLEGRO_JAREK_ACCESS_TOKEN={$json['access_token']}\nALLEGRO_JAREK_REFRESH_TOKEN=".($json['refresh_token'] ?? '')."\n\nPo wklejeniu do .env uruchom: php artisan config:clear oraz na produkcji (jeśli używa cache): php artisan config:cache\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache',
        ]);
    }

    private function logOAuthCallback(Request $request, bool $stateMatches, string $reason, ?int $httpStatus = null): void
    {
        $payload = [
            'route' => optional($request->route())->getName() ?? $request->path(),
            'user_id' => optional($request->user())->id,
            'has_code' => $request->filled('code'),
            'has_state' => $request->filled('state'),
            'session_has_state' => $request->session()->has('jarek_allegro_oauth_state'),
            'state_matches' => $stateMatches,
            'reason_for_403' => $reason === 'success' || $reason === 'token_exchange_failed' ? null : $reason,
            'secrets_logged' => false,
        ];

        if (Schema::hasTable('marketplace_sync_logs')) {
            MarketplaceSyncLog::query()->create([
                'marketplace' => 'allegro_jarek',
                'action' => 'jarek_allegro_oauth_callback',
                'status' => $reason === 'success' ? 'success' : 'error',
                'http_status' => $httpStatus,
                'message' => 'Jarek Allegro OAuth callback: '.$reason,
                'payload' => $payload,
                'created_at' => now(),
            ]);

            return;
        }

        Log::channel(config('logging.default'))->info('Jarek Allegro OAuth callback', $payload + [
            'status' => $reason === 'success' ? 'success' : 'error',
            'http_status' => $httpStatus,
        ]);
    }
}
