<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\Order;
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
        'marketplace_support_allegro_live_readonly_probe_v2',
        'marketplace_support_ebay_capability_probe_v2',
        'marketplace_support_ovoko_config_diagnose_v2',
        'marketplace_support_order_link_preview_v2',
        'marketplace_support_allegro_accept_header_fix_v3',
        'marketplace_support_allegro_feature_probe_split_v3',
        'marketplace_support_ebay_auth_scope_diagnose_v3',
        'marketplace_support_ebay_post_order_access_diagnose_v3',
    ];

    private const EBAY_FEATURES = [
        'messages' => ['api_family' => 'Commerce Message API', 'endpoint' => '/commerce/message/v1/conversation?limit=5', 'required_scopes' => ['https://api.ebay.com/oauth/api_scope/commerce.message.readonly'], 'requires_application_approval' => true, 'deprecated' => false],
        'returns' => ['api_family' => 'Post-Order Return API', 'endpoint' => '/post-order/v2/return/search?limit=5', 'required_scopes' => ['https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'], 'requires_application_approval' => true, 'deprecated' => false],
        'inquiries' => ['api_family' => 'Post-Order Inquiry API', 'endpoint' => '/post-order/v2/inquiry/search?limit=5', 'required_scopes' => ['https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'], 'requires_application_approval' => true, 'deprecated' => false],
        'cancellations' => ['api_family' => 'Post-Order Cancellation API', 'endpoint' => '/post-order/v2/cancellation/search?limit=5', 'required_scopes' => ['https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'], 'requires_application_approval' => true, 'deprecated' => false],
        'disputes' => ['api_family' => 'Post-Order Case/Dispute API', 'endpoint' => '/post-order/v2/casemanagement/search?limit=5', 'required_scopes' => ['https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'], 'requires_application_approval' => true, 'deprecated' => false],
    ];

    public const CAPABILITIES = [
        'allegro' => ['api_supported' => true, 'messages_supported' => false, 'returns_supported' => true, 'complaints_supported' => true, 'webhooks_supported' => true, 'required_scopes' => ['allegro:api:orders:read'], 'returns_required_scopes' => ['allegro:api:orders:read'], 'issues_required_scopes' => ['allegro:api:sale:offers:read'], 'diagnostic_endpoint' => '/order/customer-returns?limit=5', 'notes' => 'Returns use documented GET /order/customer-returns. Complaints/disputes are probed only through the documented disputes endpoint when the token can access it; Message Center remains unconfirmed.'],
        'ebay' => ['api_supported' => true, 'messages_supported' => false, 'returns_supported' => false, 'complaints_supported' => false, 'webhooks_supported' => true, 'required_scopes' => ['https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'], 'diagnostic_endpoint' => null, 'notes' => 'Sell Fulfillment Orders is not treated as proof for messages, returns, inquiries, cancellations, or disputes.'],
        'ovoko' => ['api_supported' => true, 'messages_supported' => false, 'returns_supported' => false, 'complaints_supported' => false, 'webhooks_supported' => false, 'required_scopes' => ['username', 'password', 'user_token'], 'diagnostic_endpoint' => null, 'notes' => 'No official public support-message/return/complaint endpoints found in the currently used Ovoko API pattern; do not invent endpoints.'],
    ];

    public function diagnose(string $marketplace, bool $probe = false): array
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $capability = self::CAPABILITIES[$marketplace];
        $account = $this->account($marketplace);
        $credentials = $this->credentials($account);
        $scopes = $this->scopes($credentials);
        $missing = $this->missingScopes($capability['required_scopes'], $scopes, $marketplace, $credentials);
        $authReady = $this->authenticationReady($marketplace, $account, $credentials);

        $result = ['ok' => true, 'read_only' => true, 'marketplace' => $marketplace, 'api_supported' => $capability['api_supported'], 'authentication_ready' => $authReady, 'required_scopes' => $capability['required_scopes'], 'missing_scopes' => $missing, 'messages_supported' => $capability['messages_supported'], 'returns_supported' => $capability['returns_supported'], 'complaints_supported' => $capability['complaints_supported'], 'webhooks_supported' => $capability['webhooks_supported'], 'sample_count' => 0, 'sample' => [], 'order_link_preview' => [], 'decision_table' => [], 'no_mutation' => true, 'max_sample' => 5, 'probe_executed' => false, 'diagnostic_endpoint' => $capability['diagnostic_endpoint'], 'notes' => $capability['notes'], 'audit_markers' => self::AUDIT_MARKERS];

        if ($marketplace === 'ovoko') $result['ovoko_config'] = $this->ovokoConfigDiagnose($account, $credentials);
        if ($marketplace === 'ebay') $result['capability_checks'] = $this->ebayCapabilities($account, $credentials, $scopes, $probe && $authReady);
        if ($marketplace === 'allegro') {
            $result['returns_required_scopes'] = $capability['returns_required_scopes'];
            $result['issues_required_scopes'] = $capability['issues_required_scopes'];
            $result['scope_presence_confirmed'] = $scopes !== [];
            $result['returns_missing_scopes'] = $this->missingScopes($capability['returns_required_scopes'], $scopes, $marketplace, $credentials);
            $result['issues_missing_scopes'] = $this->missingScopes($capability['issues_required_scopes'], $scopes, $marketplace, $credentials);
        }
        if ($probe && $authReady && $marketplace === 'allegro') $result = $this->probeAllegro($account, $credentials, $result);
        $result['decision_table'] = $this->decisionTable($marketplace, $result);
        return $result;
    }

    public function preview(string $marketplace): array
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        return ['ok' => true, 'read_only' => true, 'marketplace' => $marketplace, 'source' => 'http_probe_confirmed_records_only', 'sample_count' => 0, 'sample' => [], 'no_mutation' => true, 'audit_markers' => self::AUDIT_MARKERS];
    }

    private function probeAllegro(?MarketplaceAccount $account, array $credentials, array $result): array
    {
        $base = rtrim((string) $account?->api_base_url, '/'); if ($base === '') return $result;
        $endpoints = [
            'returns' => ['endpoint' => '/order/customer-returns?limit=5', 'accept' => 'application/vnd.allegro.public.v1+json', 'keys' => ['customerReturns']],
            'issues' => ['endpoint' => '/sale/issues?limit=5', 'accept' => 'application/vnd.allegro.beta.v1+json', 'keys' => ['issues']],
        ];
        $result['probe_results'] = [];
        foreach ($endpoints as $feature => $meta) {
            $response = Http::withToken((string) $credentials['access_token'])->timeout(10)->withHeaders(['Accept' => $meta['accept'], 'Accept-Language' => 'pl-PL'])->get($base.$meta['endpoint']);
            $result['probe_executed'] = true; $result['http_status'][$feature] = $response->status();
            $json = is_array($response->json()) ? (array) $response->json() : [];
            $rows = $response->successful() ? $this->extractRows($json, $meta['keys']) : [];
            $samples = array_map(fn (array $row) => $this->supportSample('allegro', $feature === 'returns' ? 'return' : 'dispute', $row), array_slice($rows, 0, 5));
            $result['sample'] = array_slice(array_merge($result['sample'], $samples), 0, 5);
            $error = $response->successful() ? null : $this->allegroErrorType($response->status(), $json);
            $result['errors'][$feature] = $error;
            $result['probe_results'][$feature] = [
                'feature' => $feature, 'endpoint' => $meta['endpoint'], 'accept_header' => $meta['accept'], 'http_status' => $response->status(), 'error_type' => $error,
                'response_content_type' => $response->header('Content-Type'), 'allegro_error_code' => $this->firstErrorValue($json, 'code'), 'allegro_error_message' => $this->firstErrorValue($json, 'message') ?: $this->firstErrorValue($json, 'userMessage'),
                'trace_id' => $response->header('trace-id') ?: $response->header('x-trace-id'), 'top_level_keys' => array_values(array_keys($json)), 'sample_count' => count($samples),
            ];
        }
        $result['sample_count'] = count($result['sample']);
        $result['order_link_preview'] = array_map(fn ($s) => $this->orderLinkPreview('allegro', $s), $result['sample']);
        return $result;
    }

    private function ebayCapabilities(?MarketplaceAccount $account, array $credentials, array $scopes, bool $probe): array
    {
        $base = rtrim((string) $account?->api_base_url, '/') ?: 'https://api.ebay.com';
        $out = [];
        foreach (self::EBAY_FEATURES as $feature => $meta) {
            $missing = array_values(array_diff($meta['required_scopes'], $scopes));
            $row = ['feature' => $feature] + $meta + ['token_type' => 'user', 'configured_requested_scopes' => $scopes, 'token_scope_confirmed' => $scopes !== [], 'missing_scopes' => $missing, 'requires_application_approval' => $meta['requires_application_approval'], 'application_approval_confirmed' => false, 'resolution' => $missing ? 'reauthorize_with_scope' : 'unknown', 'probe_supported' => $missing === [], 'probe_executed' => false, 'http_status' => null, 'sample_count' => 0, 'error_type' => null, 'api_exists' => true, 'app_has_scope' => $missing === [], 'app_access_confirmed' => false, 'ebay_error_id' => null, 'ebay_error_domain' => null, 'ebay_error_category' => null, 'ebay_error_message' => null];
            if ($probe && $missing === []) {
                $response = Http::withToken((string) ($credentials['access_token'] ?? ''))->timeout(10)->acceptJson()->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => (string) (($account?->api_settings ?? [])['marketplace_id'] ?? 'EBAY_DE')])->get($base.$meta['endpoint']);
                $row['probe_executed'] = true; $row['http_status'] = $response->status(); $row['error_type'] = $response->successful() ? null : $this->errorType($response->status());
                $err = $this->ebayErrorDetails(is_array($response->json()) ? (array) $response->json() : []); $row = array_merge($row, $err);
                $row['app_access_confirmed'] = $response->successful(); $row['application_approval_confirmed'] = $response->successful(); $row['resolution'] = $response->successful() ? null : (($response->status() === 401 || $response->status() === 403) ? 'request_application_approval' : 'fix_request'); $row['sample_count'] = $response->successful() ? min(5, count($this->extractRows((array) $response->json(), ['conversations', 'returns', 'inquiries', 'cancellations', 'cases', 'disputes']))) : 0;
            }
            $out[] = $row;
        }
        return $out;
    }

    public function ebayAuthDiagnose(): array
    {
        $account = $this->account('ebay'); $credentials = $this->credentials($account); $scopes = $this->scopes($credentials);
        $required = collect(self::EBAY_FEATURES)->mapWithKeys(fn ($m, $f) => [$f => $m['required_scopes']])->all();
        $missing = collect($required)->mapWithKeys(fn ($req, $f) => [$f => array_values(array_diff($req, $scopes))])->all();
        return ['ok' => true, 'read_only' => true, 'token_manager_ready' => $account !== null, 'user_token_available' => filled($credentials['access_token'] ?? null), 'refresh_token_available' => filled($credentials['refresh_token'] ?? null), 'configured_scopes' => $scopes, 'required_scopes_by_feature' => $required, 'missing_scopes_by_feature' => $missing, 'requires_reauthorization' => collect($missing)->flatten()->isNotEmpty(), 'requires_application_approval' => array_keys(array_filter(self::EBAY_FEATURES, fn ($m) => $m['requires_application_approval'])), 'no_mutation' => true, 'audit_markers' => self::AUDIT_MARKERS];
    }

    private function supportSample(string $marketplace, string $type, array $row): array
    {
        $orderId = (string) data_get($row, 'order.id', data_get($row, 'checkoutForm.id', data_get($row, 'orderId', '')));
        return ['external_id' => (string) ($row['id'] ?? $row['returnId'] ?? $row['caseId'] ?? ''), 'type' => $type, 'raw_status' => (string) ($row['status'] ?? $row['state'] ?? 'unknown'), 'normalized_status' => $this->normalizeStatus((string) ($row['status'] ?? $row['state'] ?? 'unknown')), 'requires_action' => $this->requiresAction($row), 'external_order_id' => $orderId, 'local_order_id' => $this->localOrderId($marketplace, $orderId), 'created_at' => $row['createdAt'] ?? $row['created_at'] ?? null, 'updated_at' => $row['updatedAt'] ?? $row['lastModifiedAt'] ?? null, 'deadline_at' => $row['deadlineAt'] ?? null, 'safe_keys' => array_values(array_slice(array_filter(array_keys($row), fn ($k) => ! preg_match('/email|phone|address|name|token|secret|password/i', (string) $k)), 0, 20))];
    }

    private function orderLinkPreview(string $marketplace, array $sample): array
    { return ['external_order_id' => $sample['external_order_id'] ?? null, 'local_order_id' => $sample['local_order_id'] ?? null, 'marketplace' => $marketplace, 'type' => $sample['type'] ?? null, 'normalized_status' => $sample['normalized_status'] ?? null, 'requires_action' => (bool) ($sample['requires_action'] ?? false), 'would_go_to' => ['messages' => ($sample['type'] ?? '') === 'message', 'returns_complaints' => in_array($sample['type'] ?? '', ['return','complaint','dispute'], true), 'requires_action' => (bool) ($sample['requires_action'] ?? false)], 'no_mutation' => true]; }
    private function extractRows(array $json, array $keys): array { foreach ($keys as $key) if (isset($json[$key]) && is_array($json[$key])) return array_values(array_filter($json[$key], 'is_array')); return []; }
    private function errorType(int $status): ?string { return in_array($status, [401,403], true) ? 'auth_or_scope_error' : ($status === 429 ? 'rate_limited' : ($status >= 400 ? 'api_error' : null)); }
    private function allegroErrorType(int $status, array $json): ?string { if ($status === 406) return 'not_acceptable_media_type'; return $this->errorType($status); }
    private function firstErrorValue(array $json, string $key): ?string { $errors = data_get($json, 'errors', []); if (is_array($errors) && isset($errors[0]) && is_array($errors[0]) && filled($errors[0][$key] ?? null)) return (string) $errors[0][$key]; return filled($json[$key] ?? null) ? (string) $json[$key] : null; }
    private function ebayErrorDetails(array $json): array { $e = data_get($json, 'errors.0', []); return ['ebay_error_id' => data_get($e, 'errorId'), 'ebay_error_domain' => data_get($e, 'domain'), 'ebay_error_category' => data_get($e, 'category'), 'ebay_error_message' => data_get($e, 'message') ?: data_get($json, 'message')]; }
    private function normalizeStatus(string $status): string { return match (Str::lower($status)) { 'created','new','open','opened' => 'open', 'closed','completed','resolved' => 'closed', 'cancelled','canceled','rejected' => 'closed', default => 'unknown' }; }
    private function requiresAction(array $row): bool { $status = Str::lower((string) ($row['status'] ?? $row['state'] ?? '')); return (bool) ($row['requiresAction'] ?? Str::contains($status, ['created','open','waiting'])); }

    private function localReadOnlySamples(string $marketplace): array { if (! Schema::hasTable('orders')) return []; return Order::query()->where(fn ($q) => $q->where('marketplace', 'like', '%'.$marketplace.'%')->orWhere('order_number', 'like', '%'.$marketplace.'%'))->latest()->limit(5)->get()->map(fn (Order $order) => $this->orderLinkPreview($marketplace, ['type' => 'message', 'normalized_status' => 'unknown', 'requires_action' => false, 'external_order_id' => (string) ($order->marketplace_order_id ?? $order->order_number ?? ''), 'local_order_id' => $order->id]) + ['raw_reference' => ['order_number' => $order->order_number, 'marketplace' => $order->marketplace], 'unread' => false])->values()->all(); }
    private function ovokoConfigDiagnose(?MarketplaceAccount $account, array $credentials): array { $order = $this->hasAll($credentials, ['username','password','user_token']) || $this->hasAll((array) ($account?->config ?? []), ['username','password','user_token']); return ['order_sync_credentials_detected' => $order, 'support_api_credentials_detected' => false, 'same_configuration_source' => false, 'support_endpoints_documented' => false, 'can_probe_support_api' => false, 'reason' => 'Order sync credentials may exist for the documented order API pattern, but no official support API endpoint is configured or documented; values are intentionally redacted.']; }
    private function hasAll(array $array, array $keys): bool { foreach ($keys as $key) if (blank($array[$key] ?? null)) return false; return true; }
    private function decisionTable(string $marketplace, array $result): array { if ($marketplace === 'ovoko') return ['messages' => 'unavailable', 'returns' => 'unavailable', 'complaints' => 'unavailable']; if ($marketplace === 'allegro') return ['returns' => ($result['errors']['returns'] ?? null) ? 'blocked' : (($result['probe_executed'] ?? false) ? 'confirmed working' : 'not probed'), 'complaints/disputes' => ($result['errors']['issues'] ?? null) ? 'blocked' : (($result['probe_executed'] ?? false) ? 'confirmed working' : 'not probed'), 'messages' => 'unsupported/unconfirmed']; return collect($result['capability_checks'] ?? [])->mapWithKeys(fn ($r) => [$r['feature'] => $r['missing_scopes'] ? 'missing scope' : (($r['app_access_confirmed'] ?? false) ? 'confirmed working' : (($r['probe_executed'] ?? false) ? 'restricted' : 'not probed'))])->all(); }
    private function account(string $marketplace): ?MarketplaceAccount { if (! Schema::hasTable('marketplace_accounts')) return null; return MarketplaceAccount::query()->where(fn ($q) => $q->where('marketplace', $marketplace)->orWhere('code', 'like', $marketplace.'%'))->where('api_enabled', true)->first(); }
    private function normalizeMarketplace(string $marketplace): string { $value = Str::lower(trim($marketplace)); if (Str::contains($value, 'ebay')) return 'ebay'; if (! array_key_exists($value, self::CAPABILITIES)) abort(404, 'Unsupported marketplace.'); return $value; }
    private function credentials(?MarketplaceAccount $account): array { return is_array($account?->api_credentials) ? $account->api_credentials : []; }
    private function scopes(array $credentials): array { $scope = $credentials['scope'] ?? $credentials['scopes'] ?? []; return is_array($scope) ? $scope : preg_split('/\s+/', (string) $scope, -1, PREG_SPLIT_NO_EMPTY); }
    private function missingScopes(array $required, array $actual, string $marketplace, array $credentials = []): array { return $marketplace === 'ovoko' ? array_values(array_filter($required, fn ($key) => blank($credentials[$key] ?? null))) : array_values(array_diff($required, $actual)); }
    private function authenticationReady(string $marketplace, ?MarketplaceAccount $account, array $credentials): bool { return $account !== null && $account->api_enabled && filled($account->api_base_url) && ($marketplace === 'ovoko' ? $this->hasAll($credentials, ['username','password','user_token']) : filled($credentials['access_token'] ?? null)); }
    private function localOrderId(string $marketplace, string $externalOrderId): ?int { if ($externalOrderId === '' || ! Schema::hasTable('orders')) return null; return Order::query()->where('marketplace_order_id', $externalOrderId)->where('marketplace', 'like', '%'.$marketplace.'%')->value('id'); }
}
