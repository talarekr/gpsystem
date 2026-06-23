<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Marketplace\EbayListingDryRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class EbayListingDryRunController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __construct(private readonly EbayListingDryRunService $service) {}

    public function readiness(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $payload = $this->service->readiness((int) $request->integer('part_id'), (string) $request->query('channel', 'ebay_de'));
        return response()->json($payload, ($payload['ready'] ?? false) ? 200 : 422);
    }

    public function dryRunPayload(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $payload = $this->service->dryRunPayload((int) $request->integer('part_id'), (string) $request->query('channel', 'ebay_de'));
        return response()->json($payload, ($payload['blockers'] ?? []) === [] ? 200 : 422);
    }

    public function compatibility(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $payload = $this->service->compatibilityDiagnostics((int) $request->integer('part_id'), (string) $request->query('channel', 'ebay_de'));
        return response()->json($payload, ($payload['blockers'] ?? []) === [] ? 200 : 422);
    }

    public function dryRunCompatibilityPayload(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $payload = $this->service->dryRunCompatibilityPayload((int) $request->integer('part_id'), (string) $request->query('channel', 'ebay_de'));
        return response()->json($payload, ($payload['blockers'] ?? []) === [] ? 200 : 422);
    }

    public function readinessAll(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $payload = $this->service->readinessAll((int) $request->integer('part_id'));
        return response()->json($payload, ($payload['overall_ready'] ?? false) ? 200 : 422);
    }


    public function checkAccountPolicySettings(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();

        return response()->json([
            'ok' => true,
            'channels' => collect($this->policyDefaults())->mapWithKeys(fn (array $defaults, string $channel) => [$channel => $this->policySettingsPayload($channel, $defaults)])->all(),
            'notes' => ['read_only' => 'Diagnostics only; no eBay API write, listing publish, price/stock sync, or product mutation is performed.'],
        ]);
    }

    public function setAccountPolicySettings(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        if ((string) $request->query('confirm', '') !== '1') {
            return response()->json(['ok' => false, 'blockers' => ['Missing confirm=1.']], 422);
        }
        if (! Schema::hasTable('marketplace_accounts')) {
            return response()->json(['ok' => false, 'blockers' => ['marketplace_accounts table is missing.']], 422);
        }

        $updated = [];
        foreach ($this->policyDefaults() as $channel => $defaults) {
            $account = MarketplaceAccount::query()->firstOrCreate(
                ['code' => $channel],
                ['marketplace' => $channel, 'name' => $defaults['name'], 'status' => 'active', 'api_base_url' => 'https://api.ebay.com', 'api_mode' => 'dry_run']
            );
            $settings = is_array($account->api_settings) ? $account->api_settings : [];
            $settings['payment_policy_id'] = $defaults['payment_policy_id'];
            $settings['return_policy_id'] = $defaults['return_policy_id'];
            $settings['marketplace_id'] = $settings['marketplace_id'] ?? $defaults['marketplace_id'];
            $settings['site_id'] = $settings['site_id'] ?? $defaults['site_id'];
            $account->api_settings = $settings;
            $account->save();
            $updated[$channel] = $this->policySettingsPayload($channel, $defaults);
        }

        return response()->json([
            'ok' => true,
            'channels' => $updated,
            'notes' => ['local_only' => 'Updated only marketplace_accounts.api_settings. No eBay API write, listing publish, price/stock sync, or product mutation was performed.'],
        ]);
    }

    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }

    private function policySettingsPayload(string $channel, array $defaults): array
    {
        $blockers = [];
        $warnings = [];
        if (! Schema::hasTable('marketplace_accounts')) $blockers[] = 'marketplace_accounts table is missing.';
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $channel)->first() : null;
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $apiPayment = filled($settings['payment_policy_id'] ?? null) ? (string) $settings['payment_policy_id'] : null;
        $apiReturn = filled($settings['return_policy_id'] ?? null) ? (string) $settings['return_policy_id'] : null;
        $resolvedPayment = $this->service->resolvedPaymentPolicyId($settings);
        $resolvedReturn = $this->service->resolvedReturnPolicyId($settings);
        if (! $account) $blockers[] = 'Marketplace account not found.';
        if ($account && $apiPayment === null) $blockers[] = 'api_settings.payment_policy_id is missing.';
        if ($account && $apiReturn === null) $blockers[] = 'api_settings.return_policy_id is missing.';
        if ($resolvedPayment !== $apiPayment || $resolvedReturn !== $apiReturn) $warnings[] = 'Resolved values include fallback keys; direct api_settings values differ.';

        return [
            'account_exists' => $account !== null,
            'api_settings_payment_policy_id' => $apiPayment,
            'api_settings_return_policy_id' => $apiReturn,
            'resolved_payment_policy_id' => $resolvedPayment,
            'resolved_return_policy_id' => $resolvedReturn,
            'default_payment_policy_id' => $defaults['payment_policy_id'],
            'default_return_policy_id' => $defaults['return_policy_id'],
            'readiness_reads_same_values' => $apiPayment !== null && $apiReturn !== null && $resolvedPayment === $apiPayment && $resolvedReturn === $apiReturn,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function policyDefaults(): array
    {
        return [
            'ebay_de' => ['name' => 'eBay DE', 'marketplace_id' => 'EBAY_DE', 'site_id' => '77', 'payment_policy_id' => '259264220013', 'return_policy_id' => '259264151013'],
            'ebay_fr' => ['name' => 'eBay FR', 'marketplace_id' => 'EBAY_FR', 'site_id' => '71', 'payment_policy_id' => '260547435013', 'return_policy_id' => '260547447013'],
        ];
    }
    private function invalidToken(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
}
