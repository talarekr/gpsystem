<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategory;
use App\Models\Part;
use Illuminate\Support\Facades\Schema;

class AllegroCategoryConsistencyGuard
{
    public const MISMATCH_MARKER = 'allegro_reject_catalog_product_category_mismatch_v3';

    /** @return array<string, mixed> */
    public function diagnose(Part $part, array $payload, ?array $body = null): array
    {
        $offerCategoryId = $this->stringOrNull(data_get($body ?? $payload, 'category.id') ?? data_get($payload, 'category_id'));
        $productCategoryId = $this->stringOrNull(data_get($body ?? $payload, 'productSet.0.product.category.id'));
        $productId = $this->stringOrNull(data_get($body ?? $payload, 'productSet.0.product.id'));
        $productName = $this->stringOrNull(data_get($body ?? $payload, 'productSet.0.product.name'));
        $hasExistingProduct = filled($productId);
        $categoriesMatch = $offerCategoryId !== null && $productCategoryId !== null ? $offerCategoryId === $productCategoryId : null;
        $mismatch = $categoriesMatch === false;

        return [
            'marker' => self::MISMATCH_MARKER,
            'offer_category_id' => $offerCategoryId,
            'offer_category_name' => $this->categoryName($offerCategoryId) ?? data_get($payload, 'category_mapping_name'),
            'product_set_category_id' => $productCategoryId,
            'product_set_category_name' => $this->categoryName($productCategoryId),
            'categories_match' => $categoriesMatch,
            'offer_category_source' => $offerCategoryId !== null ? (data_get($payload, 'category_mapping_source') ?: 'prepared_payload.category_id') : 'missing',
            'product_set_category_source' => $productCategoryId !== null ? 'productSet[0].product.category.id' : 'not_set_locally',
            'local_category_id' => $part->category_id,
            'category_mapping_id' => data_get($payload, 'category_mapping_id'),
            'category_mapping_source' => data_get($payload, 'category_mapping_source'),
            'category_mapping_name' => data_get($payload, 'category_mapping_name'),
            'category_mapping_path' => data_get($payload, 'category_mapping_path'),
            'product_set_source' => $hasExistingProduct ? 'existing_catalog_product' : (is_array(data_get($body ?? $payload, 'productSet.0.product')) ? 'local_builder' : 'unknown'),
            'product_set_product_id' => $productId,
            'product_set_product_name' => $productName,
            'product_set_uses_existing_allegro_catalog_product' => $hasExistingProduct,
            'mismatch_origin' => $mismatch ? 'local_payload_before_request' : ($productCategoryId === null ? 'not_present_in_local_payload_may_only_appear_in_allegro_response' : 'none'),
            'why_product_set_product_was_selected' => $hasExistingProduct ? 'productSet[0].product.id was present in prepared payload or account settings' : 'no existing Allegro catalog product id present; local product builder used',
            'payload_category_consistency_ok' => ! $mismatch,
            'payload_would_be_rejected_because_category_mismatch' => $mismatch,
            'recommended_action' => $mismatch ? 'reject_catalog_product_category_mismatch' : null,
            'blocker_message' => $mismatch ? $this->blockerMessage($offerCategoryId, $productCategoryId) : null,
        ];
    }

    public function hasBlockingMismatch(array $diagnostics): bool
    {
        return ($diagnostics['payload_would_be_rejected_because_category_mismatch'] ?? false) === true;
    }

    private function blockerMessage(?string $offerCategoryId, ?string $productCategoryId): string
    {
        $offer = trim(($this->categoryName($offerCategoryId) ?? 'nieznana kategoria').' ('.($offerCategoryId ?? 'brak').')');
        $product = trim(($this->categoryName($productCategoryId) ?? 'nieznana kategoria').' ('.($productCategoryId ?? 'brak').')');
        return 'Znaleziony produkt katalogowy Allegro jest w kategorii '.$product.', a oferta jest w kategorii '.$offer.'. Odrzucono produkt katalogowy z innej kategorii przed wysyłką do Allegro.';
    }

    private function categoryName(?string $id): ?string
    {
        if (! $id || ! Schema::hasTable('marketplace_categories')) return null;
        return MarketplaceCategory::query()->whereIn('channel', ['allegro_main', 'allegro'])->where('external_category_id', $id)->value('name');
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
