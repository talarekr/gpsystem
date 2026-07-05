<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\DB;
use App\Support\Marketplace\AllegroUserAgent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use App\Services\Marketplace\Api\AllegroApiClient;
use Throwable;

class AllegroCategoryParametersService
{
    public function definitions(string $categoryId, bool $refresh = false): array
    {
        if ($categoryId === '') return ['ok' => false, 'source' => 'none', 'blocker' => 'allegro_category_mapping_missing', 'parameters' => []];
        if (Schema::hasTable('allegro_category_parameters_cache') && ! $refresh) {
            try {
                $row = DB::table('allegro_category_parameters_cache')->where($this->cacheKeyColumn(), $categoryId)->first();
                if ($row) return ['ok' => true, 'source' => 'cache', 'endpoint' => 'GET /sale/categories/{categoryId}/parameters', 'parameters' => $this->parameters((array) json_decode($row->raw_response, true), $categoryId)];
            } catch (Throwable $exception) {
                return $this->cacheError($exception);
            }
        }
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'allegro_main')->first() : null;
        $token = (string) data_get($account, 'api_credentials.access_token');
        $base = rtrim((string) ($account?->api_base_url ?: 'https://api.allegro.pl'), '/');
        if ($token === '') return ['ok' => false, 'source' => 'none', 'blocker' => 'allegro_credentials_missing', 'parameters' => []];
        $endpoint = $base.'/sale/categories/'.$categoryId.'/parameters';
        $response = AllegroUserAgent::request()->withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(20)->get($endpoint);
        if ($response->status() === 401 && filled(data_get($account, 'api_credentials.refresh_token'))) {
            $refresh = (new AllegroApiClient('allegro_main', $account))->refreshAccessToken();
            if (($refresh['ok'] ?? false) === true) {
                $account->refresh();
                $response = AllegroUserAgent::request()->withToken((string) data_get($account, 'api_credentials.access_token'))->accept('application/vnd.allegro.public.v1+json')->timeout(20)->get($endpoint);
            }
        }
        if ($response->status() === 401) {
            app(ApiIntegrationLogger::class)->error('allegro', 'GET /sale/categories/{categoryId}/parameters', 'Allegro token wygasł, połącz konto ponownie.', ['http_status' => $response->status(), 'request' => ['category_id' => $categoryId], 'response' => is_array($response->json()) ? $response->json() : ['body_present' => filled($response->body())]]);
            return ['ok' => false, 'source' => 'api', 'blocker' => 'Allegro token wygasł, połącz konto ponownie.', 'http_status' => $response->status(), 'parameters' => []];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            app(ApiIntegrationLogger::class)->error('allegro', 'GET /sale/categories/{categoryId}/parameters', 'Nie udało się pobrać parametrów kategorii Allegro '.$categoryId.'.', ['http_status' => $response->status(), 'request' => ['category_id' => $categoryId], 'response' => is_array($response->json()) ? $response->json() : ['body_present' => filled($response->body())]]);
            return ['ok' => false, 'source' => 'api', 'blocker' => 'Brak parametrów Allegro dla category id '.$categoryId, 'http_status' => $response->status(), 'parameters' => []];
        }
        $payload = $response->json();
        if (Schema::hasTable('allegro_category_parameters_cache')) {
            try {
                DB::table('allegro_category_parameters_cache')->updateOrInsert([$this->cacheKeyColumn() => $categoryId], ['raw_response' => json_encode($payload), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            } catch (Throwable $exception) {
                return $this->cacheError($exception);
            }
        }
        return ['ok' => true, 'source' => 'api', 'endpoint' => 'GET /sale/categories/{categoryId}/parameters', 'parameters' => $this->parameters($payload, $categoryId)];
    }

    private function parameters(array $payload, ?string $categoryId = null): array { return array_values(array_map(function (array $parameter) use ($categoryId): array { if ($categoryId !== null) $parameter['category_id'] = $categoryId; return $parameter; }, array_filter($payload['parameters'] ?? [], 'is_array'))); }
    private function cacheKeyColumn(): string { return Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id') ? 'allegro_category_id' : 'category_id'; }
    private function cacheError(Throwable $exception): array { return ['ok' => false, 'source' => 'cache', 'blocker' => 'allegro_category_parameters_cache_error', 'cache_error' => class_basename($exception), 'parameters' => []]; }
}
