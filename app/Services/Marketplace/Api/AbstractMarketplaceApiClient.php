<?php

namespace App\Services\Marketplace\Api;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class AbstractMarketplaceApiClient implements MarketplaceApiClient
{
    public function __construct(protected string $channel, protected ?MarketplaceAccount $account) {}

    abstract protected function requiredCredentialKeys(): array;
    abstract protected function optionalCredentialKeys(): array;
    abstract protected function endpointPath(): string;
    abstract protected function requestSample(int $limit): array;
    abstract protected function extractOffers(array $payload): array;

    public function getAccountReadiness(): array
    {
        $credentials = $this->credentials();
        $blockers = [];
        $warnings = [];
        if (! $this->account) $blockers[] = 'Marketplace account not found.';
        if ($this->account && ! $this->account->api_enabled) $blockers[] = 'API is not enabled.';
        if ($this->account && blank($this->account->api_base_url)) $blockers[] = 'API base URL is missing.';
        if ($this->account && ! in_array($this->account->api_mode, ['dry_run', 'read_only'], true)) $blockers[] = 'API mode must be dry_run or read_only.';
        foreach ($this->requiredCredentialKeys() as $key) if (blank($credentials[$key] ?? null)) $blockers[] = "Credential {$key} is missing.";
        if ($this->account && $this->account->api_mode === 'live') $warnings[] = 'Live mode is blocked by read-only foundation endpoints.';

        return [
            'account_exists' => $this->account !== null,
            'api_enabled' => (bool) ($this->account?->api_enabled ?? false),
            'api_base_url' => $this->account?->api_base_url,
            'api_mode' => $this->account?->api_mode,
            'credentials_configured' => $this->credentialsConfigured(),
            ...$this->credentialConfiguredFlags(),
            'last_connection_check_at' => $this->account?->last_connection_check_at?->toISOString(),
            'last_connection_status' => $this->account?->last_connection_status,
            'last_connection_message' => $this->account?->last_connection_message,
            'supports_read_only_test' => true,
            'supports_offer_sample' => true,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    public function testConnection(): array
    {
        return $this->performReadOnlyRequest(1, true);
    }

    public function fetchOffersSample(int $limit): array
    {
        return $this->performReadOnlyRequest(max(1, min($limit, 50)), false);
    }

    protected function performReadOnlyRequest(int $limit, bool $connectionOnly): array
    {
        $checkedAt = now();
        $base = [
            'ok' => true, 'channel' => $this->channel, 'connection_ok' => false, 'api_ok' => false,
            'http_status' => null, 'endpoint_used' => $this->endpointUsed($limit),
            'api_mode' => $this->account?->api_mode, 'credentials_present' => $this->credentialsConfigured(),
            'response_sample_safe' => null, 'count' => 0, 'sample_external_ids' => [],
            'error_message_safe' => null, 'checked_at' => $checkedAt->toISOString(),
        ];
        $readiness = $this->getAccountReadiness();
        if ($readiness['blockers'] !== []) return array_merge($base, ['ok' => false, 'error_message_safe' => implode(' ', $readiness['blockers'])]);
        try {
            $result = $this->requestSample($limit);
            $payload = is_array($result['json'] ?? null) ? $result['json'] : [];
            $offers = $this->extractOffers($payload);
            $ok = (bool) ($result['api_ok'] ?? ($result['http_status'] >= 200 && $result['http_status'] < 300));
            $message = $ok ? 'Read-only marketplace API request succeeded.' : (string) ($result['error'] ?? 'Marketplace API returned a non-success response.');
            $this->storeStatus($ok ? 'ok' : 'failed', $message);
            $sample = array_map(fn ($offer) => $offer['external_offer_id'] ?? null, array_slice($offers, 0, 5));
            return array_merge($base, [
                'connection_ok' => $ok, 'api_ok' => $ok, 'http_status' => $result['http_status'] ?? null,
                'response_sample_safe' => $this->safeSample($payload, $offers), 'count' => count($offers),
                'sample_external_ids' => array_values(array_filter($sample)), 'error_message_safe' => $ok ? null : $message,
                'offers' => $connectionOnly ? null : $this->withLocalComparison($offers),
            ]);
        } catch (ConnectionException|Throwable) {
            $message = 'Read-only marketplace API request failed without exposing credentials.';
            $this->storeStatus('failed', $message);
            return array_merge($base, ['ok' => false, 'error_message_safe' => $message]);
        }
    }

    protected function withLocalComparison(array $offers): array
    {
        $ids = array_values(array_filter(array_map(fn ($o) => $o['external_offer_id'] ?? null, $offers)));
        $listings = MarketplaceListing::query()->with('part')->where('marketplace', $this->marketplaceCode())->whereIn('external_offer_id', $ids)->get()->keyBy('external_offer_id');
        return array_map(function (array $offer) use ($listings) {
            $listing = $listings->get((string) ($offer['external_offer_id'] ?? ''));
            $part = $listing?->part;
            return $offer + [
                'matched_to_part' => $part !== null, 'part_id' => $part?->id, 'part_number' => $part?->part_number,
                'local_price' => $part?->price, 'local_quantity' => $part?->quantity,
                'listing_exists' => $listing !== null, 'conflict_detected' => $listing && $listing->part_id === null,
            ];
        }, $offers);
    }

    protected function credentials(): array { return is_array($this->account?->api_credentials) ? $this->account->api_credentials : []; }
    protected function credentialsConfigured(): bool { foreach ($this->requiredCredentialKeys() as $key) if (blank($this->credentials()[$key] ?? null)) return false; return $this->requiredCredentialKeys() !== []; }
    protected function credentialConfiguredFlags(): array { $flags = []; foreach (array_unique([...$this->requiredCredentialKeys(), ...$this->optionalCredentialKeys()]) as $key) $flags[$key.'_configured'] = filled($this->credentials()[$key] ?? null); return $flags; }
    protected function endpointUsed(int $limit): string { return rtrim((string) $this->account?->api_base_url, '/').$this->endpointPath().'?limit='.$limit; }
    protected function marketplaceCode(): string { return str_starts_with($this->channel, 'ebay_') ? 'ebay' : ($this->channel === 'allegro_main' ? 'allegro' : 'ovoko'); }
    protected function storeStatus(string $status, string $message): void { $this->account?->forceFill(['last_connection_check_at' => now(), 'last_connection_status' => $status, 'last_connection_message' => $message])->save(); }
    protected function safeSample(array $payload, array $offers): array { return ['top_level_keys' => array_slice(array_keys($payload), 0, 20), 'offer_count' => count($offers), 'first_offer_keys' => array_slice(array_keys($offers[0] ?? []), 0, 20)]; }
}
