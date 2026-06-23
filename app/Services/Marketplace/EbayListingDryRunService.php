<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EbayListingDryRunService
{
    private const CHANNELS = ['ebay_de' => 'EBAY_DE', 'ebay_fr' => 'EBAY_FR'];

    public function __construct(
        private readonly EbayDescriptionTemplateService $templateService,
        private readonly GoogleTranslateService $translateService,
        private readonly NbpExchangeRateService $exchangeRateService,
    ) {}

    public function readiness(int $partId, string $channel): array
    {
        $blockers = [];
        $warnings = ['Read-only dry-run only: no eBay write API calls, no inventory item, no offer, no publish, no local listing/part mutations.'];
        $part = Part::query()->with(['category', 'images', 'marketplaceListings', 'car'])->find($partId);
        if (! $part) $blockers[] = 'Part not found.';
        if (! isset(self::CHANNELS[$channel])) $blockers[] = 'Unsupported channel. Allowed: ebay_de, ebay_fr.';

        $account = isset(self::CHANNELS[$channel]) && Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $channel)->first() : null;
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $marketplaceId = $settings['marketplace_id'] ?? (self::CHANNELS[$channel] ?? null);
        $mapping = $part && isset(self::CHANNELS[$channel]) && Schema::hasTable('marketplace_category_mappings')
            ? MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->where('channel', $channel)->first()
            : null;

        if (! $account) $blockers[] = 'Marketplace account not found.';
        if ($account && ! $account->api_enabled) $blockers[] = 'eBay API is disabled for this account.';
        if ($account && $account->api_mode !== 'dry_run') $blockers[] = 'api_mode must be dry_run.';
        foreach (['client_id', 'client_secret', 'refresh_token'] as $key) if ($account && blank($credentials[$key] ?? null)) $blockers[] = "OAuth/API credential missing: {$key}.";
        if (blank($marketplaceId)) $blockers[] = 'Marketplace ID missing.';
        if ($part && ! $mapping) $blockers[] = 'Category mapping missing in marketplace_category_mappings.';
        if ($mapping?->is_blocked) $blockers[] = 'Category is blocked for this eBay channel.';
        if ($mapping && blank($mapping->external_category_id)) $blockers[] = 'External eBay category ID missing.';

        $paymentPolicyId = $this->resolvedPaymentPolicyId($settings);
        $returnPolicyId = $this->resolvedReturnPolicyId($settings);
        if ($mapping && blank($mapping->fulfillment_policy_id)) $blockers[] = 'fulfillment_policy_id missing.';
        if (blank($paymentPolicyId)) $blockers[] = 'payment_policy_id missing from marketplace account api_settings/config.';
        if (blank($returnPolicyId)) $blockers[] = 'return_policy_id missing from marketplace account api_settings/config.';

        $ebayPricePln = $this->money($part?->ebay_price ?? null);
        $storefrontPricePln = $this->money($part?->price ?? null);
        if ($part && $storefrontPricePln === null) $blockers[] = 'Storefront price missing.';
        $ebayPriceSource = null;
        if ($part && $ebayPricePln !== null) {
            $ebayPriceSource = 'parts.ebay_price';
        } elseif ($part && $storefrontPricePln !== null) {
            $ebayPricePln = round($storefrontPricePln * 1.25, 2);
            $ebayPriceSource = 'calculated_from_storefront_price';
        }
        if ($part && $ebayPricePln === null) $blockers[] = 'eBay price missing/cannot be calculated.';
        $conversion = $this->exchangeRateService->eurPln();
        $rate = is_numeric($conversion['rate'] ?? null) ? (float) $conversion['rate'] : null;
        if ($rate === null) {
            $blockers[] = 'PLN to EUR conversion is not configured/available; price is not guessed.';
            if (filled($conversion['warning'] ?? null)) $warnings[] = (string) $conversion['warning'];
        }
        $estimatedEur = $ebayPricePln !== null && $rate !== null ? round($ebayPricePln / $rate, 2) : null;

        if ($part && (int) $part->quantity <= 0) $blockers[] = 'Quantity must be greater than zero.';
        if ($part && in_array($part->status, ['archived', 'sold'], true)) $blockers[] = 'Part status is archived or sold.';
        if ($part && (bool) $part->needs_listing) $blockers[] = 'Part is marked needs_listing.';
        if ($part && blank($part->name)) $blockers[] = 'Title missing.';

        $template = $part && isset(self::CHANNELS[$channel]) ? $this->templateService->validate($part->id, $channel) : ['ok' => false, 'html_length' => 0, 'missing_assets' => [], 'warnings' => [], 'blockers' => []];
        foreach ($template['blockers'] ?? [] as $b) $blockers[] = 'Template: '.$b;
        if (! ($template['ok'] ?? false)) $blockers[] = 'Template validation is not OK.';
        $warnings = array_merge($warnings, $template['warnings'] ?? []);
        $preview = $part && isset(self::CHANNELS[$channel]) ? $this->templateService->preview($part->id, $channel) : [];

        $imageUrls = $part ? $part->images->map(fn ($image) => $image->listingUrl())->filter()->values() : collect();
        if ($part && $imageUrls->isEmpty()) $blockers[] = 'No public product images available.';
        $translation = $this->translation($channel, $preview);
        if ($translation['required'] && ! $translation['available']) $blockers[] = 'Google Translate is required for FR preview but is not available.';

        $aspectDiagnostics = $this->aspectsWithDiagnostics($part);
        $warnings = array_merge($warnings, $aspectDiagnostics['warnings']);

        $existing = $this->existingListing($part, $channel);
        if ($existing['exists'] && in_array(strtolower((string) $existing['status']), ['active', 'published', 'live'], true)) $warnings[] = 'Existing active local marketplace listing found; do not create a duplicate.';

        return [
            'ok' => true,
            'ready' => $blockers === [],
            'part_id' => $partId,
            'channel' => $channel,
            'marketplace_id' => $marketplaceId,
            'category' => ['local_category_id' => $part?->category_id, 'external_category_id' => $mapping?->external_category_id, 'external_category_name' => $mapping?->external_category_name, 'is_blocked' => (bool) ($mapping?->is_blocked ?? false), 'block_reason' => $mapping?->block_reason],
            'business_policies' => ['fulfillment_policy_id' => $mapping?->fulfillment_policy_id, 'payment_policy_id' => $paymentPolicyId, 'return_policy_id' => $returnPolicyId],
            'price' => ['storefront_price_pln' => $storefrontPricePln, 'ebay_price_pln' => $ebayPricePln, 'ebay_price_source' => $ebayPriceSource, 'eur_rate' => $rate, 'estimated_price_eur' => $estimatedEur, 'currency' => 'EUR', 'conversion_source' => $rate === null ? null : 'nbp', 'conversion_fetched_at' => $conversion['fetched_at'] ?? null, 'conversion_effective_date' => $conversion['effective_date'] ?? null],
            'inventory' => ['quantity' => $part?->quantity, 'status' => $part?->status, 'needs_listing' => (bool) ($part?->needs_listing ?? false)],
            'template' => ['ok' => (bool) ($template['ok'] ?? false), 'html_length' => $template['html_length'] ?? 0, 'missing_assets' => $template['missing_assets'] ?? [], 'warnings' => $template['warnings'] ?? []],
            'images' => ['count' => $part?->images->count() ?? 0, 'public_urls_sample' => $imageUrls->take(5)->all(), 'missing_public_images_count' => max(0, ($part?->images->count() ?? 0) - $imageUrls->count())],
            'translation' => $translation,
            'translated_specification_values' => $preview['translated_specification_values'] ?? [],
            'untranslated_technical_values' => $preview['untranslated_technical_values'] ?? [],
            'aspects_source' => $aspectDiagnostics['aspects_source'],
            'existing_listing' => $existing,
            'compatibility' => $this->listingCompatibilitySummary($part, $channel, $mapping?->external_category_id, $marketplaceId),
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function dryRunPayload(int $partId, string $channel): array
    {
        $ready = $this->readiness($partId, $channel);
        $part = Part::query()->with(['images', 'car'])->find($partId);
        $preview = $part && isset(self::CHANNELS[$channel]) ? $this->templateService->preview($part->id, $channel) : [];
        $sku = $part?->sku ?: 'part-'.$partId;
        $publishable = ($ready['ready'] ?? false) && ! ($ready['category']['is_blocked'] ?? false);
        $imageUrls = $part ? $part->images->map(fn ($image) => $image->listingUrl())->filter()->values()->all() : [];
        $title = (string) ($preview['title'] ?? $part?->name ?? '');
        $short = (string) ($preview['short_inventory_description'] ?? Str::limit(strip_tags((string) ($part?->description ?? '')), 400, ''));
        $html = (string) ($preview['listing_description_html'] ?? '');
        $priceEur = $ready['price']['estimated_price_eur'] ?? null;
        $aspectDiagnostics = $this->aspectsWithDiagnostics($part);
        $warnings = array_values(array_unique(array_merge($ready['warnings'] ?? [], $aspectDiagnostics['warnings'])));

        return [
            'ok' => true,
            'dry_run' => true,
            'part_id' => $partId,
            'channel' => $channel,
            'marketplace_id' => $ready['marketplace_id'] ?? null,
            'sku' => $sku,
            'planned_steps' => [
                'inventory_item_upsert' => ['would_send' => false, 'method' => 'PUT', 'path' => '/sell/inventory/v1/inventory_item/{sku}', 'dry_run_only' => true],
                'offer_create_or_update' => ['would_send' => false, 'method' => 'POST/PATCH', 'path' => '/sell/inventory/v1/offer', 'dry_run_only' => true],
                'offer_publish' => ['would_send' => false, 'method' => 'POST', 'path' => '/sell/inventory/v1/offer/{offerId}/publish', 'dry_run_only' => true, 'publishable_payload_ready' => $publishable],
            ],
            'inventory_item_payload' => $publishable ? ['sku' => $sku, 'product' => ['title' => $title, 'description' => $short, 'imageUrls' => $imageUrls, 'aspects' => $aspectDiagnostics['aspects']], 'condition' => 'USED_EXCELLENT', 'availability' => ['shipToLocationAvailability' => ['quantity' => (int) ($part?->quantity ?? 0)]]] : null,
            'offer_payload' => $publishable ? ['marketplaceId' => $ready['marketplace_id'], 'format' => 'FIXED_PRICE', 'availableQuantity' => (int) ($part?->quantity ?? 0), 'categoryId' => $ready['category']['external_category_id'], 'listingDescription' => $html, 'pricingSummary' => ['price' => ['value' => $priceEur, 'currency' => 'EUR']], 'listingPolicies' => ['fulfillmentPolicyId' => $ready['business_policies']['fulfillment_policy_id'], 'paymentPolicyId' => $ready['business_policies']['payment_policy_id'], 'returnPolicyId' => $ready['business_policies']['return_policy_id']]] : null,
            'listing_description_html_length' => Str::length($html),
            'translated_specification_values' => $preview['translated_specification_values'] ?? [],
            'untranslated_technical_values' => $preview['untranslated_technical_values'] ?? [],
            'aspects_source' => $aspectDiagnostics['aspects_source'],
            'compatibility' => $ready['compatibility'] ?? $this->listingCompatibilitySummary($part, $channel, $ready['category']['external_category_id'] ?? null, $ready['marketplace_id'] ?? null),
            'blockers' => $ready['blockers'] ?? [],
            'warnings' => $warnings,
        ];
    }


    public function compatibilityDiagnostics(int $partId, string $channel): array
    {
        $part = Part::query()->with(['category', 'car'])->find($partId);
        $channelSupported = isset(self::CHANNELS[$channel]);
        $marketplaceId = $this->marketplaceId($channel);
        $mapping = $part && $channelSupported && Schema::hasTable('marketplace_category_mappings')
            ? MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->where('channel', $channel)->first()
            : null;
        $diagnostics = $this->vehicleCompatibilityDiagnostics($part, $mapping?->external_category_id, $marketplaceId);
        $blockers = $diagnostics['blockers'];
        $warnings = $diagnostics['warnings'];

        if (! $part) $blockers[] = 'Part not found.';
        if (! $channelSupported) $blockers[] = 'Unsupported channel. Allowed: ebay_de, ebay_fr.';
        if ($part && ! $mapping) $blockers[] = 'Category mapping missing in marketplace_category_mappings.';
        if ($mapping?->is_blocked) $blockers[] = 'Category is blocked for this eBay channel.';
        if ($mapping && blank($mapping->external_category_id)) $blockers[] = 'External eBay category ID missing.';
        if (blank($marketplaceId)) $blockers[] = 'Marketplace ID missing.';

        return [
            'ok' => true,
            'part_id' => $partId,
            'channel' => $channel,
            'compatibility_status' => $this->compatibilityStatus($part),
            'source' => $diagnostics['source'],
            'vehicle_count' => $diagnostics['vehicle_count'],
            'vehicles_sample' => $diagnostics['vehicles_sample'],
            'missing_required_vehicle_fields' => $diagnostics['missing_required_vehicle_fields'],
            'can_build_ebay_fitment_payload' => $diagnostics['can_build_ebay_fitment_payload'],
            'ebay_category_id' => $mapping?->external_category_id,
            'marketplace_id' => $marketplaceId,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function dryRunCompatibilityPayload(int $partId, string $channel): array
    {
        $diagnostics = $this->compatibilityDiagnostics($partId, $channel);
        $payload = [
            'ebay_fitment_payload' => null,
            'local_description_compatibility' => $diagnostics['vehicles_sample'],
            'payload_ready_for_ebay_write' => false,
            'note' => 'Local dry-run only; donor/legacy vehicle data can be rendered in the description, but is not sent as eBay Product Compatibility without category-specific requirements.',
        ];

        return [
            'ok' => true,
            'dry_run' => true,
            'part_id' => $partId,
            'channel' => $channel,
            'marketplace_id' => $diagnostics['marketplace_id'],
            'category_id' => $diagnostics['ebay_category_id'],
            'compatibility_payload' => $payload,
            'vehicle_count' => $diagnostics['vehicle_count'],
            'vehicles_sample' => $diagnostics['vehicles_sample'],
            'would_send' => false,
            'blockers' => $diagnostics['blockers'],
            'warnings' => $diagnostics['warnings'],
        ];
    }

    public function readinessAll(int $partId): array
    {
        $channels = ['ebay_de' => $this->readiness($partId, 'ebay_de'), 'ebay_fr' => $this->readiness($partId, 'ebay_fr')];
        return ['part_id' => $partId, 'channels' => $channels, 'overall_ready' => collect($channels)->every(fn ($r) => (bool) $r['ready']), 'blockers' => array_values(array_unique(array_merge($channels['ebay_de']['blockers'], $channels['ebay_fr']['blockers']))), 'warnings' => array_values(array_unique(array_merge($channels['ebay_de']['warnings'], $channels['ebay_fr']['warnings'])))];
    }

    public function resolvedPaymentPolicyId(array $settings): ?string { return $this->policyId($settings, 'payment'); }
    public function resolvedReturnPolicyId(array $settings): ?string { return $this->policyId($settings, 'return'); }
    private function policyId(array $settings, string $type): ?string { foreach (["{$type}_policy_id", "default_{$type}_policy_id", "ebay_{$type}_policy_id"] as $key) if (filled($settings[$key] ?? null)) return (string) $settings[$key]; $policies = is_array($settings['business_policies'] ?? null) ? $settings['business_policies'] : []; return filled($policies[$type] ?? null) ? (string) $policies[$type] : null; }
    private function money(mixed $value): ?float { return is_numeric($value) ? round((float) $value, 2) : null; }
    private function translation(string $channel, array $preview): array { $required = $channel === 'ebay_fr'; $readiness = $required ? $this->translateService->readiness(false) : ['ok' => true]; return ['required' => $required, 'available' => (bool) ($readiness['ok'] ?? false), 'translation_needed_fields' => $preview['translation_needed_fields'] ?? []]; }
    private function existingListing(?Part $part, string $channel): array { $listing = $part ? MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', $channel)->whereIn('status', ['active', 'published', 'live'])->first() : null; return ['exists' => $listing !== null, 'status' => $listing?->status, 'external_offer_id' => $listing?->external_offer_id]; }
    private function compatibilityStatus(?Part $part): string { if (! $part) return 'missing'; return ($part->car_id || filled($part->vehicle_snapshot)) ? 'available' : 'not_required_yet'; }

    private function listingCompatibilitySummary(?Part $part, string $channel, ?string $categoryId, ?string $marketplaceId): array
    {
        $diagnostics = $this->vehicleCompatibilityDiagnostics($part, $categoryId, $marketplaceId);

        return [
            'status' => $this->compatibilityStatus($part),
            'payload_ready' => $diagnostics['can_build_ebay_fitment_payload'],
            'vehicle_count' => $diagnostics['vehicle_count'],
            'included_in_listing_description' => $diagnostics['vehicle_count'] > 0,
            'included_as_ebay_fitment_payload' => false,
            'blockers' => $diagnostics['blockers'],
            'warnings' => $diagnostics['warnings'],
        ];
    }

    private function vehicleCompatibilityDiagnostics(?Part $part, ?string $categoryId, ?string $marketplaceId): array
    {
        $vehicles = $this->compatibilityVehicles($part);
        $missing = [];
        foreach ($vehicles as $vehicle) {
            $missing[] = array_values(array_filter(['make', 'model', 'production_year'], fn (string $field) => blank($vehicle[$field] ?? null)));
        }
        $missingRequired = array_values(array_unique(array_merge(...($missing ?: [[]]))));
        $blockers = [];
        $warnings = ['Read-only compatibility dry-run only: no eBay API write, no inventory item, no offer, no publish, no price/stock sync, no product/listing mutation.'];

        if ($vehicles === []) {
            $blockers[] = 'No donor vehicle, fitment table rows, part vehicle relation, or legacy vehicle metadata found.';
        }
        if ($missingRequired !== []) {
            $blockers[] = 'Vehicle compatibility data is missing required local fields: '.implode(', ', $missingRequired).'.';
        }
        if (blank($categoryId)) {
            $blockers[] = 'Cannot evaluate eBay fitment payload without mapped eBay category ID.';
        }
        if (blank($marketplaceId)) {
            $blockers[] = 'Cannot evaluate eBay fitment payload without marketplace ID.';
        }

        $blockers[] = 'Full eBay Product Compatibility requirements/properties are not available locally for this category; not guessing fitment property names or values.';
        if (($vehicles[0]['source'] ?? null) === 'donor_vehicle') {
            $warnings[] = 'Only donor vehicle compatibility was found; use it in the listing description, not as full eBay fitment, unless category-specific eBay compatibility requirements are imported.';
        }

        return [
            'source' => $vehicles[0]['source'] ?? null,
            'vehicle_count' => count($vehicles),
            'vehicles_sample' => array_slice($vehicles, 0, 10),
            'missing_required_vehicle_fields' => $missingRequired,
            'can_build_ebay_fitment_payload' => false,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function compatibilityVehicles(?Part $part): array
    {
        if (! $part) return [];
        $vehicle = [];
        foreach (['make', 'model', 'model_variant', 'production_year', 'production_period', 'engine_capacity_cm3', 'engine_code', 'fuel_type', 'gearbox_type', 'body_type', 'steering_side'] as $key) {
            $vehicle[$key] = $this->cleanAspectValue($part->storefrontDetailValue($key));
        }
        $vehicle = array_filter($vehicle, fn ($value) => filled($value));
        if ($vehicle !== []) {
            $vehicle['source'] = ($part->car_id || $part->relationLoaded('car') && $part->car) ? 'donor_vehicle' : 'legacy_vehicle_metadata';
            $vehicle['car_id'] = $part->car_id;
            return [$vehicle];
        }

        return [];
    }


    private function marketplaceId(string $channel): ?string
    {
        $account = isset(self::CHANNELS[$channel]) && Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $channel)->first() : null;
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        return $settings['marketplace_id'] ?? (self::CHANNELS[$channel] ?? null);
    }

    private function aspectsWithDiagnostics(?Part $part): array
    {
        $brand = $part ? $this->cleanAspectValue($part->storefrontDetailValue('make')) : null;
        $source = $brand ? 'vehicle_make' : null;
        $fallbackUsed = false;

        if (! $brand) {
            $brand = $this->cleanAspectValue(data_get($part?->legacy_payload, 'brand') ?? data_get($part?->legacy_payload, 'manufacturer'));
            $source = $brand ? 'legacy_payload_brand_or_manufacturer' : null;
        }

        if (! $brand || $this->looksLikePartCode($brand, $part)) {
            $brand = 'GPSwiss';
            $source = 'fallback_gpswiss';
            $fallbackUsed = true;
        }

        $aspects = array_filter([
            'Brand' => $brand,
            'Manufacturer Part Number' => $part?->part_number,
            'Reference OE/OEM Number' => $part?->oem_number,
        ]);

        return [
            'aspects' => $aspects,
            'aspects_source' => [
                'Brand' => $source,
                'Manufacturer Part Number' => filled($part?->part_number) ? 'parts.part_number' : null,
                'Reference OE/OEM Number' => filled($part?->oem_number) ? 'parts.oem_number' : null,
            ],
            'warnings' => $fallbackUsed ? ['Brand aspect uses fallback GPSwiss because no vehicle/manufacturer brand was available; part number was not used as Brand.'] : [],
        ];
    }

    private function cleanAspectValue(mixed $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?: '');
        return $value === '' ? null : $value;
    }

    private function looksLikePartCode(string $value, ?Part $part): bool
    {
        $normalized = mb_strtolower($value);
        foreach ([$part?->part_number, $part?->oem_number, $part?->sku, $part?->manufacturer_code] as $code) {
            if (filled($code) && $normalized === mb_strtolower((string) $code)) return true;
        }

        return preg_match('/\d/u', $value) === 1 && preg_match('/^[A-Z0-9 .\/-]{5,}$/u', $value) === 1;
    }
}
