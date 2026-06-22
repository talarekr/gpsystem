<?php

namespace App\Http\Controllers\Admin\Allegro;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Support\Marketplace\AllegroOAuthConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AllegroOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $account = MarketplaceAccount::query()->where('code', AllegroOAuthConfig::ACCOUNT_CODE)->first();
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];

        if (! $account || blank($credentials['client_id'] ?? null) || blank($credentials['client_secret'] ?? null)) {
            return redirect('/admin/allegro-settings')->with('error', 'Uzupełnij Client ID i Client secret dla Allegro przed połączeniem.');
        }

        $state = AllegroOAuthConfig::state();
        $request->session()->put('allegro_oauth_state', $state);

        $url = AllegroOAuthConfig::AUTHORIZATION_URL.'?'.Arr::query([
            'response_type' => 'code',
            'client_id' => (string) $credentials['client_id'],
            'redirect_uri' => AllegroOAuthConfig::REDIRECT_URI,
            'state' => $state,
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $expectedState = (string) $request->session()->pull('allegro_oauth_state', '');

        if ($state === '' || $expectedState === '' || ! hash_equals($expectedState, $state)) {
            return redirect('/admin/allegro-settings')->with('error', 'Nieprawidłowy state OAuth Allegro. Spróbuj ponownie.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect('/admin/allegro-settings')->with('error', 'Allegro nie zwróciło kodu autoryzacyjnego.');
        }

        $account = MarketplaceAccount::query()->where('code', AllegroOAuthConfig::ACCOUNT_CODE)->first();
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $clientId = (string) ($credentials['client_id'] ?? '');
        $clientSecret = (string) ($credentials['client_secret'] ?? '');

        if (! $account || $clientId === '' || $clientSecret === '') {
            return redirect('/admin/allegro-settings')->with('error', 'Brakuje Client ID lub Client secret dla Allegro.');
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->acceptJson()
                ->timeout(20)
                ->post(AllegroOAuthConfig::TOKEN_URL, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => AllegroOAuthConfig::REDIRECT_URI,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Allegro OAuth token exchange failed without exposing credentials.', ['exception' => $exception::class]);
            return redirect('/admin/allegro-settings')->with('error', 'Nie udało się połączyć z Allegro OAuth. Spróbuj ponownie.');
        }

        if (! $response->successful()) {
            Log::warning('Allegro OAuth token exchange returned non-success status.', ['status' => $response->status()]);
            return redirect('/admin/allegro-settings')->with('error', 'Allegro odrzuciło wymianę kodu na tokeny. Sprawdź konfigurację aplikacji.');
        }

        $payload = $response->json();
        if (! is_array($payload) || blank($payload['access_token'] ?? null) || blank($payload['refresh_token'] ?? null)) {
            return redirect('/admin/allegro-settings')->with('error', 'Odpowiedź Allegro nie zawiera wymaganych tokenów.');
        }

        $account->forceFill([
            'api_credentials' => array_merge($credentials, [
                'access_token' => (string) $payload['access_token'],
                'refresh_token' => (string) $payload['refresh_token'],
                'token_type' => (string) ($payload['token_type'] ?? ''),
                'expires_in' => $payload['expires_in'] ?? null,
                'access_token_expires_at' => AllegroOAuthConfig::tokenExpiresAt($payload['expires_in'] ?? null),
                'connected_at' => now()->toISOString(),
            ]),
            'last_connection_check_at' => now(),
            'last_connection_status' => 'ok',
            'last_connection_message' => 'Allegro OAuth connected; tokens stored securely.',
            'last_connected_at' => now(),
        ])->save();

        return redirect()->away('https://gpswiss.pl/admin/allegro-settings')
            ->with('success', 'Allegro zostało połączone. Tokeny zapisane bezpiecznie.');
    }
}
