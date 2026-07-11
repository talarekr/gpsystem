<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MarketplaceSupportReadOnlyService
{
    public const AUDIT_MARKERS = [
        'marketplace_support_journal_audit_v1',
        'marketplace_support_api_capabilities_v1',
        'marketplace_support_read_only_diagnostics_v1',
        'marketplace_support_normalization_v1',
    ];

    public const CAPABILITIES = [
        'allegro' => [
            'api_supported' => true,
            'messages_supported' => false,
            'returns_supported' => true,
            'complaints_supported' => true,
            'webhooks_supported' => true,
            'required_scopes' => ['allegro:api:orders:read'],
            'diagnostic_endpoint' => '/order/customer-returns?limit=1',
            'notes' => 'Public order/customer-returns and dispute areas are read-capable; no documented generic Message Center import endpoint was wired in this stage.',
        ],
        'ebay' => [
            'api_supported' => true,
            'messages_supported' => true,
            'returns_supported' => true,
            'complaints_supported' => true,
            'webhooks_supported' => true,
            'required_scopes' => ['https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'],
            'diagnostic_endpoint' => '/sell/fulfillment/v1/order?limit=1',
            'notes' => 'Current GPSwiss order integration uses Sell Fulfillment; Post-Order and legacy messaging APIs may need additional approval/scopes before live probing.',
        ],
        'ovoko' => [
            'api_supported' => true,
            'messages_supported' => false,
            'returns_supported' => false,
            'complaints_supported' => false,
            'webhooks_supported' => false,
            'required_scopes' => ['username', 'password', 'user_token'],
            'diagnostic_endpoint' => null,
            'notes' => 'No official public support-message/return/complaint endpoints found in the currently used Ovoko API pattern; do not invent endpoints.',
        ],
    ];

    public function diagnose(string $marketplace, bool $probe = false): array
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $capability = self::CAPABILITIES[$marketplace];
        $account = $this->account($marketplace);
        $credentials = $this->credentials($account);
        $scopes = $this->scopes($credentials);
        $missing = $this->missingScopes($capability['required_scopes'], $scopes, $marketplace);

        $result = [
            'ok' => true,
            'read_only' => true,
            'marketplace' => $marketplace,
            'api_supported' => $capability['api_supported'],
            'authentication_ready' => $this->authenticationReady($marketplace, $account, $credentials),
            'required_scopes' => $capability['required_scopes'],
            'missing_scopes' => $missing,
            'messages_supported' => $capability['messages_supported'],
            'returns_supported' => $capability['returns_supported'],
            'complaints_supported' => $capability['complaints_supported'],
            'webhooks_supported' => $capability['webhooks_supported'],
            'sample_count' => 0,
            'sample' => [],
            'no_mutation' => true,
            'max_sample' => 5,
            'probe_executed' => false,
            'diagnostic_endpoint' => $capability['diagnostic_endpoint'],
            'notes' => $capability['notes'],
            'audit_markers' => self::AUDIT_MARKERS,
        ];

        if ($probe && $result['authentication_ready'] && in_array($marketplace, ['allegro', 'ebay'], true)) {
            $result = $this->probe($marketplace, $account, $credentials, $result);
        }

        return $result;
    }

    public function preview(string $marketplace): array
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $diagnose = $this->diagnose($marketplace, false);
        $sample = $this->localReadOnlySamples($marketplace);

        return [
            'ok' => true,
            'read_only' => true,
            'marketplace' => $marketplace,
            'source' => 'local_safe_preview_no_marketplace_mutation',
            'api_diagnosis' => Arr::only($diagnose, ['api_supported', 'authentication_ready', 'messages_supported', 'returns_supported', 'complaints_supported', 'missing_scopes']),
            'sample_count' => count($sample),
            'sample' => $sample,
            'no_mutation' => true,
        ];
    }

    private function probe(string $marketplace, ?MarketplaceAccount $account, array $credentials, array $result): array
    {
        $base = rtrim((string) $account?->api_base_url, '/');
        if ($base === '') return $result;
        $endpoint = self::CAPABILITIES[$marketplace]['diagnostic_endpoint'];
        try {
            $request = Http::withToken((string) ($credentials['access_token'] ?? ''))->timeout(10)->acceptJson();
            if ($marketplace === 'allegro') $request = $request->accept('application/vnd.allegro.public.v1+json');
            if ($marketplace === 'ebay') $request = $request->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => (string) (($account?->api_settings ?? [])['marketplace_id'] ?? 'EBAY_DE')]);
            $response = $request->get($base.$endpoint);
            $json = is_array($response->json()) ? $response->json() : [];
            $rows = array_slice(array_values(array_filter($json['customerReturns'] ?? $json['orders'] ?? $json['data'] ?? [], 'is_array')), 0, 5);
            $result['probe_executed'] = true;
            $result['http_status'] = $response->status();
            $result['sample'] = array_map(fn (array $row): array => $this->redact($row), $rows);
            $result['sample_count'] = count($result['sample']);
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['probe_error'] = $e::class;
        }
        return $result;
    }

    private function localReadOnlySamples(string $marketplace): array
    {
        if (! Schema::hasTable('orders')) return [];
        return Order::query()->where(function ($query) use ($marketplace): void {
            $query->where('marketplace', 'like', '%'.$marketplace.'%')->orWhere('order_number', 'like', '%'.$marketplace.'%');
        })->latest()->limit(5)->get()->map(function (Order $order) use ($marketplace): array {
            $external = (string) ($order->marketplace_order_id ?? $order->order_number ?? '');
            return [
                'raw_reference' => ['order_number' => $order->order_number, 'marketplace' => $order->marketplace],
                'normalized_type' => 'message',
                'normalized_status' => 'unknown',
                'requires_action' => false,
                'unread' => false,
                'external_order_id' => $external,
                'local_order_id' => $order->id,
                'exists' => false,
                'would' => 'create_if_supported_by_future_import',
                'no_mutation' => true,
            ];
        })->values()->all();
    }

    private function account(string $marketplace): ?MarketplaceAccount
    {
        if (! Schema::hasTable('marketplace_accounts')) return null;
        return MarketplaceAccount::query()->where(function ($query) use ($marketplace): void {
            $query->where('marketplace', $marketplace)->orWhere('code', 'like', $marketplace.'%');
        })->where('api_enabled', true)->first();
    }

    private function normalizeMarketplace(string $marketplace): string
    {
        $value = Str::lower(trim($marketplace));
        if (Str::contains($value, 'ebay')) return 'ebay';
        if (! array_key_exists($value, self::CAPABILITIES)) abort(404, 'Unsupported marketplace.');
        return $value;
    }

    private function credentials(?MarketplaceAccount $account): array { return is_array($account?->api_credentials) ? $account->api_credentials : []; }
    private function scopes(array $credentials): array { $scope = $credentials['scope'] ?? $credentials['scopes'] ?? []; return is_array($scope) ? $scope : preg_split('/\s+/', (string) $scope, -1, PREG_SPLIT_NO_EMPTY); }
    private function missingScopes(array $required, array $actual, string $marketplace): array { return $marketplace === 'ovoko' ? array_values(array_filter($required, fn ($key) => blank($actual[$key] ?? null))) : array_values(array_diff($required, $actual)); }
    private function authenticationReady(string $marketplace, ?MarketplaceAccount $account, array $credentials): bool { return $account !== null && $account->api_enabled && filled($account->api_base_url) && ($marketplace === 'ovoko' ? filled($credentials['user_token'] ?? null) : filled($credentials['access_token'] ?? null)); }
    private function redact(array $row): array { array_walk_recursive($row, function (&$value, $key): void { if (preg_match('/token|email|phone|address|password|secret/i', (string) $key)) $value = '[redacted]'; }); return $row; }
}
