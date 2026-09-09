<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class JarekGearboxEbayPriceApplyService
{
    private const OFFER_FIELDS = ['availableQuantity', 'categoryId', 'charity', 'extendedProducerResponsibility', 'format', 'hideBuyerDetails', 'includeCatalogProductDetails', 'listingDescription', 'listingDuration', 'listingPolicies', 'lotSize', 'marketplaceId', 'merchantLocationKey', 'pricingSummary', 'quantityLimitPerBuyer', 'regulatory', 'secondaryCategoryId', 'sku', 'storeCategoryNames', 'tax'];

    /** @param array<int, int> $selectedIds */
    public function apply(string $snapshotId, int $limit, int $offset, array $selectedIds = []): array
    {
        $preview = app(JarekGearboxEbayBulkPricePreviewService::class)->preview(7, 'ebay_de');
        if (! hash_equals($preview['snapshot_id'], $snapshotId)) return $this->blocked('preview_snapshot_changed');
        $rows = collect($preview['eligible_products']);
        if ($selectedIds !== []) $rows = $rows->whereIn('jarek_gearbox_id', $selectedIds);
        else $rows = $rows->slice($offset, $limit);
        $rows = $rows->take($limit)->values();
        if ($rows->isEmpty()) return $this->blocked('no_eligible_jarek_gearboxes_selected');
        $batchId = (string) Str::uuid();
        $results = $rows->map(fn (array $row): array => $this->update($row, $batchId))->all();

        return ['ok' => collect($results)->every('ok'), 'applied' => true, 'marketplace_write' => true, 'apply_batch_id' => $batchId, 'count' => count($results), 'results' => $results, 'full_autorun' => false, 'stopped_after_canary' => true];
    }

    private function update(array $row, string $batchId): array
    {
        $product = JarekGearbox::query()->findOrFail($row['jarek_gearbox_id']);
        $fetchedAt = data_get($product->ebay_payload_snapshot, '_jarek_price_fetch.fetched_at');
        if (! $fetchedAt || now()->diffInHours($fetchedAt, true) > config('marketplace.jarek_ebay_price_cache_max_age_hours', 24)) return $this->blockedItem($row, 'ebay_price_cache_stale');
        $account = MarketplaceAccount::query()->where('code', 'ebay_de')->firstOrFail();
        $headers = ['X-EBAY-C-MARKETPLACE-ID' => 'EBAY_DE'];
        $url = rtrim((string) $account->api_base_url, '/').'/sell/inventory/v1/offer/'.rawurlencode($row['ebay_offer_id']);
        $client = Http::withToken((string) data_get($account, 'api_credentials.access_token'))->withHeaders($headers)->acceptJson()->timeout(30);
        $read = $client->get($url);
        $offer = is_array($read->json()) ? $read->json() : [];
        $remotePrice = data_get($offer, 'pricingSummary.price.value');
        $remoteCurrency = data_get($offer, 'pricingSummary.price.currency');
        if (! $read->successful() || ! is_numeric($remotePrice) || round((float) $remotePrice, 2) !== round((float) $row['old_price'], 2) || $remoteCurrency !== $row['currency']) return $this->blockedItem($row, 'remote_offer_differs_from_accepted_cache');
        $payload = array_intersect_key($offer, array_flip(self::OFFER_FIELDS));
        if (! isset($payload['pricingSummary']['price']) || blank($payload['marketplaceId'] ?? null) || blank($payload['sku'] ?? null)) return $this->blockedItem($row, 'offer_cannot_be_safely_preserved');
        data_set($payload, 'pricingSummary.price.value', number_format((float) $row['new_price'], 2, '.', ''));
        $write = $client->asJson()->put($url, $payload);
        $requestId = $write->header('x-ebay-c-request-id') ?: $write->header('x-ebay-correlation-id') ?: (string) Str::uuid();
        $ok = $write->successful();
        $snapshot = (array) $product->ebay_payload_snapshot;
        $snapshot['_jarek_price_apply'] = ['old_price' => $row['old_price'], 'new_price' => $row['new_price'], 'currency' => $row['currency'], 'applied_at' => now()->toIso8601String(), 'http_status' => $write->status(), 'request_id' => $requestId, 'apply_batch_id' => $batchId];
        $product->forceFill(['ebay_payload_snapshot' => $snapshot])->saveQuietly();
        MarketplaceSyncLog::query()->create(['marketplace' => 'ebay_de', 'action' => 'jarek_gearboxes_ebay_bulk_price_increase_apply', 'status' => $ok ? 'success' : 'error', 'http_status' => $write->status(), 'request_id' => $requestId, 'external_id' => $row['ebay_offer_id'], 'message' => 'Price-only existing eBay offer update.', 'payload' => ['marketplace_write' => true, 'apply_batch_id' => $batchId, 'jarek_gearbox_id' => $product->id, 'diff' => ['pricingSummary.price' => ['old' => $row['old_price'], 'new' => $row['new_price']], 'all_other_fields' => 'preserved'], 'request_fields' => array_keys($payload), 'response' => $this->safeResponse($write->json()), 'secrets_logged' => false], 'created_at' => now()]);
        return $row + ['ok' => $ok, 'status' => $ok ? 'success' : 'failed', 'http_status' => $write->status(), 'request_id' => $requestId, 'price_accepted' => $ok, 'listing_url' => filled($row['ebay_listing_id']) ? 'https://www.ebay.de/itm/'.$row['ebay_listing_id'] : null];
    }

    private function blocked(string $reason): array { return ['ok' => false, 'applied' => false, 'marketplace_write' => false, 'error' => $reason]; }
    private function blockedItem(array $row, string $reason): array { return $row + ['ok' => false, 'status' => 'blocked', 'marketplace_write' => false, 'error' => $reason]; }
    private function safeResponse(mixed $json): array { return is_array($json) ? array_intersect_key($json, array_flip(['offerId', 'listingId', 'status', 'errors', 'warnings'])) : []; }
}
