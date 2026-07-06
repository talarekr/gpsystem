<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class EbayDebugPartController extends Controller
{
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
        $checks[] = ['name' => 'local_policy_ids', 'ok' => filled($settings['payment_policy_id'] ?? $settings['selected_payment_policy_id'] ?? null) && filled($settings['return_policy_id'] ?? $settings['selected_return_policy_id'] ?? null), 'payment_policy_id' => $settings['payment_policy_id'] ?? $settings['selected_payment_policy_id'] ?? null, 'fulfillment_policy_id' => $settings['fulfillment_policy_id'] ?? $settings['selected_fulfillment_policy_id'] ?? null, 'return_policy_id' => $settings['return_policy_id'] ?? $settings['selected_return_policy_id'] ?? null];

        $mapping = null;
        if ($part && Schema::hasTable('marketplace_category_mappings')) {
            $mapping = MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->whereIn('channel', [$channel, 'ebay'])->whereNotNull('external_category_id')->first();
        }
        $checks[] = ['name' => 'category_mapping', 'ok' => $mapping !== null, 'local_category_id' => $part?->category_id, 'external_category_id' => $mapping?->external_category_id ?? null];

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
            'checks' => $checks,
            'ebay_http_errors' => $errors,
            'likely_cause' => $failed[0]['name'] ?? ($errors[0]['message'] ?? null),
            'recommendations' => array_values(array_unique($recommendations)),
        ]);
    }
}
