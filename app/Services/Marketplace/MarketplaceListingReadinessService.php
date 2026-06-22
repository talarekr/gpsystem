<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Arr;

class MarketplaceListingReadinessService
{
    public const CHANNELS = ['storefront', 'allegro_main', 'ovoko', 'ebay_de', 'ebay_fr'];

    public function __construct(private readonly TranslationService $translationService) {}

    /** @return array<string, mixed> */
    public function checkPartReadiness(Part $part, string $channel): array
    {
        $part->loadMissing(['images', 'category', 'car', 'marketplaceListings.account']);
        $channel = $channel === 'ebay' ? 'ebay_de' : $channel;

        if (! in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('Unsupported marketplace readiness channel.');
        }

        $required = ['title', 'price', 'quantity', 'images'];
        $missing = [];
        $warnings = [];
        $blockers = [];
        $notes = ['dry_run_only' => 'Readiness does not publish, update, delete, sync prices, sync stock, or import orders.'];
        $marketplace = $this->marketplaceCode($channel);
        $account = $this->accountFor($channel);
        $price = $this->priceFor($part, $channel);
        $imagesCount = $part->images->count();
        $hasActiveListing = $marketplace ? $this->hasActiveListing($part, $marketplace, $channel) : false;

        $this->requireFilled($part->name, 'title', $missing, $blockers);
        $this->requirePositive($price, 'price', $missing, $blockers);
        $this->requirePositive($part->quantity, 'quantity', $missing, $blockers);
        if ($imagesCount < 1) {
            $missing[] = 'images';
            $blockers[] = 'At least one part image is required.';
        }

        $categoryReady = filled($part->category_id);
        $vehicleReady = $part->car_id !== null || is_array($part->vehicle_snapshot);
        $descriptionReady = filled(strip_tags((string) $part->description)) || filled(strip_tags((string) $part->short_description));
        $titleReady = filled($part->name);
        $stockReady = is_numeric($part->quantity) && (int) $part->quantity > 0;

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
            if (! $descriptionReady && blank($part->condition_notes)) { $missing[] = 'description_or_condition'; $warnings[] = 'Ovoko description or condition notes are missing.'; }
        } else {
            $required = ['title', 'ebay_price_pln', 'quantity', 'images', 'translation_credentials', 'description_template', 'business_policies', 'marketplace_country'];
            $this->checkEbayAccount($account, $blockers, $warnings);
            if (! $this->translationService->isGoogleTranslateConfigured()) { $missing[] = 'translation_credentials'; $warnings[] = 'Google Translate credentials are not configured for later title/description/condition translation dry-runs.'; }
            if (! $this->ebayTemplateExists($channel)) { $missing[] = 'description_template'; $blockers[] = 'eBay description template for '.$channel.' is missing.'; }
            if (! $this->ebayBusinessPoliciesReady($account)) { $missing[] = 'business_policies'; $blockers[] = 'eBay business policies are missing: payment, fulfillment/shipping, or return.'; }
            $notes['translation'] = ['source_language' => 'PL', 'target_language' => $this->translationService->targetLanguageForChannel($channel), 'fields' => ['title', 'description', 'condition_notes']];
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
            'prepared_payload_preview_safe' => $this->safePayloadPreview($part, $channel, $price),
            'price_source' => $this->priceSource($channel),
            'local_price' => $part->price !== null ? (float) $part->price : null,
            'marketplace_price' => $price,
            'currency' => 'PLN',
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
        $channels = collect(self::CHANNELS)->mapWithKeys(fn (string $channel): array => [$channel => $this->checkPartReadiness($part, $channel)])->all();
        return ['channels' => $channels, 'summary' => ['ready_channels' => array_keys(array_filter($channels, fn ($r) => $r['can_prepare'])), 'blocked_channels' => array_keys(array_filter($channels, fn ($r) => $r['blockers'] !== [])), 'warning_channels' => array_keys(array_filter($channels, fn ($r) => $r['warnings'] !== []))], 'blockers' => array_merge(...array_map(fn ($r) => $r['blockers'], $channels)), 'warnings' => array_merge(...array_map(fn ($r) => $r['warnings'], $channels))];
    }

