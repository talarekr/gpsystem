<?php

namespace App\Http\Controllers\Admin\Ebay;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Support\Marketplace\EbayOAuthConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EbayOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $channel = $this->channel($request);
        if (! $channel) return redirect('/admin/ebay-settings')->with('error', 'Nieprawidłowy kanał eBay OAuth.');

        $account = MarketplaceAccount::query()->where('code', $channel)->first();
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $clientId = (string) ($credentials['client_id'] ?? '');
        $ruName = (string) ($credentials['ru_name'] ?? '');

        if (! $account || $clientId === '' || $ruName === '') {
            return redirect('/admin/ebay-settings')->with('error', 'Uzupełnij Client ID oraz RuName / redirect URI dla eBay przed połączeniem.');
        }

        $state = EbayOAuthConfig::state($channel);
        $request->session()->put('ebay_oauth_state_'.$channel, $state);

        return redirect()->away(EbayOAuthConfig::AUTHORIZATION_URL.'?'.Arr::query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $ruName,
            'scope' => EbayOAuthConfig::scopeString(),
            'state' => $state,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $channel = $this->channel($request);
        if (! $channel) return redirect('/admin/ebay-settings')->with('error', 'Nieprawidłowy kanał eBay OAuth.');

        if ($request->filled('error')) {
            return redirect('/admin/ebay-settings')->with('error', 'eBay OAuth error: '.(string) $request->query('error').' '.(string) $request->query('error_description'));
        }

        $state = (string) $request->query('state', '');
        $expectedState = (string) $request->session()->pull('ebay_oauth_state_'.$channel, '');
        if ($state === '' || $expectedState === '' || ! hash_equals($expectedState, $state) || ! str_starts_with($state, $channel.'|')) {
            return redirect('/admin/ebay-settings')->with('error', 'Nieprawidłowy state OAuth eBay. Spróbuj ponownie.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') return redirect('/admin/ebay-settings')->with('error', 'eBay nie zwrócił kodu autoryzacyjnego.');

        $account = MarketplaceAccount::query()->where('code', $channel)->first();
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $clientId = (string) ($credentials['client_id'] ?? '');
        $clientSecret = (string) ($credentials['client_secret'] ?? '');
        $ruName = (string) ($credentials['ru_name'] ?? '');

        if (! $account || $clientId === '' || $clientSecret === '' || $ruName === '') {
            return redirect('/admin/ebay-settings')->with('error', 'Brakuje Client ID, Client secret lub RuName dla eBay.');
        }

        try {
            $response = Http::asForm()->withBasicAuth($clientId, $clientSecret)->acceptJson()->timeout(20)->post(EbayOAuthConfig::tokenUrl((string) $account->api_base_url), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $ruName,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('eBay OAuth token exchange failed without exposing credentials.', ['channel' => $channel, 'exception' => $exception::class]);
            return redirect('/admin/ebay-settings')->with('error', 'Nie udało się połączyć z eBay OAuth. Spróbuj ponownie.');
        }

        $payload = $response->json();
        if (! $response->successful()) {
            Log::warning('eBay OAuth token exchange returned non-success status.', ['channel' => $channel, 'status' => $response->status()]);
            return redirect('/admin/ebay-settings')->with('error', 'eBay odrzucił wymianę kodu na tokeny: '.$this->safeError($payload, $response->status()));
        }
        if (! is_array($payload) || blank($payload['access_token'] ?? null) || blank($payload['refresh_token'] ?? null)) {
            return redirect('/admin/ebay-settings')->with('error', 'Odpowiedź eBay nie zawiera wymaganych tokenów.');
        }

        $account->forceFill([
            'api_credentials' => array_merge($credentials, [
                'access_token' => (string) $payload['access_token'],
                'refresh_token' => (string) $payload['refresh_token'],
                'expires_at' => EbayOAuthConfig::tokenExpiresAt($payload['expires_in'] ?? null),
                'token_type' => (string) ($payload['token_type'] ?? ''),
                'scopes' => $payload['scope'] ?? EbayOAuthConfig::scopeString(),
                'connected_at' => now()->toISOString(),
            ]),
            'last_connection_check_at' => now(),
            'last_connection_status' => 'ok',
            'last_connection_message' => 'eBay OAuth connected; tokens stored securely.',
            'last_connected_at' => now(),
        ])->save();

        return redirect('/admin/ebay-settings')->with('success', 'eBay DE zostało połączone. Tokeny zapisane bezpiecznie.');
    }

    private function channel(Request $request): ?string
    {
        $channel = (string) $request->query('channel', 'ebay_de');
        return in_array($channel, ['ebay_de', 'ebay_fr'], true) ? $channel : null;
    }

    private function safeError(mixed $payload, int $status): string
    {
        if (! is_array($payload)) return 'HTTP '.$status;
        return trim('HTTP '.$status.' '.(string) ($payload['error'] ?? '').' '.(string) ($payload['error_description'] ?? ($payload['message'] ?? '')));
    }
}
