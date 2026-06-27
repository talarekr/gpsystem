<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class AllegroCategoryParametersService
{
    public function definitions(string $categoryId, bool $refresh = false): array
    {
        if ($categoryId === '') return ['ok' => false, 'source' => 'none', 'blocker' => 'allegro_category_mapping_missing', 'parameters' => []];
        if (Schema::hasTable('allegro_category_parameters_cache') && ! $refresh) {
            $row = DB::table('allegro_category_parameters_cache')->where($this->cacheKeyColumn(), $categoryId)->first();
            if ($row) return ['ok' => true, 'source' => 'cache', 'endpoint' => 'GET /sale/categories/{categoryId}/parameters', 'parameters' => $this->parameters((array) json_decode($row->raw_response, true))];
        }
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'allegro_main')->first() : null;
        $token = (string) data_get($account, 'api_credentials.access_token');
        $base = rtrim((string) ($account?->api_base_url ?: 'https://api.allegro.pl'), '/');
        if ($token === '') return ['ok' => false, 'source' => 'none', 'blocker' => 'allegro_credentials_missing', 'parameters' => []];
        $endpoint = $base.'/sale/categories/'.$categoryId.'/parameters';
        $response = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(20)->get($endpoint);
        if (! $response->successful() || ! is_array($response->json())) {
            return ['ok' => false, 'source' => 'api', 'blocker' => 'allegro_category_parameters_unavailable', 'http_status' => $response->status(), 'parameters' => []];
        }
        $payload = $response->json();
        if (Schema::hasTable('allegro_category_parameters_cache')) {
            DB::table('allegro_category_parameters_cache')->updateOrInsert([$this->cacheKeyColumn() => $categoryId], ['raw_response' => json_encode($payload), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        return ['ok' => true, 'source' => 'api', 'endpoint' => 'GET /sale/categories/{categoryId}/parameters', 'parameters' => $this->parameters($payload)];
    }

    private function parameters(array $payload): array { return array_values(array_filter($payload['parameters'] ?? [], 'is_array')); }
    private function cacheKeyColumn(): string { return Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id') ? 'allegro_category_id' : 'category_id'; }
}