    private function accountFor(string $channel): ?MarketplaceAccount { $code = $channel === 'ovoko' ? 'ovoko_main' : $channel; return MarketplaceAccount::query()->where('code', $code)->first(); }
    private function marketplaceCode(string $channel): ?string { return match (true) { $channel === 'storefront' => null, $channel === 'allegro_main' => 'allegro', str_starts_with($channel, 'ebay_') => $channel, default => $channel }; }
    private function priceFor(Part $part, string $channel): ?float { $value = match ($channel) { 'ovoko' => $part->ovoko_price, 'ebay_de', 'ebay_fr' => $part->ebay_price ?? (is_numeric($part->price) ? round((float) $part->price * 1.25, 2) : null), 'allegro_main' => $part->allegro_price ?? $part->price, default => $part->price }; return is_numeric($value) ? (float) $value : null; }
    private function priceSource(string $channel): string { return match ($channel) { 'ovoko' => 'parts.ovoko_price_pln', 'ebay_de', 'ebay_fr' => 'parts.ebay_price_pln_or_storefront_price_x_1_25_pln', 'allegro_main' => 'parts.allegro_price_pln_or_storefront_price_pln', default => 'parts.price_pln' }; }
    private function requireFilled(mixed $value, string $field, array &$missing, array &$blockers): void { if (blank($value)) { $missing[] = $field; $blockers[] = ucfirst($field).' is required.'; } }
    private function requirePositive(mixed $value, string $field, array &$missing, array &$blockers): void { if (! is_numeric($value) || (float) $value <= 0) { $missing[] = $field; $blockers[] = ucfirst($field).' must be greater than zero.'; } }
    private function checkAccount(?MarketplaceAccount $account, array &$blockers, array &$warnings, string $missingMessage): void { if (! $account || ! $account->api_enabled || $account->status !== 'enabled') $blockers[] = $missingMessage; elseif ($account->last_connection_status && $account->last_connection_status !== 'ok') $warnings[] = 'Last read-only API connection check is not ok.'; }
    private function checkEbayAccount(?MarketplaceAccount $account, array &$blockers, array &$warnings): void { if (! $account || ! $account->api_enabled) $blockers[] = 'eBay API not configured'; else $this->checkAccount($account, $blockers, $warnings, 'eBay API not configured'); }
    private function ebayTemplateExists(string $channel): bool { return filled(config('marketplace.ebay.templates.'.$channel)) || view()->exists('marketplace.ebay.templates.'.$channel); }
    private function ebayBusinessPoliciesReady(?MarketplaceAccount $account): bool { $settings = is_array($account?->api_settings) ? $account->api_settings : []; return filled(Arr::get($settings, 'business_policies.payment')) && filled(Arr::get($settings, 'business_policies.fulfillment')) && filled(Arr::get($settings, 'business_policies.return')); }
    private function hasActiveListing(Part $part, ?string $marketplace, string $channel): bool { if (! $marketplace) return false; return MarketplaceListing::query()->where('part_id', $part->id)->where('marketplace', $marketplace)->whereNotNull('external_offer_id')->whereNotIn('status', ['ended', 'deleted', 'archived', 'inactive'])->exists(); }
    /** @return array<string, mixed> */
    private function safePayloadPreview(Part $part, string $channel, ?float $price): array { return ['dry_run' => true, 'channel' => $channel, 'sku' => $part->sku, 'title' => $part->name, 'description_present' => filled(strip_tags((string) ($part->description ?: $part->short_description))), 'condition_notes_present' => filled($part->condition_notes), 'price_pln' => $price, 'quantity' => $part->quantity, 'currency' => 'PLN', 'image_urls' => $part->images->take(5)->map(fn ($image) => $image->listingUrl())->filter()->values()->all(), 'category_id' => $part->category_id, 'vehicle' => ['car_id' => $part->car_id, 'snapshot_present' => is_array($part->vehicle_snapshot)], 'will_make_marketplace_request' => false]; }
}
