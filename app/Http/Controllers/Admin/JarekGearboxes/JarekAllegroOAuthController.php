<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        abort_unless(hash_equals((string) $request->session()->pull('jarek_allegro_oauth_state'), (string) $request->query('state')), 403);
        foreach (['client_id' => 'ALLEGRO_JAREK_CLIENT_ID', 'client_secret' => 'ALLEGRO_JAREK_CLIENT_SECRET'] as $key => $env) {
            if (blank(config("services.allegro_jarek.{$key}"))) throw new RuntimeException('Missing .env/config key for Allegro Jarek: '.$env);
        }

        $response = Http::asForm()->withBasicAuth(config('services.allegro_jarek.client_id'), config('services.allegro_jarek.client_secret'))
            ->post('https://allegro.pl/auth/oauth/token', ['grant_type' => 'authorization_code', 'code' => $request->query('code'), 'redirect_uri' => self::REDIRECT_URI]);

        abort_unless($response->successful(), 502, 'Allegro Jarek OAuth token exchange failed.');
        $json = $response->json();

        return response("ALLEGRO_JAREK_ACCESS_TOKEN={$json['access_token']}\nALLEGRO_JAREK_REFRESH_TOKEN=".($json['refresh_token'] ?? '')."\n\nPo wklejeniu do .env uruchom: php artisan config:clear oraz na produkcji (jeśli używa cache): php artisan config:cache\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache',
        ]);
    }
}
