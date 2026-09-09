<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class JarekGearboxEbayBulkPricePreviewService
{
    public const INACTIVE_STATUSES = ['ended', 'end', 'closed', 'inactive', 'stale', 'deleted', 'failed'];

    /** @return array<string, mixed> */
    public function preview(float $percent): array
    {
        $products = Schema::hasTable('jarek_gearboxes')
            ? JarekGearbox::query()->orderBy('id')->get()
            : collect();
        $rows = $products->map(fn (JarekGearbox $product): array => $this->inspect($product, $percent));
        $listed = $rows->where('has_ebay_listing', true);
        $eligible = $rows->where('eligible', true);
        $skipped = $rows->where('eligible', false);
        $currencies = $eligible->groupBy('currency')->map(fn (Collection $items, string $currency): array => [
            'currency' => $currency,
            'count' => $items->count(),
            'total_old_price' => $this->sum($items, 'old_price'),
            'total_new_price' => $this->sum($items, 'new_price'),
            'total_difference' => $this->sum($items, 'difference'),
        ])->values();
        $accounts = $this->accountDiagnostics();

        return [
            'ok' => true,
            'dry_run' => true,
            'read_only' => true,
            'marketplace_write' => false,
            'external_api_requests' => false,
            'percent' => $percent,
            'snapshot_id' => hash('sha256', json_encode($rows->map(fn (array $row): array => [
                $row['id'], $row['ebay_channel'], $row['ebay_offer_id'], $row['ebay_listing_id'], $row['sku'], $row['old_price'], $row['new_price'],
            ])->all(), JSON_THROW_ON_ERROR).'|'.$percent),
            'total_jarek_products' => $products->count(),
            'products_with_ebay_listing' => $listed->count(),
            'products_without_ebay_listing' => $products->count() - $listed->count(),
            'products_eligible_for_price_increase' => $eligible->count(),
            'products_skipped' => $skipped->count(),
            'skipped_reasons' => $skipped->flatMap(fn (array $row): array => $row['skipped_reasons'])->countBy()->sortDesc(),
            'total_old_price' => $this->sum($eligible, 'old_price'),
            'total_new_price' => $this->sum($eligible, 'new_price'),
            'total_difference' => $this->sum($eligible, 'difference'),
            'currency_summary' => $currencies,
            'ebay_channel_summary' => [
                'ebay_de' => $listed->where('ebay_channel', 'ebay_de')->count(),
                'ebay_fr' => $listed->where('ebay_channel', 'ebay_fr')->count(),
            ],
            'identifier_diagnostics' => [
                'missing_offer_id' => $listed->whereNull('ebay_offer_id')->count(),
                'missing_listing_id' => $listed->whereNull('ebay_listing_id')->count(),
                'missing_sku' => $listed->whereNull('sku')->count(),
                'duplicate_offer_ids' => $this->duplicates($listed, 'ebay_offer_id'),
                'duplicate_skus' => $this->duplicates($listed, 'sku'),
                'inactive_or_stale' => $listed->where('active', false)->count(),
            ],
            'safety_diagnostics' => [
                'ebay_connections' => $accounts,
                'ebay_disabled_blocks_apply' => true,
                'jarek_model_observers_detected' => false,
                'local_price_update_auto_revise_risk' => false,
                'reason' => 'JarekGearbox has no price-sync observer; Parts price sync is a separate service and this preview performs no model updates.',
                'price_only_revise_available' => false,
                'price_only_revise_scope' => 'The existing bulk_update_price_quantity adapter also sends quantity; apply remains HTTP 501 until a verified price-only client path exists.',
                'main_ebay_status_channel' => 'ebay_de',
            ],
            'price_source' => [
                'publication_source' => 'eBay Inventory API offer price only; jarek_gearboxes.price is never used',
                'preview_old_ebay_price' => 'cached eBay offer fetch, falling back to an eBay payload snapshot',
                'missing_snapshot_policy' => 'needs_ebay_price_fetch; never infer from a local product price',
            ],
            'sample_products' => $rows->take(50)->map(fn (array $row): array => collect($row)->except(['has_ebay_listing', 'eligible', 'old_price', 'difference'])->all())->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function inspect(JarekGearbox $product, float $percent): array
    {
        $snapshot = (array) $product->ebay_payload_snapshot;
        $offerId = $this->value($product->ebay_offer_id);
        $listingId = $this->value($product->ebay_listing_id);
        $sku = $this->value($product->ebay_inventory_sku);
        $hasListing = $offerId !== null || $listingId !== null || $sku !== null;
        $status = strtolower(trim((string) $product->ebay_status));
        $active = $hasListing && ! in_array($status, self::INACTIVE_STATUSES, true);
        $channel = strtoupper((string) data_get($snapshot, 'marketplaceId', data_get($snapshot, 'json.marketplaceId', 'EBAY_DE'))) === 'EBAY_FR' ? 'ebay_fr' : 'ebay_de';
        [$oldPrice, $currency, $pricePath] = $this->snapshotPrice($snapshot);
        $reasons = [];
        if (! $hasListing) $reasons[] = 'no_ebay_listing';
        if (! $active && $hasListing) $reasons[] = 'inactive_ended_or_stale_listing';
        if ($offerId === null && $hasListing) $reasons[] = 'missing_offer_id';
        if ($sku === null && $hasListing) $reasons[] = 'missing_sku';
        if ($oldPrice === null) $reasons[] = 'needs_ebay_price_fetch';
        elseif ($oldPrice <= 0) $reasons[] = 'non_positive_price';
        if ($currency === null) $reasons[] = 'missing_currency';
        $eligible = $reasons === [];
        $newPrice = $eligible ? round($oldPrice * (1 + $percent / 100), 2) : null;

        return [
            'id' => $product->id,
            'jarek_gearbox_id' => $product->id,
            'title' => $product->title,
            'sku' => $sku,
            'ebay_offer_id' => $offerId,
            'ebay_listing_id' => $listingId,
            'ebay_channel' => $channel,
            'current_local_price' => $product->price !== null ? (float) $product->price : null,
            'current_ebay_price' => $oldPrice,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'difference' => $eligible ? round($newPrice - $oldPrice, 2) : null,
            'currency' => $currency,
            'ebay_status' => $product->ebay_status,
            'active' => $active,
            'price_source' => $pricePath,
            'fetched_at' => data_get($snapshot, '_jarek_price_fetch.fetched_at'),
            'revise_would_be_needed' => $eligible && $newPrice !== $oldPrice,
            'has_ebay_listing' => $hasListing,
            'eligible' => $eligible,
            'skipped_reasons' => $reasons,
        ];
    }

    /** @return array{0: ?float, 1: ?string, 2: ?string} */
    private function snapshotPrice(array $snapshot): array
    {
        foreach (['_jarek_price_fetch.pricingSummary.price', 'pricingSummary.price', 'json.pricingSummary.price', 'offer.pricingSummary.price'] as $path) {
            $price = data_get($snapshot, $path);
            if (is_array($price) && is_numeric($price['value'] ?? null)) return [(float) $price['value'], $this->value($price['currency'] ?? null), str_starts_with($path, '_jarek_price_fetch') ? 'ebay_offer_fetch_cache' : 'ebay_payload_snapshot'];
        }

        return [null, null, null];
    }

    private function sum(Collection $rows, string $field): float
    {
        return round((float) $rows->sum($field), 2);
    }

    /** @return array<string, int> */
    private function duplicates(Collection $rows, string $field): array
    {
        return $rows->pluck($field)->filter()->countBy()->filter(fn (int $count): bool => $count > 1)->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function accountDiagnostics(): array
    {
        $result = [];
        foreach (['ebay_de', 'ebay_fr'] as $channel) {
            $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $channel)->first() : null;
            $result[$channel] = ['configured' => $account !== null, 'enabled' => (bool) $account?->api_enabled, 'status' => $account?->status];
        }
        return $result;
    }

    private function value(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
