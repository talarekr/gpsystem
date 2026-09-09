<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class JarekGearboxEbayPriceApplyService
{
    private const OFFER_FIELDS = ['availableQuantity', 'categoryId', 'charity', 'extendedProducerResponsibility', 'format', 'hideBuyerDetails', 'includeCatalogProductDetails', 'listingDescription', 'listingDuration', 'listingPolicies', 'lotSize', 'marketplaceId', 'merchantLocationKey', 'pricingSummary', 'quantityLimitPerBuyer', 'regulatory', 'secondaryCategoryId', 'sku', 'storeCategoryNames', 'tax'];

    /** @param array<int, int> $selectedIds */
    public function apply(string $snapshotId, int $limit, int $offset, array $selectedIds = [], ?string $runId = null): array
    {
        $preview = app(JarekGearboxEbayBulkPricePreviewService::class)->preview(7, 'ebay_de');
        if (! hash_equals($preview['snapshot_id'], $snapshotId)) return $this->blocked('preview_snapshot_changed');
        $rows = collect($preview['eligible_products']);
        $rows = $selectedIds !== [] ? $rows->whereIn('jarek_gearbox_id', $selectedIds) : $rows->slice($offset, $limit);
        $rows = $rows->take(min(10, $limit))->values();
        if ($rows->isEmpty()) return $this->blocked('no_eligible_jarek_gearboxes_selected');
        $batchId = (string) Str::uuid();
        $runId ??= $batchId;
        $results = [];
        $badRequests = 0;
        $stop = null;
        foreach ($rows as $row) {
            $result = $this->update($row, $snapshotId, $runId, $batchId);
            $results[] = $result;
            if (($result['http_status'] ?? null) === 400 && ++$badRequests > 3) $stop = 'more_than_3_http_400_errors_in_batch';
            if (($result['stop_runner'] ?? false) || $stop) { $stop ??= $result['error'] ?? 'fatal_error'; break; }
        }
        return ['ok' => $stop === null, 'applied' => true, 'marketplace_write' => collect($results)->contains('marketplace_write', true), 'apply_run_id' => $runId, 'apply_batch_id' => $batchId, 'count' => count($results), 'results' => $results, 'stop_reason' => $stop, 'full_autorun' => false];
    }

    private function update(array $row, string $snapshotId, string $runId, string $batchId): array
    {
        $product = JarekGearbox::query()->findOrFail($row['jarek_gearbox_id']);
        if (data_get($product->ebay_payload_snapshot, '_jarek_price_apply.snapshot_id') === $snapshotId && data_get($product->ebay_payload_snapshot, '_jarek_price_apply.status') === 'success') {
            return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'already_updated', null, null, false, 'Previously completed for this snapshot/run; no API request.');
        }
        $fetchedAt = data_get($product->ebay_payload_snapshot, '_jarek_price_fetch.fetched_at');
        if (! $fetchedAt || now()->diffInHours($fetchedAt, true) > config('marketplace.jarek_ebay_price_cache_max_age_hours', 24)) return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'skipped', null, null, false, 'ebay_price_cache_stale');

        $account = MarketplaceAccount::query()->where('code', 'ebay_de')->firstOrFail();
        $headers = $this->inventoryApiHeaders();
        $client = Http::withToken((string) data_get($account, 'api_credentials.access_token'))->withHeaders($headers)->timeout(30);
        $url = rtrim((string) $account->api_base_url, '/').'/sell/inventory/v1/offer/'.rawurlencode($row['ebay_offer_id']);
        try { $read = $this->requestWithOneServerRetry(fn () => $client->get($url)); }
        catch (Throwable $e) { return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'failed', null, null, false, 'GET timeout after retry', true); }
        if (! $read->successful()) return $this->httpFailure($row, $product, $snapshotId, $runId, $batchId, $read, 'GET');
        $offer = is_array($read->json()) ? $read->json() : [];
        $remotePrice = data_get($offer, 'pricingSummary.price.value');
        $remoteCurrency = data_get($offer, 'pricingSummary.price.currency');
        if (! is_numeric($remotePrice) || $remoteCurrency !== $row['currency']) return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'skipped', $read->status(), $read, false, 'remote_price_drift');
        if (abs((float) $remotePrice - (float) $row['new_price']) <= .01) return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'already_updated', $read->status(), $read, false, 'Remote price already equals new_price; PUT omitted.', false, true);
        if (abs((float) $remotePrice - (float) $row['old_price']) > .01) return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'skipped', $read->status(), $read, false, 'remote_price_drift');
        $payload = array_intersect_key($offer, array_flip(self::OFFER_FIELDS));
        if (! isset($payload['pricingSummary']['price']) || blank($payload['marketplaceId'] ?? null) || blank($payload['sku'] ?? null)) return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'failed', null, null, false, 'offer_cannot_be_safely_preserved', true);
        data_set($payload, 'pricingSummary.price.value', number_format((float) $row['new_price'], 2, '.', ''));
        try { $write = $this->requestWithOneServerRetry(fn () => $client->asJson()->put($url, $payload)); }
        catch (Throwable $e) { return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'failed', null, null, true, 'PUT timeout after retry', true); }
        if (! $write->successful()) return $this->httpFailure($row, $product, $snapshotId, $runId, $batchId, $write, 'PUT', true);
        return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'success', $write->status(), $write, true, 'Price-only existing eBay offer update.', false, true);
    }

    private function requestWithOneServerRetry(callable $request): Response
    {
        try { $response = $request(); } catch (ConnectionException $e) { usleep(250000); return $request(); }
        if ($response->serverError()) { usleep(250000); return $request(); }
        return $response;
    }

    private function httpFailure(array $row, JarekGearbox $product, string $snapshotId, string $runId, string $batchId, Response $response, string $method, bool $wrote = false): array
    {
        $status = $response->status();
        $reason = $status === 429 ? 'throttle' : (in_array($status, [401, 403], true) ? 'authentication_or_authorization' : "{$method}_http_{$status}");
        $perItem400 = $status === 400;
        return $this->logResult($row, $product, $snapshotId, $runId, $batchId, 'failed', $status, $response, $wrote, $reason, ! $perItem400);
    }

    private function logResult(array $row, JarekGearbox $product, string $snapshotId, string $runId, string $batchId, string $status, ?int $httpStatus, ?Response $response, bool $wrote, string $message, bool $stop = false, bool $markComplete = false): array
    {
        $requestId = $response?->header('x-ebay-c-request-id') ?: $response?->header('x-ebay-correlation-id');
        $error = $response && ! $response->successful() ? $this->sanitize(is_array($response->json()) ? $response->json() : ['message' => mb_substr($response->body(), 0, 4000)]) : null;
        $ebayError = is_array(data_get($error, 'errors.0')) ? data_get($error, 'errors.0') : ($error ? ['message' => $error['message'] ?? $message] : null);
        if ($ebayError && filled($ebayError['message'] ?? null)) $message = (string) $ebayError['message'];
        if ($markComplete) { $snapshot = (array) $product->ebay_payload_snapshot; $snapshot['_jarek_price_apply'] = ['snapshot_id' => $snapshotId, 'apply_run_id' => $runId, 'apply_batch_id' => $batchId, 'status' => 'success', 'old_price' => $row['old_price'], 'new_price' => $row['new_price'], 'applied_at' => now()->toIso8601String()]; $product->forceFill(['ebay_payload_snapshot' => $snapshot])->saveQuietly(); }
        $headers = $this->headersSummary();
        MarketplaceSyncLog::query()->create(['marketplace' => 'ebay_de', 'action' => 'jarek_gearboxes_ebay_bulk_price_increase_apply', 'status' => in_array($status, ['success', 'already_updated'], true) ? 'success' : $status, 'http_status' => $httpStatus, 'request_id' => $requestId, 'external_id' => $row['ebay_offer_id'], 'message' => $message, 'payload' => ['apply_run_id' => $runId, 'apply_batch_id' => $batchId, 'snapshot_id' => $snapshotId, 'jarek_gearbox_id' => $product->id, 'sku' => $row['sku'], 'ebay_offer_id' => $row['ebay_offer_id'], 'ebay_listing_id' => $row['ebay_listing_id'], 'old_price' => $row['old_price'], 'new_price' => $row['new_price'], 'currency' => $row['currency'], 'result_status' => $status, 'http_status' => $httpStatus, 'request_id' => $requestId, 'request_headers_summary' => $headers, 'request' => ['method' => $wrote ? 'PUT' : 'GET/none', 'resource' => '/sell/inventory/v1/offer/{offerId}', 'changed_fields' => $wrote ? ['pricingSummary.price.value'] : [], 'quantity_preserved' => true], 'ebay_error' => $ebayError, 'error_body_sanitized' => $error, 'marketplace_write' => $wrote, 'secrets_logged' => false], 'created_at' => now()]);
        return $row + ['ok' => in_array($status, ['success', 'already_updated'], true), 'status' => $status, 'marketplace_write' => $wrote, 'http_status' => $httpStatus, 'request_id' => $requestId, 'request_headers_summary' => $headers, 'error' => $status === 'success' ? null : $message, 'stop_runner' => $stop, 'price_accepted' => in_array($status, ['success', 'already_updated'], true), 'ebay_error' => $ebayError, 'error_body_sanitized' => $error];
    }

    private function inventoryApiHeaders(): array { return ['X-EBAY-C-MARKETPLACE-ID' => 'EBAY_DE', 'Content-Language' => 'de-DE', 'Accept-Language' => 'de-DE', 'Content-Type' => 'application/json', 'Accept' => 'application/json']; }
    private function headersSummary(): array { return ['marketplace_id' => 'EBAY_DE', 'content_language' => 'de-DE', 'accept_language' => 'de-DE', 'content_type' => 'application/json', 'accept' => 'application/json']; }
    private function blocked(string $reason): array { return ['ok' => false, 'applied' => false, 'marketplace_write' => false, 'error' => $reason]; }
    private function sanitize(array $value): array { foreach ($value as $key => $item) { if (preg_match('/token|authorization|credential|secret|password|cookie/i', (string) $key)) $value[$key] = '[REDACTED]'; elseif (is_array($item)) $value[$key] = $this->sanitize($item); } return $value; }
}
