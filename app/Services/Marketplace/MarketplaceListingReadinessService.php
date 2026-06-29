<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MarketplaceListingReadinessService
{
    public const CHANNELS = ['storefront', 'allegro_main', 'ovoko', 'ebay_de', 'ebay_fr'];

    public function __construct(private readonly TranslationService $translationService, private readonly EbayDescriptionTemplateRenderer $ebayDescriptionTemplateRenderer, private readonly NbpExchangeRateService $exchangeRateService, private readonly EbayItemSpecificsService $ebayItemSpecificsService, private readonly AllegroCategoryParametersService $allegroCategoryParametersService, private readonly AllegroOfferParametersBuilder $allegroOfferParametersBuilder) {}

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
        $ebayPrice = str_starts_with($channel, 'ebay_') ? $this->resolveEbayPrice($price) : null;
        $images = $this->imagesFor($part);
        $imagesCount = $images->count();
        $hasActiveListing = $marketplace ? $this->hasActiveListing($part, $marketplace, $channel) : false;
        $categoryMapping = match (true) {
            str_starts_with($channel, 'ebay_') => $this->ebayCategoryMapping($part, $channel),
            $channel === 'allegro_main' => $this->allegroCategoryMapping($part),
            $channel === 'ovoko' => $this->ovokoCategoryMapping($part),
            default => null,
        };
        $allegroParameters = null;

        $this->requireFilled($part->name ?? null, 'title', $missing, $blockers);
        $this->requirePositive($price, str_starts_with($channel, 'ebay_') ? 'ebay_price_pln' : 'price', $missing, $blockers);
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
            if (! $categoryMapping || blank($categoryMapping->external_category_id)) { $missing[] = 'allegro_category_mapping'; $blockers[] = 'Brakuje Allegro category id.'; }
            if (! $descriptionReady) { $missing[] = 'description'; $warnings[] = 'Allegro description should be prepared before publishing later.'; }
            if ($categoryMapping && filled($categoryMapping->external_category_id)) {
                $definitions = $this->allegroCategoryParametersService->definitions((string) $categoryMapping->external_category_id);
                $allegroParameters = $this->allegroOfferParametersBuilder->build($part, $categoryMapping, $definitions);
                foreach (($allegroParameters['missing_required_parameters'] ?? []) as $param) { $missing[] = 'allegro_parameter:'.($param['name'] ?? $param['id']); }
                if (($allegroParameters['missing_required_parameters'] ?? []) !== []) $blockers[] = 'allegro_required_category_parameters_missing';
                if (! ($definitions['ok'] ?? false)) $blockers[] = $definitions['blocker'] ?? 'allegro_category_parameters_unavailable';
            } else {
                $allegroParameters = ['will_make_marketplace_request' => false, 'parameter_definitions_source' => 'none', 'missing_required_parameters' => [], 'unmapped_parameters' => [], 'blocker' => 'allegro_category_mapping_missing'];
            }
        } elseif ($channel === 'ovoko') {
            $required = ['title', 'ovoko_price_pln', 'quantity', 'images', 'vehicle', 'ovoko_category_mapping', 'description_or_condition'];
            $this->checkAccount($account, $blockers, $warnings, 'Ovoko API credentials/account are not configured or not enabled.');
            if (! $vehicleReady) { $missing[] = 'vehicle'; $blockers[] = 'Vehicle data is required for Ovoko readiness.'; }
            if (! $categoryMapping || blank($categoryMapping->external_category_id)) { $missing[] = 'ovoko_category_mapping'; $blockers[] = 'Ovoko: brakuje category_id dla wybranej kategorii '.($part->category?->name ?? $part->category_id ?? 'części'); }
            if (! $descriptionReady && blank($part->condition_notes ?? null)) { $missing[] = 'description_or_condition'; $warnings[] = 'Ovoko description or condition notes are missing.'; }
            if (! $this->hasCompleteDimensions($part)) { $warnings[] = 'Ovoko dimensions are incomplete (weight_kg, length_cm, width_cm, height_cm).'; }
        } else {
            $required = ['title', 'ebay_price_pln', 'exchange_rate', 'price_eur', 'quantity', 'images', 'ebay_category_mapping', 'translation_credentials', 'description_template', 'business_policies', 'marketplace_country'];
            $this->checkEbayAccount($account, $blockers, $warnings);
            if (! ($ebayPrice['exchange_rate_available'] ?? false)) { $missing[] = 'exchange_rate'; $blockers[] = 'Brak kursu EUR z NBP.'; }
            if (! is_numeric($ebayPrice['price_eur'] ?? null) || (float) $ebayPrice['price_eur'] <= 0) { $missing[] = 'price_eur'; $blockers[] = 'eBay EUR price must be greater than zero.'; }
            if (! $categoryMapping) { $missing[] = 'ebay_category_mapping'; $blockers[] = 'ebay_category_mapping_missing'; }
            elseif ($categoryMapping->is_blocked) { $missing[] = 'ebay_category_mapping'; $blockers[] = 'ebay_category_blocked'; }
            elseif (blank($categoryMapping->external_category_id)) { $missing[] = 'ebay_category_mapping'; $blockers[] = 'ebay_category_mapping_missing'; }
            else { $notes['ebay_category_mapping'] = ['source' => 'marketplace_category_mappings', 'channel' => $categoryMapping->channel, 'external_category_id' => $categoryMapping->external_category_id]; }
            if (! $this->isGoogleTranslateConfigured()) { $missing[] = 'translation_credentials'; $warnings[] = 'Google Translate credentials are not configured for later title/description/condition translation dry-runs.'; }
            $preparedTranslation = $this->preparedTranslation($part, $channel);
            if (($preparedTranslation['status'] ?? 'not_prepared') !== 'prepared') { $missing[] = 'prepared_translations'; $warnings[] = 'Tłumaczenia nieprzygotowane — użyj przycisku Przygotuj.'; }
            if (! $this->ebayTemplateExists($channel)) { $missing[] = 'description_template'; $blockers[] = 'eBay description template for '.$channel.' is missing.'; }
            $businessPolicies = $this->resolveEbayBusinessPolicies($account, $categoryMapping, $part, $channel);
            foreach ($businessPolicies['missing'] as $policyMissing) { $missing[] = $policyMissing; }
            foreach ($businessPolicies['blockers'] as $policyBlocker) { $blockers[] = $policyBlocker; }
            $notes['shipping_policy_resolution'] = $businessPolicies['shipping_policy_resolution'];
            $notes['business_policies'] = $businessPolicies['business_policies'];
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
            'prepared_payload_preview_safe' => $this->safePayloadPreview($part, $channel, $price, $categoryMapping, $ebayPrice, $allegroParameters),
            'price_source' => $this->priceSource($channel),
            'local_price' => is_numeric($part->price ?? null) ? (float) $part->price : null,
            'marketplace_price' => $ebayPrice['price_eur'] ?? $price,
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
    public function prepareEbayTranslations(Part $part, string $channel): array
    {
        $channel = $channel === 'ebay' ? 'ebay_de' : $channel;
        if (! in_array($channel, ['ebay_de', 'ebay_fr'], true)) return ['ok' => false, 'blockers' => ['unsupported_channel']];
        $this->loadSafeRelations($part);
        $language = strtolower((string) $this->targetLanguageForChannel($channel));
        $vehicle = $this->vehiclePayload($part, $channel, false);
        $fields = [
            'title' => (string) ($part->name ?? ''),
            'description' => trim(strip_tags((string) (($part->description ?? null) ?: ($part->short_description ?? null)))),
            'condition_notes' => (string) ($part->condition_notes ?? ''),
        ];
        foreach (($vehicle['attributes_source'] ?? []) as $key => $value) {
            if (is_string($value) && trim($value) !== '') $fields['vehicle.'.$key] = $value;
        }
        $translated = [];
        $translatedFields = [];
        $untranslatedFields = [];
        $blockers = [];
        foreach ($fields as $key => $value) {
            if (blank($value)) { $untranslatedFields[] = $key; continue; }
            $local = $this->localVehicleTranslation($value, $language);
            if ($local !== null) { $translated[$key] = $local; $translatedFields[] = $key; continue; }
            $result = app(GoogleTranslateService::class)->translate((string) $value, $language, 'pl');
            if (($result['ok'] ?? false) && filled($result['translated_text'] ?? null)) { $translated[$key] = (string) $result['translated_text']; $translatedFields[] = $key; }
            else { $translated[$key] = (string) $value; $untranslatedFields[] = $key; $blockers = array_merge($blockers, (array) ($result['blockers'] ?? ['translation_failed'])); }
        }
        $itemSpecifics = $this->ebayItemSpecificsService->fallbackSpecifics($part, $channel, $this->ebayCategoryMapping($part, $channel));
        $metadata = is_array($part->review_metadata) ? $part->review_metadata : [];
        Arr::set($metadata, 'marketplace_prepared_translations.'.$channel, [
            'status' => $blockers === [] ? 'prepared' : 'failed',
            'language' => $language,
            'fields' => $translated,
            'translated_fields' => $translatedFields,
            'untranslated_fields' => $untranslatedFields,
            'prepared_at' => now()->toIso8601String(),
            'vehicle_source' => $vehicle['source'],
            'item_specifics' => $itemSpecifics,
        ]);
        $part->forceFill(['review_metadata' => $metadata])->save();
        return ['ok' => $blockers === [], 'channel' => $channel, 'translation_status' => $blockers === [] ? 'prepared' : 'failed', 'translation_language' => $language, 'translated_fields' => $translatedFields, 'untranslated_fields' => $untranslatedFields, 'item_specifics_translated_fields' => array_keys($itemSpecifics), 'blockers' => array_values(array_unique($blockers)), 'will_make_marketplace_request' => false];
    }

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


    /** @return array<string, mixed> */
    private function resolveEbayBusinessPolicies(?MarketplaceAccount $account, ?MarketplaceCategoryMapping $mapping, Part $part, string $channel): array
    {
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $config = is_array($account?->config) ? $account->config : [];
        $paymentPolicyId = $this->policyId($settings, 'payment');
        $returnPolicyId = $this->policyId($settings, 'return');
        $merchantLocationKey = $this->merchantLocationKey($settings, $config);
        $shippingGroup = filled($mapping?->shipping_group) ? (string) $mapping->shipping_group : null;
        $fulfillmentPolicyId = filled($mapping?->fulfillment_policy_id) ? (string) $mapping->fulfillment_policy_id : null;
        $fulfillmentPolicyName = $this->fulfillmentPolicyName($fulfillmentPolicyId, $shippingGroup);
        $categoryName = $part->relationLoaded('category') ? ($part->category?->name ?? $mapping?->local_category_name) : $mapping?->local_category_name;
        $availablePolicyMapping = $this->availableShippingPolicyMapping($channel);
        $missing = [];
        $blockers = [];

        if (blank($shippingGroup)) {
            $missing[] = 'category_shipping_group';
            $blockers[] = 'category_shipping_group';
        }

        if (blank($fulfillmentPolicyId)) {
            $missing[] = 'shipping_policy_mapping';
            $blockers[] = 'shipping_policy_mapping';
        }

        if (blank($paymentPolicyId)) {
            $missing[] = 'payment_policy';
            $blockers[] = 'payment_policy';
        }

        if (blank($returnPolicyId)) {
            $missing[] = 'return_policy';
            $blockers[] = 'return_policy';
        }

        return [
            'missing' => $missing,
            'blockers' => $blockers,
            'shipping_policy_resolution' => [
                'local_category_id' => $part->category_id ?? null,
                'local_category_name' => $categoryName,
                'shipping_group' => $shippingGroup,
                'shipping_group_source' => $shippingGroup ? 'marketplace_category_mappings.shipping_group' : null,
                'selected_fulfillment_policy_id' => $fulfillmentPolicyId,
                'selected_fulfillment_policy_name' => $fulfillmentPolicyName,
                'available_policy_mapping' => $availablePolicyMapping,
                'missing' => array_values(array_intersect($missing, ['category_shipping_group', 'shipping_policy_mapping'])),
            ],
            'business_policies' => [
                'selected_fulfillment_policy_id' => $fulfillmentPolicyId,
                'selected_fulfillment_policy_name' => $fulfillmentPolicyName,
                'selected_payment_policy_id' => $paymentPolicyId,
                'selected_payment_policy_name' => $this->policyName($settings, 'payment', $paymentPolicyId),
                'selected_return_policy_id' => $returnPolicyId,
                'selected_return_policy_name' => $this->policyName($settings, 'return', $returnPolicyId),
                'merchant_location_key' => $merchantLocationKey,
                'missing' => $missing,
            ],
        ];
    }

    private function policyId(array $settings, string $type): ?string { foreach (["{$type}_policy_id", "default_{$type}_policy_id", "ebay_{$type}_policy_id"] as $key) if (filled($settings[$key] ?? null)) return (string) $settings[$key]; $policies = is_array($settings['business_policies'] ?? null) ? $settings['business_policies'] : []; return filled($policies[$type] ?? null) ? (string) $policies[$type] : null; }
    private function policyName(array $settings, string $type, ?string $id): ?string { if (blank($id)) return null; $policies = is_array($settings[$type.'_policies'] ?? null) ? $settings[$type.'_policies'] : []; foreach ($policies as $policy) if (is_array($policy) && (string) ($policy['id'] ?? '') === (string) $id) return $policy['name'] ?? null; return null; }
    private function merchantLocationKey(array $settings, array $config): ?string { foreach (['merchant_location_key', 'location_key', 'inventory_location_key'] as $key) if (filled($settings[$key] ?? null)) return (string) $settings[$key]; foreach (['merchant_location_key', 'location_key', 'inventory_location_key'] as $key) if (filled($config[$key] ?? null)) return (string) $config[$key]; return null; }
    /** @return array<string, string> */
    private function availableShippingPolicyMapping(string $channel): array { return $channel === 'ebay_fr' ? ['fr_55_eur' => '260547694013', 'fr_70_eur' => '260547464013', 'fr_130_eur' => '260547754013'] : ['de_30_eur' => '259264150013', 'de_50_eur' => '259677066013', 'de_130_eur' => '259636579013']; }
    private function fulfillmentPolicyName(?string $policyId, ?string $shippingGroup): ?string { if (blank($policyId)) return null; return match ((string) $policyId) { '259264150013' => 'Wysyłka 30 euro', '259677066013' => 'Wysyłka 50 euro', '259636579013' => 'Wysyłka 130 euro', '260547694013' => 'Wysyłka FR 55 euro', '260547464013' => 'Wysyłka FR 70 euro', '260547754013' => 'Wysyłka FR 130 euro', default => $shippingGroup, }; }

    /** @return array<string, mixed> */
    private function resolveEbayPrice(?float $sourcePricePln): array
    {
        $conversion = $this->exchangeRateService->eurPln();
        $rate = is_numeric($conversion['rate'] ?? null) ? (float) $conversion['rate'] : null;
        $priceEur = $sourcePricePln !== null && $sourcePricePln > 0 && $rate !== null && $rate > 0 ? round($sourcePricePln / $rate, 2) : null;

        return [
            'price_source_pln' => $sourcePricePln,
            'price_eur' => $priceEur,
            'exchange_rate_available' => $rate !== null && $rate > 0,
            'exchange_rate' => $rate === null ? null : [
                'source' => 'NBP_TABLE_A',
                'currency' => 'EUR',
                'rate' => $rate,
                'effective_date' => $conversion['effective_date'] ?? null,
                'table_no' => $conversion['table_no'] ?? null,
            ],
        ];
    }

    private function allegroCategoryMapping(Part $part): ?MarketplaceCategoryMapping
    {
        return $this->categoryOverride($part, 'allegro', 'allegro_main') ?: $this->mappedCategory($part, ['allegro_main', 'allegro'], 'allegro_main');
    }

    private function ovokoCategoryMapping(Part $part): ?MarketplaceCategoryMapping
    {
        return $this->categoryOverride($part, 'ovoko', 'ovoko') ?: $this->mappedCategory($part, ['ovoko'], 'ovoko');
    }

    private function ebayCategoryMapping(Part $part, string $channel): ?MarketplaceCategoryMapping
    {
        return $this->categoryOverride($part, 'ebay', $channel) ?: $this->mappedCategory($part, [$channel, 'ebay'], $channel);
    }

    private function mappedCategory(Part $part, array $channels, string $preferredChannel): ?MarketplaceCategoryMapping
    {
        if (! Schema::hasTable('marketplace_category_mappings') || blank($part->category_id ?? null)) return null;
        return MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->whereIn('channel', $channels)->orderByRaw('case when channel = ? then 0 else 1 end', [$preferredChannel])->first();
    }

    private function categoryOverride(Part $part, string $key, string $fallbackChannel): ?MarketplaceCategoryMapping
    {
        $override = data_get(is_array($part->review_metadata) ? $part->review_metadata : [], 'marketplace_category_overrides.'.$key);
        if (! is_array($override) || blank($override['external_category_id'] ?? null)) return null;
        $mapping = new MarketplaceCategoryMapping();
        $mapping->forceFill([
            'local_category_id' => $part->category_id,
            'channel' => (string) ($override['channel'] ?? $fallbackChannel),
            'external_category_id' => (string) $override['external_category_id'],
            'external_category_name' => $override['external_category_name'] ?? null,
            'external_category_path' => $override['external_category_path'] ?? null,
            'source' => $override['source'] ?? 'review_metadata.marketplace_category_overrides',
        ]);
        return $mapping;
    }
    private function hasActiveListing(Part $part, ?string $marketplace, string $channel): bool { if (! $marketplace || ! Schema::hasTable('marketplace_listings')) return false; return MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', $marketplace)->whereNotNull('external_offer_id')->whereNotIn('status', ['ended', 'deleted', 'archived', 'inactive'])->exists(); }
    /** @return array<string, mixed> */
    private function safePayloadPreview(Part $part, string $channel, ?float $price, ?MarketplaceCategoryMapping $categoryMapping = null, ?array $ebayPrice = null, ?array $allegroParameters = null): array
    {
        $translation = $this->preparedTranslation($part, $channel);
        $itemSpecifics = str_starts_with($channel, 'ebay_') ? $this->ebayItemSpecificsService->build($part, $channel, $categoryMapping, $translation) : null;
        $preview = ['dry_run' => true, 'channel' => $channel, 'sku' => $part->sku ?? null, 'title' => $part->name ?? null, 'description_present' => filled(strip_tags((string) (($part->description ?? null) ?: ($part->short_description ?? null)))), 'condition_notes_present' => filled($part->condition_notes ?? null), 'price_pln' => $price, 'quantity' => $part->quantity ?? null, 'currency' => 'PLN', 'dimensions' => $this->dimensionsPayload($part), 'image_urls' => $this->imagesFor($part)->take(5)->map(fn ($image) => method_exists($image, 'listingUrl') ? $image->listingUrl() : null)->filter()->values()->all(), 'category_id' => $categoryMapping?->external_category_id ?? ($part->category_id ?? null), 'category_mapping_source' => $categoryMapping ? 'marketplace_category_mappings' : null, 'category_mapping_channel' => $categoryMapping?->channel, 'category_mapping_name' => $categoryMapping?->external_category_name, 'category_mapping_path' => $categoryMapping?->external_category_path, 'local_category_id' => $part->category_id ?? null, 'vehicle' => $this->vehiclePayload($part, $channel), 'diagnostics' => $this->diagnosticsPayload($part, $channel, $translation), 'translation_status' => $translation['status'], 'translation_language' => $translation['language'], 'translated_fields' => $translation['translated_fields'], 'untranslated_fields' => $translation['untranslated_fields'], 'will_make_marketplace_request' => false];

        if ($itemSpecifics) {
            $preview = array_merge($preview, $itemSpecifics);
            $preview['diagnostics'] = array_merge($preview['diagnostics'], array_intersect_key($itemSpecifics, array_flip(['item_specifics_present','item_specifics_count','item_specifics_source','item_specifics_missing_required','item_specifics_unmapped_fields','item_specifics_translation_status','item_specifics_translated_fields'])));
        }

        if (str_starts_with($channel, 'ebay_')) {
            $preview['price_source_pln'] = $price;
            $preview['price_pln'] = $price;
            $preview['price_eur'] = $ebayPrice['price_eur'] ?? null;
            $preview['currency'] = 'EUR';
            $preview['exchange_rate'] = $ebayPrice['exchange_rate'] ?? null;
            $renderData = $this->renderDataFromTranslation($translation, $part, $channel);
            $rendered = $this->ebayTemplateExists($channel) ? $this->ebayDescriptionTemplateRenderer->render($channel, $part, $renderData) : '';
            $preview['description_template_present'] = $this->ebayTemplateExists($channel);
            $preview['description_template_channel'] = $channel;
            $preview['description_rendered_present'] = filled(trim($rendered));
            $preview['description_template_asset_urls'] = $this->ebayDescriptionTemplateRenderer->assetUrls();
            $businessPolicies = $this->resolveEbayBusinessPolicies($this->accountFor($channel), $categoryMapping, $part, $channel);
            $preview['shipping_policy_resolution'] = $businessPolicies['shipping_policy_resolution'];
            $preview['business_policies'] = $businessPolicies['business_policies'];
            $preview['description_rendered_html'] = $rendered;
            $preview['diagnostics']['specification_rows_count'] = substr_count($rendered, '<tr><td');
        }

        if ($channel === 'allegro_main' && $allegroParameters !== null) {
            $preview['allegro_parameters'] = $allegroParameters;
            $preview['allegro_product_parameters'] = $allegroParameters['product_parameters'] ?? [];
            $preview['allegro_offer_parameters'] = $allegroParameters['offer_parameters'] ?? [];
            $preview['missing_required_parameters'] = $allegroParameters['missing_required_parameters'] ?? [];
            $preview['unmapped_parameters'] = $allegroParameters['unmapped_parameters'] ?? [];
            $preview['parameter_definitions_source'] = $allegroParameters['parameter_definitions_source'] ?? 'none';
            $preview['will_make_marketplace_request'] = false;
        }

        return $preview;
    }
    private function preparedTranslation(Part $part, string $channel): array
    {
        $data = data_get(is_array($part->review_metadata) ? $part->review_metadata : [], 'marketplace_prepared_translations.'.$channel, []);
        return is_array($data) ? ['status' => $data['status'] ?? 'not_prepared', 'language' => $data['language'] ?? strtolower((string) $this->targetLanguageForChannel($channel)), 'fields' => is_array($data['fields'] ?? null) ? $data['fields'] : [], 'item_specifics' => is_array($data['item_specifics'] ?? null) ? $data['item_specifics'] : [], 'translated_fields' => $data['translated_fields'] ?? [], 'untranslated_fields' => $data['untranslated_fields'] ?? []] : ['status' => 'not_prepared', 'language' => strtolower((string) $this->targetLanguageForChannel($channel)), 'fields' => [], 'item_specifics' => [], 'translated_fields' => [], 'untranslated_fields' => []];
    }
    private function renderDataFromTranslation(array $translation, ?Part $part = null, ?string $channel = null): array { $fields = $translation['fields'] ?? []; if (! is_array($fields)) return []; $data = []; foreach ($fields as $key => $value) { $data[str_replace('vehicle.', '', $key)] = $value; } if ($part && $channel) foreach (($this->vehiclePayload($part, $channel)['attributes'] ?? []) as $key => $value) { $data[$key] ??= $value; } if (($translation['status'] ?? null) !== 'prepared') $data['translation_fallback_notice'] = 'Tłumaczenia nieprzygotowane — użyj przycisku Przygotuj.'; return $data; }
    private function diagnosticsPayload(Part $part, string $channel, array $translation): array { $vehicle = $this->vehiclePayload($part, $channel, false); return ['vehicle_source' => $vehicle['source'], 'vehicle_present' => $vehicle['present'], 'vehicle_fields_present' => $vehicle['fields_present'], 'vehicle_fields_missing' => $vehicle['fields_missing'], 'translation_status' => $translation['status'], 'translation_language' => $translation['language'], 'translated_fields' => $translation['translated_fields'], 'untranslated_fields' => $translation['untranslated_fields']]; }
    private function vehiclePayload(Part $part, string $channel, bool $translated = true): array
    {
        $expected = ['make','model','model_variant','production_year','first_registration_year','steering_side','mileage_km','fuel_type','engine_power_kw','engine_capacity_cm3','engine_code','drivetrain','gearbox_type','gearbox_code','body_type','color_code','color','interior'];
        $src = $part->car_id && $part->car ? 'car' : (is_array($part->vehicle_snapshot ?? null) ? 'vehicle_snapshot' : 'none');
        $v = $src === 'car'
            ? $part->car->only($expected)
            : ($src === 'vehicle_snapshot' ? array_intersect_key($part->vehicle_snapshot, array_flip($expected)) : []);
        $lang = strtolower((string) $this->targetLanguageForChannel($channel));
        foreach (['fuel_type','color','steering_side','gearbox_type','body_type','drivetrain'] as $k) {
            if ($translated && isset($v[$k])) $v[$k] = $this->localVehicleTranslation((string) $v[$k], $lang) ?? $v[$k];
        }
        $present = array_values(array_filter($expected, fn($k) => filled($v[$k] ?? null)));
        $title = trim(implode(' ', array_filter([$v['make'] ?? null, $v['model'] ?? null, $v['model_variant'] ?? null]))).(filled($v['production_year'] ?? null) ? ' ('.$v['production_year'].')' : '');

        return ['source'=>$src,'present'=>$src !== 'none','car_id'=>$part->car_id ?? null,'snapshot_present'=>is_array($part->vehicle_snapshot ?? null),'title'=>trim($title),'attributes'=>$v,'attributes_source'=>$src === 'car' ? ($part->car?->only($expected) ?? []) : $v,'summary'=>implode(' · ', array_values(array_filter([$v['production_year'] ?? null,$v['body_type'] ?? null,$v['fuel_type'] ?? null,filled($v['engine_capacity_cm3'] ?? null) ? $v['engine_capacity_cm3'].' cm³' : null,$v['color'] ?? null,$v['drivetrain'] ?? null,$v['steering_side'] ?? null,$v['gearbox_type'] ?? null]))),'fields_present'=>$present,'fields_missing'=>array_values(array_diff($expected,$present))];
    }
    private function localVehicleTranslation(string $value, string $language): ?string { $n = Str::lower(trim($value)); $map = ['de'=>['benzyna'=>'Benzin','szary'=>'Grau','lewa strona'=>'Linkslenker','po lewej'=>'Linkslenker','automatyczny'=>'Automatik','automatyczna'=>'Automatik','używany'=>'Gebraucht','używana'=>'Gebraucht'],'fr'=>['benzyna'=>'Essence','szary'=>'Gris','lewa strona'=>'Volant à gauche','po lewej'=>'Volant à gauche','automatyczny'=>'Automatique','automatyczna'=>'Automatique','używany'=>'Occasion','używana'=>'Occasion']]; return $map[$language][$n] ?? null; }

    /** @return array<string, mixed> */
    private function unsupportedChannelResult(Part $part, string $channel): array { return ['channel' => $channel, 'can_prepare' => false, 'can_publish_later' => false, 'required_fields' => [], 'missing_fields' => ['channel'], 'warnings' => [], 'blockers' => ['Unsupported marketplace readiness channel.'], 'prepared_payload_preview_safe' => ['dry_run' => true, 'channel' => $channel, 'part_id' => $part->id, 'will_make_marketplace_request' => false], 'price_source' => 'none', 'local_price' => null, 'marketplace_price' => null, 'currency' => 'PLN', 'images_count' => 0, 'has_required_images' => false, 'category_ready' => false, 'vehicle_ready' => false, 'description_ready' => false, 'title_ready' => filled($part->name ?? null), 'stock_ready' => false, 'external_mapping_exists' => false, 'notes' => ['dry_run_only' => 'Readiness does not publish, update, delete, sync prices, sync stock, or import orders.']]; }
}
