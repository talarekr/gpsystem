<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Http;

class JarekGearboxEbayPriceFetchService
{
    /** @return array<string, mixed> */
    public function fetch(string $channel, int $limit, int $offset, bool $onlyActive, bool $onlyMissing, bool $cache): array
    {
        $account = MarketplaceAccount::query()->where('code', $channel)->first();
        $marketplaceId = strtoupper($channel);
        $query = JarekGearbox::query()->where(function ($query): void {
            $query->whereNotNull('ebay_offer_id')->orWhereNotNull('ebay_inventory_sku');
        })->orderBy('id');
        if ($onlyActive) $query->where(function ($query): void {
            $query->whereNull('ebay_status')->orWhereNotIn('ebay_status', JarekGearboxEbayBulkPricePreviewService::INACTIVE_STATUSES);
        });
        $candidates = $query->get()->filter(fn (JarekGearbox $product): bool => $this->channel($product) === $channel);
        if ($onlyMissing) $candidates = $candidates->filter(fn (JarekGearbox $product): bool => data_get($product->ebay_payload_snapshot, '_jarek_price_fetch.pricingSummary.price.value') === null);
        $products = $candidates->slice($offset, $limit)->values();

        $rows = $products->map(function (JarekGearbox $product) use ($account, $marketplaceId, $cache): array {
            $offerId = $this->value($product->ebay_offer_id);
            if ($offerId === null) return $this->missingOfferRow($product);
            $response = Http::withToken((string) data_get($account, 'api_credentials.access_token'))
                ->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => $marketplaceId])->acceptJson()->timeout(20)
                ->get(rtrim((string) $account?->api_base_url, '/').'/sell/inventory/v1/offer/'.rawurlencode($offerId));
            $json = is_array($response->json()) ? $response->json() : [];
            $price = is_numeric(data_get($json, 'pricingSummary.price.value')) ? (float) data_get($json, 'pricingSummary.price.value') : null;
            $currency = $this->value(data_get($json, 'pricingSummary.price.currency'));
            $status = $this->value($json['status'] ?? data_get($json, 'listing.status') ?? $json['publicationStatus'] ?? null);
            $eligible = $response->successful() && $price !== null && $price > 0 && $currency !== null && ! $this->inactive($status);
            $reason = $eligible ? null : $this->reason($response->status(), $price, $currency, $status);
            $fetchedAt = now()->toIso8601String();
            if ($cache && $response->successful() && $price !== null && $currency !== null) {
                $snapshot = (array) $product->ebay_payload_snapshot;
                $snapshot['_jarek_price_fetch'] = ['pricingSummary' => ['price' => ['value' => number_format($price, 2, '.', ''), 'currency' => $currency]], 'offer_status' => $status, 'marketplaceId' => $json['marketplaceId'] ?? $marketplaceId, 'fetched_at' => $fetchedAt, 'http_status' => $response->status()];
                $product->forceFill(['ebay_payload_snapshot' => $snapshot])->saveQuietly();
            }
            return $this->baseRow($product) + ['ebay_offer_status' => $status, 'marketplace_id' => $json['marketplaceId'] ?? $marketplaceId, 'current_ebay_price' => $price, 'current_ebay_currency' => $currency, 'proposed_new_price' => $eligible ? round($price * 1.07, 2) : null, 'rounding' => 'round_half_up_to_2_decimals', 'eligible_for_apply' => $eligible, 'skipped_reason' => $reason, 'http_status' => $response->status(), 'ebay_error' => $response->successful() ? null : $this->safeError($json), 'ebay_request_id' => $response->header('x-ebay-c-request-id') ?: $response->header('x-ebay-correlation-id'), 'fetched_at' => $fetchedAt];
        });

        $all = JarekGearbox::query()->get();
        $withOffer = $all->filter(fn (JarekGearbox $product): bool => $this->value($product->ebay_offer_id) !== null);
        $active = $withOffer->filter(fn (JarekGearbox $product): bool => ! $this->inactive($this->value($product->ebay_status)));
        return ['ok' => true, 'marketplace_write' => false, 'external_api_requests' => true, 'local_write' => $cache, 'channel' => $channel, 'limit' => $limit, 'offset' => $offset, 'count' => $rows->count(), 'total_jarek_products' => $all->count(), 'products_with_ebay_offer_id' => $withOffer->count(), 'products_without_ebay_offer_id' => $all->count() - $withOffer->count(), 'active_ebay_products' => $active->count(), 'inactive_or_stale' => $withOffer->count() - $active->count(), 'prices_fetched_from_ebay_count' => $rows->whereNotNull('current_ebay_price')->count(), 'prices_missing_count' => $rows->whereNull('current_ebay_price')->count(), 'stale_404_count' => $rows->where('http_status', 404)->count(), 'eligible_for_7_percent_increase' => $rows->where('eligible_for_apply', true)->count(), 'skipped_reasons' => $rows->pluck('skipped_reason')->filter()->countBy(), 'currency_summary' => $rows->pluck('current_ebay_currency')->filter()->countBy(), 'sample_products' => $rows->take(50)->all(), 'products' => $rows->all()];
    }

    private function baseRow(JarekGearbox $p): array { return ['jarek_gearbox_id' => $p->id, 'title' => $p->title, 'ebay_offer_id' => $this->value($p->ebay_offer_id), 'ebay_listing_id' => $this->value($p->ebay_listing_id), 'ebay_inventory_sku' => $this->value($p->ebay_inventory_sku), 'ebay_status' => $p->ebay_status]; }
    private function missingOfferRow(JarekGearbox $p): array { return $this->baseRow($p) + ['ebay_offer_status' => null, 'marketplace_id' => null, 'current_ebay_price' => null, 'current_ebay_currency' => null, 'proposed_new_price' => null, 'rounding' => 'round_half_up_to_2_decimals', 'eligible_for_apply' => false, 'skipped_reason' => 'missing_offer_id', 'http_status' => null, 'ebay_error' => null, 'ebay_request_id' => null, 'fetched_at' => null]; }
    private function channel(JarekGearbox $p): string { return strtoupper((string) data_get($p->ebay_payload_snapshot, '_jarek_price_fetch.marketplaceId', data_get($p->ebay_payload_snapshot, 'marketplaceId', 'EBAY_DE'))) === 'EBAY_FR' ? 'ebay_fr' : 'ebay_de'; }
    private function inactive(?string $status): bool { return $status !== null && in_array(strtolower($status), JarekGearboxEbayBulkPricePreviewService::INACTIVE_STATUSES, true); }
    private function reason(int $http, ?float $price, ?string $currency, ?string $status): string { if (in_array($http, [401, 403], true)) return 'ebay_auth_token_problem'; if ($http === 404) return 'ebay_offer_not_found_or_stale'; if (in_array($http, [400, 409, 422], true)) return 'ebay_payload_or_offer_status_problem'; if ($http >= 500) return 'retryable_ebay_internal_error'; if ($this->inactive($status)) return 'inactive_ebay_offer'; if ($price === null || $currency === null) return 'missing_ebay_price_or_currency'; return 'ebay_read_failed'; }
    private function safeError(array $json): array { return ['errors' => array_map(fn ($e) => array_intersect_key((array) $e, array_flip(['errorId', 'domain', 'category', 'message', 'longMessage', 'parameters'])), (array) ($json['errors'] ?? []))]; }
    private function value(mixed $value): ?string { $value = trim((string) ($value ?? '')); return $value === '' ? null : $value; }
}
