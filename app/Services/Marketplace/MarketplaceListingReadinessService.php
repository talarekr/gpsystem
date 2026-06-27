<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MarketplaceListingReadinessService
{
    public const CHANNELS = ['storefront', 'allegro_main', 'ovoko', 'ebay_de', 'ebay_fr'];

    public function __construct(
        private readonly TranslationService $translationService,
        private readonly EbayDescriptionTemplateRenderer $ebayDescriptionTemplateRenderer,
        private readonly NbpExchangeRateService $exchangeRateService,
    ) {}

    /** @return array<string, mixed> */
    public function checkPartReadiness(Part $part, string $channel): array
    {
        $channel = $channel === 'ebay' ? 'ebay_de' : $channel;

        if (! in_array($channel, self::CHANNELS, true)) {
            return $this->unsupportedChannelResult($part, $channel);
        }

        $this->loadSafeRelations($part);

        $required = ['title', 'price', 'quantity', 'images'];
        $missing = [];
        $warnings = [];
        $blockers = [];
        $notes = ['dry_run_only' => 'Readiness does not publish, update, delete, sync prices, sync stock, or import orders.'];
        $marketplace = $this->marketplaceCode($channel);
        $account = $this->accountFor($channel);
        $price = $this->priceFor($part, $channel);
        $images = $this->imagesFor($part);
        $imagesCount = $images->count();
        $hasActiveListing = $marketplace ? $this->hasActiveListing($part, $marketplace, $channel) : false;
        $categoryMapping = str_starts_with($channel, 'ebay_') ? $this->ebayCategoryMapping($part, $channel) : null;
        $shippingPolicyResolution = str_starts_with($channel, 'ebay_') ? $this->resolveEbayShippingPolicy($part, $channel, $categoryMapping) : null;
        $businessPolicies = str_starts_with($channel, 'ebay_') ? $this->resolveEbayBusinessPolicies($account, $channel, $shippingPolicyResolution) : null;
        $exchangeRate = str_starts_with($channel, 'ebay_') ? $this->exchangeRateService->eurPln() : null;
        $priceEur = str_starts_with($channel, 'ebay_') ? $this->convertPlnToEur($price, $exchangeRate) : null;

        $this->requireFilled($part->name ?? null, 'title', $missing, $blockers);
        $this->requirePositive($price, str_starts_with($channel, 'ebay_') ? 'price_source_pln' : 'price', $missing, $blockers);
        $this->requirePositive($part->quantity ?? null, 'quantity', $missing, $blockers);
        if ($imagesCount < 1) {
            $missing[] = 'images';
            $blockers[] = 'At least one part image is required.';
        }

        $categoryReady = filled($part->category_id ?? null) && ($part->relationLoaded('category') ? $part->category !== null : true);
        $vehicleReady = filled($part->car_id ?? null) || is_array($part->vehicle_snapshot ?? null);
        $descriptionReady = filled(strip_tags((string) ($part->description ?? ''))) || filled(strip_tags((string) ($part->short_description ?? '')));
        $titleReady = filled($part->name ?? null);
        $stockReady = is_numeric($part->quantity ?? null) && (int) $part->quantity > 0;

        if ($hasActiveListing) {
            $blockers[] = 'Part already has an active marketplace listing for this channel.';
        }

        if ($channel === 'storefront') {
            $required = ['title', 'storefront_price', 'quantity', 'images', 'description'];
            if (! $descriptionReady) $warnings[] = 'Storefront description is missing or placeholder-only.';
        } elseif ($channel === 'allegro_main') {
            $required = ['title', 'allegro_price_pln', 'quantity', 'images', 'allegro_category_mapping', 'description'];
            $this->checkAccount($account, $blockers, $warnings, 'Allegro OAuth/account is not configured or not enabled.');
            if (! $categoryReady) { $missing[] = 'allegro_category_mapping'; $blockers[] = 'Allegro category or local category mapping is missing.'; }
            if (! $descriptionReady) { $missing[] = 'description'; $warnings[] = 'Allegro description should be prepared before publishing later.'; }
            $warnings[] = 'Allegro category parameter requirements are not fetched in this step; only local diagnostics are performed.';
        } elseif ($channel === 'ovoko') {
            $required = ['title', 'ovoko_price_pln', 'quantity', 'images', 'vehicle', 'ovoko_category_mapping', 'description_or_condition'];
            $this->checkAccount($account, $blockers, $warnings, 'Ovoko API credentials/account are not configured or not enabled.');
            if (! $vehicleReady) { $missing[] = 'vehicle'; $blockers[] = 'Vehicle data is required for Ovoko readiness.'; }
            if (! $categoryReady) { $missing[] = 'ovoko_category_mapping'; $blockers[] = 'Ovoko/RRR category mapping is missing.'; }
            if (! $descriptionReady && blank($part->condition_notes ?? null)) { $missing[] = 'description_or_condition'; $warnings[] = 'Ovoko description or condition notes are missing.'; }
            if (! $this->hasCompleteDimensions($part)) { $warnings[] = 'Ovoko dimensions are incomplete (weight_kg, length_cm, width_cm, height_cm).'; }
        } else {
            $required = ['title', 'ebay_price_pln', 'quantity', 'images', 'ebay_category_mapping', 'translation_credentials', 'description_template', 'business_policies', 'marketplace_country'];
            $this->checkEbayAccount($account, $blockers, $warnings);
            if (! $categoryMapping) { $missing[] = 'ebay_category_mapping'; $blockers[] = 'ebay_category_mapping_missing'; }
            elseif ($categoryMapping->is_blocked) { $missing[] = 'ebay_category_mapping'; $blockers[] = 'ebay_category_blocked'; }
            elseif (blank($categoryMapping->external_category_id)) { $missing[] = 'ebay_category_mapping'; $blockers[] = 'ebay_category_mapping_missing'; }
            else { $notes['ebay_category_mapping'] = ['source' => 'marketplace_category_mappings', 'channel' => $categoryMapping->channel, 'external_category_id' => $categoryMapping->external_category_id]; }
            if (! $this->isGoogleTranslateConfigured()) { $missing[] = 'translation_credentials'; $warnings[] = 'Google Translate credentials are not configured for later title/description/condition translation dry-runs.'; }
            if (! $this->ebayTemplateExists($channel)) { $missing[] = 'description_template'; $blockers[] = 'eBay description template for '.$channel.' is missing.'; }
            foreach ($shippingPolicyResolution['missing'] ?? [] as $key => $message) { $missing[] = $key; $blockers[] = $message; }
            foreach ($businessPolicies['missing'] ?? [] as $key => $message) { $missing[] = $key; $blockers[] = $message; }
            if (! is_array($exchangeRate) || ! is_numeric($exchangeRate['rate'] ?? null) || (float) $exchangeRate['rate'] <= 0) { $missing[] = 'exchange_rate'; $blockers[] = 'Brak kursu EUR z NBP'; }
            if ($price !== null && $priceEur !== null && $priceEur <= 0) { $missing[] = 'price_eur'; $blockers[] = 'eBay EUR price must be greater than zero.'; }
            $notes['translation'] = ['source_language' => 'PL', 'target_language' => $this->targetLanguageForChannel($channel), 'fields' => ['title', 'description', 'condition_notes']];
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));
        $missing = array_values(array_unique($missing));

        return [
            'channel' => $channel,
            'can_prepare' => $blockers === [],
            'can_publish_later' => $blockers === [],
            'required_fields' => $required,
            'missing_fields' => $missing,
            'warnings' => $warnings,
            'blockers' => $blockers,
            'prepared_payload_preview_safe' => $this->safePayloadPreview($part, $channel, $price, $categoryMapping, $shippingPolicyResolution, $businessPolicies, $exchangeRate, $priceEur),
            'price_source' => $this->priceSource($channel),
            'local_price' => is_numeric($part->price ?? null) ? (float) $part->price : null,
            'marketplace_price' => $price,
            'currency' => str_starts_with($channel, 'ebay_') ? 'EUR' : 'PLN',
            'images_count' => $imagesCount,
            'has_required_images' => $imagesCount > 0,
            'category_ready' => $categoryReady,
            'vehicle_ready' => $vehicleReady,
            'description_ready' => $descriptionReady,
            'title_ready' => $titleReady,
            'stock_ready' => $stockReady,
            'external_mapping_exists' => $hasActiveListing,
            'notes' => $notes,
        ];
    }

    /** @return array<string, mixed> */
    public function checkAll(Part $part): array
    {
        $channels = [];

        foreach (self::CHANNELS as $channel) {
            try {
                $channels[$channel] = $this->checkPartReadiness($part, $channel);
            } catch (\Throwable $e) {
                $channels[$channel] = $this->channelExceptionResult($part, $channel, $e);
            }
        }

        return [
            'channels' => $channels,
            'summary' => [
                'ready_channels' => array_keys(array_filter($channels, fn ($r) => (bool) ($r['can_prepare'] ?? false))),
                'blocked_channels' => array_keys(array_filter($channels, fn ($r) => ($r['blockers'] ?? []) !== [])),
                'warning_channels' => array_keys(array_filter($channels, fn ($r) => ($r['warnings'] ?? []) !== [])),
            ],
            'blockers' => $this->collectChannelMessages($channels, 'blockers'),
            'warnings' => $this->collectChannelMessages($channels, 'warnings'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $channels
     * @return array<int, mixed>
     */
    private function collectChannelMessages(array $channels, string $key): array
    {
        $messages = [];

        foreach ($channels as $result) {
            $channelMessages = $result[$key] ?? [];

            if (! is_array($channelMessages)) {
                continue;
            }

            foreach ($channelMessages as $message) {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }

    private function failedStageForChannel(string $channel): string { return match ($channel) { 'storefront' => 'storefront_readiness', 'allegro_main' => 'allegro_readiness', 'ovoko' => 'ovoko_readiness', 'ebay_de' => 'ebay_de_readiness', 'ebay_fr' => 'ebay_fr_readiness', default => 'channel_readiness' }; }
    private function safeExceptionMessage(\Throwable $e): string { return Str::limit(preg_replace(['/([?&](?:token|api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret|client[_-]?secret|credential)[^=]*=)[^&\s]+/i', '/\b(?:token|api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret|client[_-]?secret|credential)\b\s*[:=]\s*[^\s,;]+/i'], ['$1[redacted]', '[redacted_secret]'], $e->getMessage()), 500, '...'); }
    /** @return array<string, mixed> */
    private function channelExceptionResult(Part $part, string $channel, \Throwable $e): array { return ['channel' => $channel, 'part_id' => $part->id, 'can_prepare' => false, 'can_publish_later' => false, 'required_fields' => [], 'missing_fields' => [], 'warnings' => [], 'blockers' => ['channel_readiness_exception'], 'exception_class' => $e::class, 'exception_message_safe' => $this->safeExceptionMessage($e), 'failed_stage' => $this->failedStageForChannel($channel), 'prepared_payload_preview_safe' => ['dry_run' => true, 'channel' => $channel, 'part_id' => $part->id, 'will_make_marketplace_request' => false], 'price_source' => 'none', 'local_price' => is_numeric($part->price ?? null) ? (float) $part->price : null, 'marketplace_price' => null, 'currency' => 'PLN', 'images_count' => 0, 'has_required_images' => false, 'category_ready' => false, 'vehicle_ready' => false, 'description_ready' => false, 'title_ready' => filled($part->name ?? null), 'stock_ready' => false, 'external_mapping_exists' => false, 'notes' => ['dry_run_only' => 'Readiness does not publish, update, delete, sync prices, sync stock, or import orders.']]; }
    private function loadSafeRelations(Part $part): void { $relations = array_filter([method_exists($part, 'images') ? 'images' : null, method_exists($part, 'partImages') ? 'partImages' : null, method_exists($part, 'category') ? 'category' : null, method_exists($part, 'car') ? 'car' : null, method_exists($part, 'marketplaceListings') ? (Schema::hasTable('marketplace_accounts') ? 'marketplaceListings.account' : 'marketplaceListings') : null]); try { $part->loadMissing($relations); } catch (\Throwable) { foreach ($relations as $relation) { try { $part->loadMissing([$relation]); } catch (\Throwable) {} } } }
    private function imagesFor(Part $part): \Illuminate\Support\Collection { foreach (['images', 'partImages'] as $relation) { if (! method_exists($part, $relation)) continue; try { return $part->relationLoaded($relation) ? $part->{$relation}->filter() : $part->{$relation}()->get(); } catch (\Throwable) {} } return collect(); }
    private function accountFor(string $channel): ?MarketplaceAccount { if (! Schema::hasTable('marketplace_accounts')) return null; $code = $channel === 'ovoko' ? 'ovoko_main' : $channel; return MarketplaceAccount::query()->where('code', $code)->first(); }
    private function marketplaceCode(string $channel): ?string { return match (true) { $channel === 'storefront' => null, $channel === 'allegro_main' => 'allegro', str_starts_with($channel, 'ebay_') => $channel, default => $channel }; }
    private function priceFor(Part $part, string $channel): ?float { $base = is_numeric($part->price ?? null) ? (float) $part->price : null; $value = match ($channel) { 'ovoko' => $part->ovoko_price ?? null, 'ebay_de', 'ebay_fr' => $part->ebay_price ?? ($base !== null ? round($base * 1.25, 2) : null), 'allegro_main' => $part->allegro_price ?? $base, default => $base }; return is_numeric($value) ? (float) $value : null; }
    private function priceSource(string $channel): string { return match ($channel) { 'ovoko' => 'parts.ovoko_price_pln', 'ebay_de', 'ebay_fr' => 'parts.ebay_price_pln_or_storefront_price_x_1_25_pln', 'allegro_main' => 'parts.allegro_price_pln_or_storefront_price_pln', default => 'parts.price_pln' }; }
    private function hasCompleteDimensions(Part $part): bool { foreach (['weight_kg', 'length_cm', 'width_cm', 'height_cm'] as $field) { if (! is_numeric($part->{$field} ?? null) || (float) $part->{$field} <= 0) return false; } return true; }
    private function dimensionsPayload(Part $part): array { return ['weight_kg' => is_numeric($part->weight_kg ?? null) ? (float) $part->weight_kg : null, 'length_cm' => is_numeric($part->length_cm ?? null) ? (float) $part->length_cm : null, 'width_cm' => is_numeric($part->width_cm ?? null) ? (float) $part->width_cm : null, 'height_cm' => is_numeric($part->height_cm ?? null) ? (float) $part->height_cm : null]; }
    private function requireFilled(mixed $value, string $field, array &$missing, array &$blockers): void { if (blank($value)) { $missing[] = $field; $blockers[] = ucfirst($field).' is required.'; } }
    private function requirePositive(mixed $value, string $field, array &$missing, array &$blockers): void { if (! is_numeric($value) || (float) $value <= 0) { $missing[] = $field; $blockers[] = ucfirst($field).' must be greater than zero.'; } }
    private function checkAccount(?MarketplaceAccount $account, array &$blockers, array &$warnings, string $missingMessage): void { if (! $account || ! ($account->api_enabled ?? false) || ! in_array($account->status, ['enabled', 'active'], true)) $blockers[] = $missingMessage; elseif (($account->last_connection_status ?? null) && $account->last_connection_status !== 'ok') $warnings[] = 'Last read-only API connection check is not ok.'; }
    private function checkEbayAccount(?MarketplaceAccount $account, array &$blockers, array &$warnings): void { if (! $account || ! ($account->api_enabled ?? false)) $blockers[] = 'eBay API/settings are not configured or not enabled.'; else $this->checkAccount($account, $blockers, $warnings, 'eBay API/settings are not configured or not enabled.'); }
    private function isGoogleTranslateConfigured(): bool { try { return $this->translationService->isGoogleTranslateConfigured(); } catch (\Throwable) { return false; } }
    private function targetLanguageForChannel(string $channel): ?string { try { return $this->translationService->targetLanguageForChannel($channel); } catch (\Throwable) { return null; } }
    private function ebayTemplateExists(string $channel): bool { try { return $this->ebayDescriptionTemplateRenderer->isAvailable($channel) || filled(config('marketplace.ebay.templates.'.$channel)) || view()->exists('marketplace.ebay.templates.'.$channel); } catch (\Throwable) { return false; } }

    private function resolveEbayShippingPolicy(Part $part, string $channel, ?MarketplaceCategoryMapping $mapping): array
    {
        $group = $mapping?->shipping_group;
        $policies = (array) config("product-hub.ebay_business_policies.$channel.fulfillment_by_shipping_group", []);
        $policy = filled($group) ? ($policies[$group] ?? null) : null;
        $missing = [];
        if (! $mapping || blank($group)) $missing['category_shipping_group'] = 'Brakuje grupy wysyłkowej dla kategorii';
        elseif (! $policy) $missing['shipping_policy_mapping'] = 'Brakuje mapowania grupy wysyłkowej na eBay fulfillment policy';

        return [
            'local_category_id' => $part->category_id ?? null,
            'local_category_name' => $part->category?->name,
            'shipping_group' => $group,
            'shipping_group_source' => $mapping ? 'marketplace_category_mappings.shipping_group' : null,
            'selected_fulfillment_policy_id' => $policy['id'] ?? $mapping?->fulfillment_policy_id,
            'selected_fulfillment_policy_name' => $policy['name'] ?? null,
            'available_policy_mapping' => collect($policies)->map(fn ($p) => $p['id'] ?? null)->all(),
            'missing' => $missing,
        ];
    }

    private function resolveEbayBusinessPolicies(?MarketplaceAccount $account, string $channel, ?array $shipping): array
    {
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $payment = data_get($settings, 'business_policies.payment') ?: config("product-hub.ebay_business_policies.$channel.payment.id");
        $return = data_get($settings, 'business_policies.return') ?: config("product-hub.ebay_business_policies.$channel.return.id");
        $location = data_get($settings, 'merchant_location_key') ?: data_get($settings, 'location.merchant_location_key');
        $missing = [];
        if (blank($payment)) $missing['payment_policy'] = 'Brakuje eBay payment policy';
        if (blank($return)) $missing['return_policy'] = 'Brakuje eBay return policy';
        if (blank($location)) $missing['merchant_location_key'] = 'Brakuje merchant location key';
        return [
            'selected_fulfillment_policy_id' => $shipping['selected_fulfillment_policy_id'] ?? null,
            'selected_fulfillment_policy_name' => $shipping['selected_fulfillment_policy_name'] ?? null,
            'selected_payment_policy_id' => $payment,
            'selected_payment_policy_name' => config("product-hub.ebay_business_policies.$channel.payment.name"),
            'selected_return_policy_id' => $return,
            'selected_return_policy_name' => config("product-hub.ebay_business_policies.$channel.return.name"),
            'merchant_location_key' => $location,
            'missing' => $missing,
        ];
    }

    private function convertPlnToEur(?float $price, ?array $rate): ?float { return $price !== null && is_numeric($rate['rate'] ?? null) && (float) $rate['rate'] > 0 ? round($price / (float) $rate['rate'], 2) : null; }
    private function exchangeRatePreview(?array $rate): ?array { if (! $rate) return null; return ['source' => 'NBP_TABLE_A', 'currency' => 'EUR', 'rate' => $rate['rate'] ?? null, 'effective_date' => $rate['effective_date'] ?? null, 'table_no' => $rate['table_no'] ?? null, 'fetched_at' => $rate['fetched_at'] ?? null]; }

    private function ebayCategoryMapping(Part $part, string $channel): ?MarketplaceCategoryMapping
    {
        if (! Schema::hasTable('marketplace_category_mappings') || blank($part->category_id ?? null)) {
            return null;
        }

        return MarketplaceCategoryMapping::query()
            ->where('local_category_id', $part->category_id)
            ->whereIn('channel', [$channel, 'ebay'])
            ->orderByRaw('case when channel = ? then 0 else 1 end', [$channel])
            ->first();
    }
    private function hasActiveListing(Part $part, ?string $marketplace, string $channel): bool { if (! $marketplace || ! Schema::hasTable('marketplace_listings')) return false; return MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', $marketplace)->whereNotNull('external_offer_id')->whereNotIn('status', ['ended', 'deleted', 'archived', 'inactive'])->exists(); }
    /** @return array<string, mixed> */
    private function safePayloadPreview(Part $part, string $channel, ?float $price, ?MarketplaceCategoryMapping $categoryMapping = null, ?array $shippingPolicyResolution = null, ?array $businessPolicies = null, ?array $exchangeRate = null, ?float $priceEur = null): array
    {
        $preview = ['dry_run' => true, 'channel' => $channel, 'sku' => $part->sku ?? null, 'title' => $part->name ?? null, 'description_present' => filled(strip_tags((string) (($part->description ?? null) ?: ($part->short_description ?? null)))), 'condition_notes_present' => filled($part->condition_notes ?? null), 'price_pln' => $price, 'price_source_pln' => str_starts_with($channel, 'ebay_') ? $price : null, 'price_eur' => $priceEur, 'exchange_rate' => $this->exchangeRatePreview($exchangeRate), 'quantity' => $part->quantity ?? null, 'currency' => str_starts_with($channel, 'ebay_') ? 'EUR' : 'PLN', 'dimensions' => $this->dimensionsPayload($part), 'image_urls' => $this->imagesFor($part)->take(5)->map(fn ($image) => method_exists($image, 'listingUrl') ? $image->listingUrl() : null)->filter()->values()->all(), 'category_id' => $categoryMapping?->external_category_id ?? ($part->category_id ?? null), 'category_mapping_source' => $categoryMapping ? 'marketplace_category_mappings' : null, 'category_mapping_channel' => $categoryMapping?->channel, 'local_category_id' => $part->category_id ?? null, 'vehicle' => ['car_id' => $part->car_id ?? null, 'snapshot_present' => is_array($part->vehicle_snapshot ?? null)], 'will_make_marketplace_request' => false];

        if (str_starts_with($channel, 'ebay_')) {
            $rendered = $this->ebayTemplateExists($channel) ? $this->ebayDescriptionTemplateRenderer->render($channel, $part) : '';
            $preview['description_template_present'] = $this->ebayTemplateExists($channel);
            $preview['description_template_channel'] = $channel;
            $preview['description_rendered_present'] = filled(trim($rendered));
            $preview['description_template_asset_urls'] = $this->ebayDescriptionTemplateRenderer->assetUrls();
            $preview['description_rendered_html'] = $rendered;
            $preview['shipping_policy_resolution'] = $shippingPolicyResolution;
            $preview['business_policies'] = $businessPolicies;
        }

        return $preview;
    }
    /** @return array<string, mixed> */
    private function unsupportedChannelResult(Part $part, string $channel): array { return ['channel' => $channel, 'can_prepare' => false, 'can_publish_later' => false, 'required_fields' => [], 'missing_fields' => ['channel'], 'warnings' => [], 'blockers' => ['Unsupported marketplace readiness channel.'], 'prepared_payload_preview_safe' => ['dry_run' => true, 'channel' => $channel, 'part_id' => $part->id, 'will_make_marketplace_request' => false], 'price_source' => 'none', 'local_price' => null, 'marketplace_price' => null, 'currency' => 'PLN', 'images_count' => 0, 'has_required_images' => false, 'category_ready' => false, 'vehicle_ready' => false, 'description_ready' => false, 'title_ready' => filled($part->name ?? null), 'stock_ready' => false, 'external_mapping_exists' => false, 'notes' => ['dry_run_only' => 'Readiness does not publish, update, delete, sync prices, sync stock, or import orders.']]; }
}
