<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\Part;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\EbayApiClient;
use App\Services\Marketplace\EbayPanelActionAuditLogger;
use App\Services\Marketplace\EbaySkuResolver;
use App\Services\Marketplace\EbayTitleSanitizer;
use App\Services\Marketplace\Ebay\EbayConditionMapper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EbayPublishAdapter extends BaseMarketplacePublishAdapter
{
    private string $activeChannel = 'ebay_de';

    public function __construct(
        \App\Services\Marketplace\MarketplaceListingReadinessService $readinessService,
        \App\Services\Marketplace\MarketplacePublishGate $gate,
        \App\Services\Marketplace\ApiIntegrationLogger $logger,
        private readonly EbaySkuResolver $skuResolver,
        private readonly EbayTitleSanitizer $ebayTitleSanitizer,
    ) { parent::__construct($readinessService, $gate, $logger); }

    protected function channel(): string { return $this->activeChannel; }
    protected function marketplace(): string { return $this->activeChannel; }
    protected function accountCode(): string { return $this->activeChannel; }

    public function preview(Part $part): MarketplacePublishPreviewResult
    {
        return $this->withEbayChannels(fn (): MarketplacePublishPreviewResult => parent::preview($part), false);
    }

    public function publish(Part $part, MarketplacePublishCommand $command): MarketplacePublishResult
    {
        return $this->withEbayChannels(fn (): MarketplacePublishResult => parent::publish($part, $command), true);
    }

    private function withEbayChannels(callable $callback, bool $writeOperation): MarketplacePublishPreviewResult|MarketplacePublishResult
    {
        $results = [];
        $original = $this->activeChannel;

        foreach (['ebay_de'] as $channel) {
            $this->activeChannel = $channel;
            $results[$channel] = $callback()->data;
        }

        $this->activeChannel = $original;
        $success = collect($results)->every(fn (array $result): bool => (bool) ($result['success'] ?? false));
        $payload = [
            'channel' => 'ebay',
            'marketplace' => 'ebay',
            'success' => $success,
            'blocked' => ! $success,
            'errors' => collect($results)->flatMap(fn (array $result): array => $result['errors'] ?? [])->unique()->values()->all(),
            'warnings' => collect($results)->flatMap(fn (array $result): array => $result['warnings'] ?? [])->unique()->values()->all(),
            'write' => collect($results)->contains(fn (array $result): bool => (bool) ($result['write'] ?? false)),
            'channels' => $results,
            'ebay_channels' => array_keys($results),
        ];

        return $writeOperation
            ? new MarketplacePublishResult('ebay', $payload + ['status' => $success ? 'published' : 'blocked'])
            : new MarketplacePublishPreviewResult('ebay', $payload);
    }

    protected function performLivePublish(Part $part, array $readiness, array $payload, ?MarketplaceAccount $account): array
    {
        if (! $account) return ['ok' => false, 'status' => 'not_configured', 'error' => 'Marketplace account ebay_de is missing.'];
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $policies = $payload['business_policies'] ?? [];
        $sku = $this->skuFor($part, $payload);
        $payload['sku'] = $sku;
        $missing = [];
        foreach (['category_id' => 'eBay: brakuje categoryId dla wybranej kategorii', 'title' => 'eBay: brakuje title'] as $key => $message) if (blank($payload[$key] ?? null) && ($key !== 'title' || blank($part->name ?? null))) $missing[] = $message;
        if ($this->accountCode() === 'ebay_de') {
            $titleSanitization = $this->ebayTitleSanitizer->sanitizeForEbayDe($part, (string) ($payload['title'] ?? $part->name ?? ''), (string) ($part->name ?? ''));
            if (($titleSanitization['blocker'] ?? null) !== null) $missing[] = 'ebay_title_needs_review';
            $payload['title_sanitization'] = $titleSanitization['diagnostics'];
        }
        foreach (['merchant_location_key' => 'eBay: brakuje merchantLocationKey', 'selected_fulfillment_policy_id' => 'eBay: brakuje fulfillmentPolicyId', 'selected_payment_policy_id' => 'eBay: brakuje paymentPolicyId', 'selected_return_policy_id' => 'eBay: brakuje returnPolicyId'] as $key => $message) if (blank($policies[$key] ?? $this->settingForPolicy($settings, $key))) $missing[] = $message;
        if ($missing !== []) return ['ok' => false, 'status' => 'payload_invalid', 'action' => 'publishOffer', 'error' => implode('; ', $missing), 'request_summary' => $this->requestSummary($payload), 'response_summary' => ['missing' => $missing]];
        app(EbayPanelActionAuditLogger::class)->step($part, 'policies_resolved', ['selected_step' => 'publish', 'channel' => $this->accountCode(), 'marketplace_id' => $settings['marketplace_id'] ?? null]);
        $aspectNormalization = $this->normalizeAspects($payload['item_specifics'] ?? []);
        $marketplaceId = (string) ($settings['marketplace_id'] ?? ($this->accountCode() === 'ebay_fr' ? 'EBAY_FR' : 'EBAY_DE'));
        $inventoryDescription = $this->inventoryDescription($payload, $part, $sku, $marketplaceId);
        $listingDescription = (string) ($payload['description_rendered_html'] ?? '');
        $inventory = [
            'product' => ['title' => (string) ($payload['title'] ?? $part->name), 'description' => $inventoryDescription, 'imageUrls' => $payload['image_urls'] ?? [], 'aspects' => $aspectNormalization['aspects']],
            'condition' => (new EbayConditionMapper())->partCondition($part, $payload, $settings)['condition'], 'availability' => ['shipToLocationAvailability' => ['quantity' => (int) ($payload['quantity'] ?? $part->quantity ?? 1)]],
        ];
        $merchantLocationKey = (string) ($policies['merchant_location_key'] ?? $this->settingForPolicy($settings, 'merchant_location_key') ?? '');
        $offer = ['sku' => $sku, 'marketplaceId' => $marketplaceId, 'format' => (string) ($settings['format'] ?? 'FIXED_PRICE'), 'listingDuration' => (string) ($settings['listing_duration'] ?? 'GTC'), 'availableQuantity' => (int) ($payload['quantity'] ?? $part->quantity ?? 1), 'categoryId' => (string) ($payload['category_id'] ?? ''), 'merchantLocationKey' => $merchantLocationKey, 'pricingSummary' => ['price' => ['value' => (string) ($payload['price_eur'] ?? $readiness['marketplace_price']), 'currency' => 'EUR']], 'listingPolicies' => ['fulfillmentPolicyId' => (string) ($policies['selected_fulfillment_policy_id'] ?? $this->settingForPolicy($settings, 'selected_fulfillment_policy_id') ?? ''), 'paymentPolicyId' => (string) ($policies['selected_payment_policy_id'] ?? $this->settingForPolicy($settings, 'selected_payment_policy_id') ?? ''), 'returnPolicyId' => (string) ($policies['selected_return_policy_id'] ?? $this->settingForPolicy($settings, 'selected_return_policy_id') ?? '')]];
        if ($listingDescription !== '') $offer['listingDescription'] = $listingDescription;
        $contentLanguage = $this->contentLanguage((string) $offer['marketplaceId']);
        $auditLogger = app(EbayPanelActionAuditLogger::class);
        $auditLogger->step($part, 'inventory_item_about_to_send', ['selected_step' => 'publish', 'channel' => $this->accountCode(), 'endpoint' => 'PUT /sell/inventory/v1/inventory_item/{sku}', 'sku' => $sku]);
        $auditLogger->step($part, 'offer_create_about_to_send', ['selected_step' => 'publish', 'channel' => $this->accountCode(), 'endpoint' => 'POST /sell/inventory/v1/offer', 'sku' => $sku]);
        $auditLogger->step($part, 'offer_update_about_to_send', ['selected_step' => 'publish', 'channel' => $this->accountCode(), 'endpoint' => 'PUT /sell/inventory/v1/offer/{offerId}', 'sku' => $sku]);
        $auditLogger->step($part, 'publish_about_to_send', ['selected_step' => 'publish', 'channel' => $this->accountCode(), 'endpoint' => 'POST /sell/inventory/v1/offer/{offerId}/publish', 'sku' => $sku]);
        $result = (new EbayApiClient($this->accountCode(), $account))
            ->withDiagnosticContext(['part_id' => $part->id, 'stage' => 'publish'])
            ->publishInventoryOffer($sku, $inventory, $offer, $contentLanguage);
        if (($result['existing_offer_reused'] ?? false) && filled($result['offer_id'] ?? null)) {
            $existingLocal = $this->attachExistingOffer($part, $account, (string) $result['offer_id'], $result['listing_id'] ?? null, $sku, $payload, $readiness, $offer, $result);
            if ($existingLocal['conflict'] ?? false) {
                return ['ok' => false, 'status' => 'ebay_offer_mapping_conflict', 'action' => 'publishOffer', 'http_status' => $result['http_status'] ?? null, 'offer_id' => $result['offer_id'], 'listing_id' => $result['listing_id'] ?? null, 'external_inventory_id' => $sku, 'resolved_sku' => $sku, 'request_id' => $result['request_id'] ?? null, 'request_summary' => $this->requestSummary($payload), 'response_summary' => $this->responseSummary($result), 'error' => 'Ta oferta eBay jest już przypisana do innej części. Sprawdź istniejące mapowanie.', 'ui_error' => 'Ta oferta eBay jest już przypisana do innej części. Sprawdź istniejące mapowanie.', 'log_context' => $existingLocal['log_context'] ?? []];
            }
            if (! ($result['ok'] ?? false)) {
                return ['ok' => true, 'action' => 'publishOffer', 'status' => 'draft', 'listing_status' => 'draft', 'offer_id' => $result['offer_id'], 'listing_id' => null, 'external_inventory_id' => $sku, 'resolved_sku' => $sku, 'url' => null, 'marketplace_listing_id' => $existingLocal['listing_id'] ?? null, 'user_message' => 'Oferta eBay już istnieje jako szkic. Została podpięta lokalnie, ale nie ma jeszcze publicznego linku.', 'request_id' => $result['request_id'] ?? null, 'request_summary' => $this->requestSummary($payload), 'response_summary' => $this->responseSummary($result), 'json' => $result['json'] ?? [], 'log_context' => $existingLocal['log_context'] ?? []];
            }
        }
        return ['ok' => $result['ok'] ?? false, 'action' => 'publishOffer', 'http_status' => $result['http_status'] ?? null, 'offer_id' => $result['offer_id'] ?? null, 'listing_id' => $result['listing_id'] ?? null, 'external_inventory_id' => $sku, 'resolved_sku' => $sku, 'url' => filled($result['listing_id'] ?? null) ? $this->listingUrl((string) $offer['marketplaceId'], (string) $result['listing_id']) : null, 'request_id' => $result['request_id'] ?? null, 'request_summary' => $this->requestSummary($payload) + ['resolved_ebay_sku' => $sku, 'resolved_merchant_location_key' => $merchantLocationKey, 'merchantLocationKey' => $offer['merchantLocationKey'], 'aspects_diagnostics' => $aspectNormalization['diagnostics'], 'content_language' => $contentLanguage, 'marketplace_id' => $offer['marketplaceId'], 'inventory_description_source' => 'title', 'inventory_description_length' => mb_strlen($inventoryDescription), 'listing_description_length' => $listingDescription !== '' ? mb_strlen($listingDescription) : null], 'response_summary' => $this->responseSummary($result), 'json' => $result['json'] ?? [], 'error' => $this->ebayError($result), 'ui_error' => 'marketplace_api_error', 'marketplace_listing_id' => ($existingLocal['listing_id'] ?? null), 'listing_status' => (($result['existing_offer_reused'] ?? false) && blank($result['listing_id'] ?? null)) ? 'draft' : 'published', 'user_message' => ($result['existing_offer_reused'] ?? false) ? (filled($result['listing_id'] ?? null) ? 'Oferta eBay już istniała. Została opublikowana i podpięta.' : 'Oferta eBay już istnieje jako szkic. Została podpięta lokalnie, ale nie ma jeszcze publicznego linku.') : null, 'log_context' => ($existingLocal['log_context'] ?? [])];
    }

    private function attachExistingOffer(Part $part, ?MarketplaceAccount $account, string $offerId, mixed $listingId, string $sku, array $payload, array $readiness, array $offer, array $result): array
    {
        $listingId = filled($listingId) ? (string) $listingId : null;
        $conflict = MarketplaceListing::query()->where('marketplace', $this->marketplace())->where(function ($q) use ($offerId, $listingId): void {
            $q->where('external_offer_id', $offerId);
            if ($listingId !== null) $q->orWhere('external_listing_id', $listingId);
        })->where('part_id', '<>', $part->id)->first();
        if ($conflict) {
            Log::warning('eBay existing offer mapping conflict', ['part_id' => $part->id, 'sku' => $sku, 'channel' => $this->accountCode(), 'offerId' => $offerId, 'listingId' => $listingId, 'conflict_listing_id' => $conflict->id, 'conflict_part_id' => $conflict->part_id]);
            return ['conflict' => true, 'log_context' => ['ebay_existing_offer_conflict' => true, 'offer_id' => $offerId, 'external_listing_id' => $listingId, 'conflict_listing_id' => $conflict->id, 'conflict_part_id' => $conflict->part_id]];
        }

        $listing = MarketplaceListing::query()->firstOrNew(['marketplace' => $this->marketplace(), 'part_id' => $part->id]);
        $created = ! $listing->exists;
        $listing->fill(['marketplace_account_id' => $account?->id, 'external_offer_id' => $offerId, 'external_listing_id' => $listingId, 'external_inventory_id' => $sku, 'sku' => $sku, 'title' => $payload['title'] ?? $part->name, 'price' => $readiness['marketplace_price'] ?? $part->price, 'quantity' => $part->quantity, 'currency' => $readiness['currency'] ?? 'EUR', 'status' => $listingId ? 'published' : 'draft', 'sync_status' => $listingId ? 'published' : 'mapped', 'match_status' => 'matched', 'match_confidence' => 100, 'url' => $listingId ? $this->listingUrl((string) $offer['marketplaceId'], $listingId) : null, 'raw_payload' => ['request_summary' => $this->requestSummary($payload), 'response_summary' => $this->responseSummary($result)], 'last_synced_at' => now()]);
        $listing->save();
        Log::info('eBay existing offer attached locally', ['part_id' => $part->id, 'sku' => $sku, 'channel' => $this->accountCode(), 'offerId' => $offerId, 'listingId' => $listingId, 'local_listing_id' => $listing->id, 'local_listing_created' => $created, 'continued_publishOffer' => true]);

        return ['conflict' => false, 'listing_id' => $listing->id, 'log_context' => ['ebay_existing_offer_reused' => true, 'offer_id' => $offerId, 'external_listing_id' => $listingId, 'local_listing_created' => $created, 'continued_publishOffer' => true]];
    }

    /**
     * @return array{aspects: array<string, array<int, string>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function normalizeAspects(mixed $aspects): array
    {
        $normalized = [];
        $diagnostics = [];

        foreach ((array) $aspects as $name => $value) {
            if (! is_string($name) && ! is_int($name)) continue;

            $aspectName = trim((string) $name);
            if ($aspectName === '') continue;

            [$values, $skipped, $originalShape] = $this->normalizeAspectValues($value);
            if ($values !== []) {
                $normalized[$aspectName] = $values;
            }

            $diagnostics[] = [
                'aspect_name' => $aspectName,
                'original_shape' => $originalShape,
                'normalized_shape' => $values === [] ? 'empty' : 'array<string>',
                'skipped' => $skipped || $values === [],
            ];
        }

        return ['aspects' => $normalized, 'diagnostics' => $diagnostics];
    }

    /** @return array{0: array<int, string>, 1: bool, 2: string} */
    private function normalizeAspectValues(mixed $value): array
    {
        $shape = get_debug_type($value);
        $skipped = false;

        if (is_scalar($value)) {
            $string = trim((string) $value);
            return [$string === '' ? [] : [$string], $string === '', $shape];
        }

        if (is_object($value)) {
            foreach (['label', 'value', 'name'] as $key) {
                if (isset($value->{$key}) && is_scalar($value->{$key})) {
                    $string = trim((string) $value->{$key});
                    return [$string === '' ? [] : [$string], $string === '', 'object'];
                }
            }

            return [[], true, 'object'];
        }

        if (is_array($value)) {
            $values = [];
            $isList = array_is_list($value);

            if (! $isList) {
                foreach (['label', 'value', 'name'] as $key) {
                    if (isset($value[$key]) && is_scalar($value[$key])) {
                        $string = trim((string) $value[$key]);
                        return [$string === '' ? [] : [$string], $string === '', 'associative_array'];
                    }
                }
                return [[], true, 'associative_array'];
            }

            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $string = trim((string) $item);
                    if ($string !== '') $values[] = $string;
                    continue;
                }

                if (is_array($item) || is_object($item)) {
                    foreach (['label', 'value', 'name'] as $key) {
                        $nestedValue = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
                        if (is_scalar($nestedValue)) {
                            $string = trim((string) $nestedValue);
                            if ($string !== '') $values[] = $string;
                            continue 2;
                        }
                    }
                }

                $skipped = true;
            }

            return [array_values(array_unique($values)), $skipped, 'array'];
        }

        return [[], true, $shape];
    }

    private function inventoryDescription(array $payload, Part $part, string $sku, string $marketplaceId): string
    {
        $description = $this->plainText((string) (($payload['description'] ?? null) ?: ($payload['title'] ?? null) ?: ($part->name ?? '')));
        if ($description === '') {
            $description = match ($marketplaceId) {
                'EBAY_FR' => 'Pièce automobile '.$sku,
                default => 'Autoteil '.$sku,
            };
        }

        return mb_substr($description, 0, 3900);
    }

    private function plainText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function listingUrl(string $marketplaceId, string $listingId): string
    {
        return ($marketplaceId === 'EBAY_FR' ? 'https://www.ebay.fr/itm/' : 'https://www.ebay.de/itm/').$listingId;
    }

    private function contentLanguage(string $marketplaceId): string
    {
        return match ($marketplaceId) {
            'EBAY_FR' => 'fr-FR',
            default => 'de-DE',
        };
    }

    private function skuFor(Part $part, array $payload): string
    {
        return $this->skuResolver->resolve($part);
    }

    protected function activeListing(Part $part): ?MarketplaceListing
    {
        if (! Schema::hasTable('marketplace_listings')) return null;

        $listing = MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', $this->marketplace())->whereNotNull('external_listing_id')->whereNotIn('status', ['ended','failed','deleted','archived','cancelled','ENDED','FAILED','DELETED','ARCHIVED','CANCELLED'])->first();
        if ($listing) return $listing;

        $sku = $this->skuResolver->resolve($part);

        return MarketplaceListing::query()
            ->where('marketplace', $this->marketplace())
            ->where('sku', $sku)
            ->where(function ($query) use ($part): void {
                $query->where('part_id', $part->id)
                    ->orWhereNull('part_id');
            })
            ->where(function ($query): void {
                $query->whereNotNull('external_listing_id')
                    ->orWhereNotNull('external_offer_id')
                    ->orWhereNotNull('external_inventory_id');
            })
            ->whereIn('status', ['published', 'active', 'ACTIVE'])
            ->first();
    }

    private function settingForPolicy(array $settings, string $key): mixed
    {
        return match ($key) {
            'merchant_location_key' => $this->merchantLocationKey($settings),
            'selected_fulfillment_policy_id' => $settings['fulfillment_policy_id'] ?? null,
            'selected_payment_policy_id' => $settings['payment_policy_id'] ?? null,
            'selected_return_policy_id' => $settings['return_policy_id'] ?? null,
            default => null,
        };
    }

    private function merchantLocationKey(array $settings): ?string
    {
        foreach (['merchant_location_key', 'merchantLocationKey', 'location_key', 'inventory_location_key'] as $key) {
            if (filled($settings[$key] ?? null)) return (string) $settings[$key];
        }

        $defaults = array_merge(
            (array) config('product-hub.ebay.default_location', []),
            (array) config('product-hub.ebay.accounts.'.$this->accountCode(), [])
        );

        foreach (['merchant_location_key', 'merchantLocationKey', 'location_key', 'inventory_location_key'] as $key) {
            if (filled($defaults[$key] ?? null)) return (string) $defaults[$key];
        }

        return null;
    }

    private function ebayError(array $result): string
    {
        if (filled($result['error'] ?? null) && $result['error'] !== 'eBay publish failed.') return (string) $result['error'];
        $json = is_array($result['json'] ?? null) ? $result['json'] : [];
        $first = is_array($json['errors'][0] ?? null) ? $json['errors'][0] : [];
        $message = $first['message'] ?? $first['longMessage'] ?? $json['message'] ?? null;
        return filled($message) ? 'eBay '.($result['step'] ?? 'publish').' failed: '.$message : 'eBay '.($result['step'] ?? 'publish').' failed (HTTP '.($result['http_status'] ?? 'n/a').').';
    }

    private function responseSummary(array $result): array
    {
        return [
            'step' => $result['step'] ?? null,
            'offer_id' => $result['offer_id'] ?? null,
            'listing_id' => $result['listing_id'] ?? null,
            'correlation_id' => $result['request_id'] ?? null,
            'body' => is_array($result['json'] ?? null) ? $result['json'] : [],
        ];
    }
}
