<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\EbayApiClient;
use App\Services\Marketplace\EbayShippingPolicyResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class EbayDebugPartController extends Controller
{
    public function __construct(private readonly EbayShippingPolicyResolutionService $shippingPolicyResolutionService) {}

    public function __invoke(Request $request, int $partId): JsonResponse
    {
        $channel = (string) $request->query('channel', 'ebay_de');
        if (! in_array($channel, ['ebay_de', 'ebay_fr'], true)) $channel = 'ebay_de';

        $part = Part::query()->with('category')->find($partId);
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $channel)->first() : null;
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $checks = [];
        $recommendations = [];

        $checks[] = ['name' => 'part_exists', 'ok' => $part !== null, 'part_id' => $partId];
        $checks[] = ['name' => 'account_configured', 'ok' => $account !== null, 'channel' => $channel, 'account_id' => $account?->id];
        $checks[] = ['name' => 'api_enabled', 'ok' => (bool) ($account?->api_enabled ?? false)];
        $checks[] = ['name' => 'token_exists', 'ok' => filled($credentials['access_token'] ?? null) || filled($credentials['refresh_token'] ?? null), 'has_access_token' => filled($credentials['access_token'] ?? null), 'has_refresh_token' => filled($credentials['refresh_token'] ?? null)];
        $checks[] = ['name' => 'token_expiry', 'ok' => blank($credentials['expires_at'] ?? null) || strtotime((string) $credentials['expires_at']) > time(), 'expires_at' => $credentials['expires_at'] ?? null];
        $checks[] = ['name' => 'marketplace_id', 'ok' => filled($settings['marketplace_id'] ?? null), 'marketplace_id' => $settings['marketplace_id'] ?? null];
        $paymentPolicy = $this->policyValue($settings, 'payment');
        $returnPolicy = $this->policyValue($settings, 'return');
        $accountFulfillmentPolicy = $this->policyValue($settings, 'fulfillment');
        $checks[] = ['name' => 'local_policy_ids', 'ok' => filled($paymentPolicy['value']) && filled($returnPolicy['value']), 'payment_policy_id' => $paymentPolicy['value'], 'payment_policy_source' => $paymentPolicy['source'], 'fulfillment_policy_id' => $accountFulfillmentPolicy['value'], 'fulfillment_policy_source' => $accountFulfillmentPolicy['source'], 'return_policy_id' => $returnPolicy['value'], 'return_policy_source' => $returnPolicy['source']];

        $mapping = null;
        if ($part && Schema::hasTable('marketplace_category_mappings')) {
            $mapping = MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->whereIn('channel', [$channel, 'ebay'])->whereNotNull('external_category_id')->first();
        }
        $checks[] = ['name' => 'category_mapping', 'ok' => $mapping !== null, 'local_category_id' => $part?->category_id, 'external_category_id' => $mapping?->external_category_id ?? null];

        $shippingPolicyResolution = $this->shippingPolicyResolutionService->resolve($part, $mapping, $channel);
        $fulfillmentPolicyId = $shippingPolicyResolution['selected_fulfillment_policy_id'] ?? null;
        $fulfillmentPolicySource = $shippingPolicyResolution['fulfillment_policy_source'] ?? null;
        $shippingGroup = $shippingPolicyResolution['shipping_group'] ?? null;
        $fulfillmentBlocker = blank($fulfillmentPolicyId);
        $checks[] = [
            'name' => 'fulfillment_policy',
            'ok' => ! $fulfillmentBlocker,
            'required' => true,
            'fulfillment_policy_id' => $fulfillmentPolicyId,
            'fulfillment_policy_source' => $fulfillmentPolicySource,
            'where_saved' => 'marketplace_category_mappings.fulfillment_policy_id for the part local category and eBay channel; fallback can come from same eBay category or an ancestor category mapping.',
            'shipping_group' => $shippingGroup,
            'shipping_group_source' => $shippingPolicyResolution['shipping_group_source'] ?? null,
            'resolution' => $shippingPolicyResolution,
        ];
        if ($fulfillmentBlocker) {
            $recommendations[] = 'Uzupełnij politykę wysyłki/dostawy eBay dla kanału '.$channel.'.';
        }

        $policyComparison = $this->policyComparison($part);

        $policyDiagnostics = null;
        if ($account && ((bool) $request->boolean('api', true))) {
            $policyDiagnostics = (new EbayApiClient($channel, $account))->withDiagnosticContext(['part_id' => $partId, 'stage' => 'debug_part'])->businessPoliciesDiagnostics();
            $checks[] = ['name' => 'ebay_read_only_business_policies', 'ok' => (bool) ($policyDiagnostics['ok'] ?? false), 'http_read_only' => true, 'diagnostics' => $policyDiagnostics];
        }

        $errors = Schema::hasTable('marketplace_sync_logs') ? MarketplaceSyncLog::query()
            ->where('marketplace', $channel)
            ->where('part_id', $partId)
            ->where('action', 'like', 'ebay_api_error:%')
            ->latest('created_at')
            ->limit(10)
            ->get(['id','created_at','action','http_status','message','request_id','payload'])
            ->map(fn (MarketplaceSyncLog $log): array => $log->toArray())
            ->all() : [];

        $failed = array_values(array_filter($checks, fn (array $check): bool => ! (bool) ($check['ok'] ?? false)));
        foreach ($failed as $check) $recommendations[] = match ($check['name']) {
            'token_exists', 'token_expiry' => 'Odśwież autoryzację eBay OAuth dla kanału '.$channel.'.',
            'local_policy_ids', 'ebay_read_only_business_policies' => 'Sprawdź Business Policies w ustawieniach eBay i uprawnienia tokena sell.account.',
            'fulfillment_policy' => 'Uzupełnij politykę wysyłki/dostawy eBay dla kanału '.$channel.'.',
            'category_mapping' => 'Uzupełnij mapowanie lokalnej kategorii części do kategorii eBay dla '.$channel.'.',
            'marketplace_id' => 'Ustaw marketplace_id (np. EBAY_DE albo EBAY_FR) dla konta marketplace.',
            default => 'Sprawdź konfigurację: '.$check['name'].'.',
        };

        return response()->json([
            'ok' => $failed === [],
            'dry_run' => true,
            'marketplace_write' => false,
            'part_id' => $partId,
            'channel' => $channel,
            'marketplace_id' => $settings['marketplace_id'] ?? null,
            'local_policy_ids' => [
                'payment_policy_id' => $paymentPolicy['value'],
                'fulfillment_policy_id' => $fulfillmentPolicyId,
                'return_policy_id' => $returnPolicy['value'],
            ],
            'payment_policy_source' => $paymentPolicy['source'],
            'fulfillment_policy_source' => $fulfillmentPolicySource,
            'return_policy_source' => $returnPolicy['source'],
            'shipping_group' => $shippingGroup,
            'shipping_profile' => $shippingGroup,
            'delivery_profile' => $shippingGroup,
            'policy_storage' => [
                'fulfillment_policy_id' => 'marketplace_category_mappings.fulfillment_policy_id',
                'shipping_group' => 'marketplace_category_mappings.shipping_group',
                'payment_policy_id' => 'marketplace_accounts.api_settings.payment_policy_id',
                'return_policy_id' => 'marketplace_accounts.api_settings.return_policy_id',
            ],
            'policy_scope' => 'Fulfillment policy is resolved per eBay channel/marketplace and local category mapping, with fallback by same external eBay category or ancestor category mapping.',
            'policy_comparison' => $policyComparison,
            'readiness_blockers' => array_values(array_unique(array_map(fn (array $check): string => (string) $check['name'], $failed))),
            'prepare_blockers' => $fulfillmentBlocker ? ['fulfillment_policy'] : [],
            'can_prepare_offer' => $failed === [],
            'checks' => $checks,
            'ebay_http_errors' => $errors,
            'likely_cause' => $fulfillmentBlocker ? 'fulfillment_policy' : ($failed[0]['name'] ?? ($errors[0]['message'] ?? null)),
            'recommendations' => array_values(array_unique($recommendations)),
        ]);
    }

    /** @return array{value:?string,source:?string} */
    private function policyValue(array $settings, string $type): array
    {
        foreach (["{$type}_policy_id", "selected_{$type}_policy_id", "default_{$type}_policy_id", "ebay_{$type}_policy_id"] as $key) {
            if (filled($settings[$key] ?? null)) return ['value' => (string) $settings[$key], 'source' => 'marketplace_accounts.api_settings.'.$key];
        }

        $policies = is_array($settings['business_policies'] ?? null) ? $settings['business_policies'] : [];
        if (filled($policies[$type] ?? null)) return ['value' => (string) $policies[$type], 'source' => 'marketplace_accounts.api_settings.business_policies.'.$type];

        return ['value' => null, 'source' => null];
    }

    /** @return array<string, mixed> */
    private function policyComparison(?Part $part): array
    {
        $result = [];
        foreach (['ebay_de', 'ebay_fr'] as $channel) {
            $mapping = $part && Schema::hasTable('marketplace_category_mappings')
                ? MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->where('channel', $channel)->first()
                : null;
            $resolution = $this->shippingPolicyResolutionService->resolve($part, $mapping, $channel);
            $result[$channel] = [
                'mapping_id' => $mapping?->id,
                'marketplace_id' => $channel === 'ebay_fr' ? 'EBAY_FR' : 'EBAY_DE',
                'local_category_id' => $part?->category_id,
                'external_category_id' => $mapping?->external_category_id,
                'shipping_group' => $resolution['shipping_group'] ?? null,
                'fulfillment_policy_id' => $resolution['selected_fulfillment_policy_id'] ?? null,
                'fulfillment_policy_source' => $resolution['fulfillment_policy_source'] ?? null,
                'is_blocked' => (bool) ($mapping?->is_blocked ?? false),
                'block_reason' => $mapping?->block_reason,
                'available_policy_mapping' => $resolution['available_policy_mapping'] ?? [],
            ];
        }

        $result['differs_between_ebay_de_and_ebay_fr'] = ($result['ebay_de']['fulfillment_policy_id'] ?? null) !== ($result['ebay_fr']['fulfillment_policy_id'] ?? null)
            || ($result['ebay_de']['shipping_group'] ?? null) !== ($result['ebay_fr']['shipping_group'] ?? null);

        return $result;
    }
}
