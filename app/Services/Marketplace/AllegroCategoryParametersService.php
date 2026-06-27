<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class AllegroCategoryParametersService
{
    public function get(string $categoryId, bool $forceRefresh = false): array
    {
        if (! Schema::hasTable('allegro_category_parameters_cache')) {
            return ['ok' => false, 'source' => 'none', 'blockers' => ['allegro_category_parameters_cache_missing'], 'parameters' => [], 'raw_response' => null];
        }

        $cached = DB::table('allegro_category_parameters_cache')->where('allegro_category_id', $categoryId)->first();
        if ($cached && ! $forceRefresh) {
            $raw = json_decode((string) $cached->raw_response, true) ?: [];
            return ['ok' => true, 'source' => 'cache', 'blockers' => [], 'parameters' => $raw['parameters'] ?? [], 'raw_response' => $raw, 'fetched_at' => $cached->fetched_at];
        }

        $account = $this->account();
        $token = $this->accessToken($account);
        if (! $account || blank($token)) {
            return ['ok' => false, 'source' => $cached ? 'cache_stale' : 'none', 'blockers' => ['allegro_credentials_missing'], 'parameters' => [], 'raw_response' => null];
        }

        $base = rtrim((string) ($account->api_base_url ?: 'https://api.allegro.pl'), '/');
        $response = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->get($base.'/sale/categories/'.rawurlencode($categoryId).'/parameters');
        if (! $response->successful()) {
            return ['ok' => false, 'source' => 'api', 'blockers' => ['allegro_category_parameters_unavailable'], 'parameters' => [], 'raw_response' => null, 'status' => $response->status()];
        }

        $raw = $response->json() ?: [];
        DB::table('allegro_category_parameters_cache')->updateOrInsert(
            ['allegro_category_id' => $categoryId],
            ['raw_response' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'fetched_at' => now(), 'updated_at' => now(), 'created_at' => $cached?->created_at ?? now()]
        );

        return ['ok' => true, 'source' => 'api', 'blockers' => [], 'parameters' => $raw['parameters'] ?? [], 'raw_response' => $raw, 'fetched_at' => now()->toDateTimeString()];
    }

    private function account(): ?MarketplaceAccount
    {
        if (! Schema::hasTable('marketplace_accounts')) return null;
        return MarketplaceAccount::query()->whereIn('code', ['allegro_main', 'allegro'])->where('api_enabled', true)->first();
    }

    private function accessToken(?MarketplaceAccount $account): ?string
    {
        $credentials = $account?->api_credentials;
        return is_array($credentials) ? ($credentials['access_token'] ?? null) : null;
    }
}
