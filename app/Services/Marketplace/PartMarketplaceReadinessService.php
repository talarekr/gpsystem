<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use Illuminate\Support\Facades\Schema;

class PartMarketplaceReadinessService
{
    public function __construct(private readonly MarketplaceListingReadinessService $listingReadinessService) {}

    /** @return array<string, array<string, mixed>> */
    public function check(Part $part, mixed $categoryId = null): array
    {
        $part = $this->partForCategory($part, $categoryId);

        return [
            'allegro' => $this->forMarketplace($part, 'allegro', 'allegro_main', ['allegro_main', 'allegro']),
            'ovoko' => $this->forMarketplace($part, 'ovoko', 'ovoko', ['ovoko']),
            'ebay' => $this->forMarketplace($part, 'eBay', 'ebay_de', ['ebay_de', 'ebay'], true),
        ];
    }

    private function partForCategory(Part $part, mixed $categoryId = null): Part
    {
        if (blank($categoryId) || (string) $part->category_id === (string) $categoryId) {
            return $part;
        }

        $preview = clone $part;
        $preview->category_id = $categoryId;

        return $preview;
    }

    /** @param array<int, string> $mappingChannels @return array<string, mixed> */
    private function forMarketplace(Part $part, string $label, string $channel, array $mappingChannels, bool $requiresEbayTranslations = false): array
    {
        try {
            $readiness = $this->listingReadinessService->checkPartReadiness($part, $channel);
            $missing = $this->polishFields($this->locallyRequiredMissingFields((array) ($readiness['missing_fields'] ?? [])));
            $warnings = $this->polishWarnings((array) ($readiness['warnings'] ?? []));
            $mapping = $this->categoryMapping($part, $mappingChannels, $channel);
            $translationMissing = $requiresEbayTranslations ? $this->missingEbayTranslations($part) : [];

            if (! $mapping) {
                $missing[] = 'mapowanie kategorii '.$label;
            } elseif ($mapping->is_blocked) {
                $missing[] = 'aktywne mapowanie kategorii '.$label;
                $warnings[] = 'Mapowanie kategorii jest oznaczone jako zablokowane: '.($mapping->block_reason ?: 'brak powodu.');
            }

            $ok = $this->okItems($readiness, $mapping, $label);
            $missing = array_values(array_unique(array_filter(array_merge($missing, $translationMissing))));
            $warnings[] = 'Podgląd gotowości — bez wystawiania oferty i bez zapisu do marketplace.';
            $status = $missing === [] ? 'ready' : 'missing';

            return [
                'status' => $status,
                'ready' => $status === 'ready',
                'missing' => $missing,
                'ok' => $ok,
                'warnings' => array_values(array_unique(array_filter($warnings))),
                'will_make_marketplace_request' => false,
                'source' => 'local_validation_only',
                'presentation' => $this->presentation($status, $missing, $mapping, $label, $requiresEbayTranslations),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'api_error',
                'ready' => false,
                'missing' => [],
                'ok' => [],
                'warnings' => ['Nie udało się przygotować podglądu gotowości: '.$e::class, 'Podgląd gotowości — bez wystawiania oferty i bez zapisu do marketplace.'],
                'will_make_marketplace_request' => false,
                'source' => 'local_validation_only',
                'presentation' => $this->presentation('api_error', ['Nie udało się przygotować podglądu gotowości.'], null, $label, $requiresEbayTranslations),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function presentation(string $status, array $missing, ?MarketplaceCategoryMapping $mapping, string $label, bool $requiresEbayTranslations): array
    {
        return [
            'status' => $status,
            'ready' => $status === 'ready',
            'message' => $status === 'ready' ? 'Gotowe' : ($missing[0] ?? 'Wymaga uzupełnienia'),
            'missing' => array_values(array_unique(array_filter($missing))),
            'category' => $this->categoryPresentation($mapping, $label),
            'requires_translations' => $requiresEbayTranslations,
            'translations' => $requiresEbayTranslations ? [
                ['label' => 'eBay DE', 'ready' => ! in_array('Brak przygotowanego tłumaczenia eBay DE', $missing, true)],
            ] : [],
            'safe_preview_only' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function categoryPresentation(?MarketplaceCategoryMapping $mapping, string $label): array
    {
        if (! $mapping) {
            return ['value' => 'Wybierz kategorię', 'mapped' => false, 'id' => null];
        }

        $category = $this->marketplaceCategory($mapping);
        $categoryId = filled($mapping->external_category_id) ? (string) $mapping->external_category_id : null;
        $value = $mapping->external_category_path
            ?: ($mapping->external_category_name
                ?: ($category?->full_path ?: ($category?->name ?: null)));

        if (blank($value) && filled($categoryId)) {
            $value = $label.' ID: '.$categoryId;
        }

        $displayName = $mapping->external_category_name ?: ($category?->name ?: null);
        if (blank($displayName) && filled($value)) {
            $segments = preg_split('/\s*(?:>|\/)\s*/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
            $displayName = $segments ? trim((string) end($segments)) : null;
        }

        return [
            'value' => $value ?: 'Wybierz kategorię',
            'display_name' => $displayName ?: ($value ?: 'Wybierz kategorię'),
            'short_display_name' => $this->shortCategoryDisplayName($displayName ?: ($value ?: 'Wybierz kategorię')),
            'leaf_name' => $displayName ?: null,
            'mapped' => ! $mapping->is_blocked,
            'id' => $categoryId,
            'source' => $mapping->source ?: 'inherited_from_local_category',
            'manual_override' => $mapping->source === 'manual_part_edit_marketplace_preparation',
        ];
    }


    private function shortCategoryDisplayName(?string $name): ?string
    {
        if (blank($name)) {
            return $name;
        }

        $name = trim((string) $name);
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) <= 2 && mb_strlen($name) <= 28) {
            return $name;
        }

        $prefix = implode(' ', array_slice($words, 0, 2));

        if (blank($prefix)) {
            $prefix = mb_substr($name, 0, 24);
        }

        return rtrim($prefix, ' .,;:-').'...';
    }

    private function marketplaceCategory(MarketplaceCategoryMapping $mapping): ?MarketplaceCategory
    {
        if (! Schema::hasTable('marketplace_categories') || blank($mapping->external_category_id)) {
            return null;
        }

        return MarketplaceCategory::query()
            ->where('channel', $mapping->channel)
            ->where('external_category_id', $mapping->external_category_id)
            ->first();
    }

    /** @return array<int, string> */
    private function missingEbayTranslations(Part $part): array
    {
        $metadata = (array) ($part->review_metadata ?: []);
        $translations = (array) (data_get($metadata, 'marketplace_translations') ?: data_get($part->legacy_payload, 'marketplace_translations') ?: []);

        return collect(['de' => 'Brak przygotowanego tłumaczenia eBay DE'])
            ->filter(function (string $label, string $locale) use ($translations, $metadata): bool {
                $channel = 'ebay_'.$locale;

                if (data_get($metadata, 'marketplace_prepared_translations.'.$channel.'.status') === 'prepared') {
                    return false;
                }

                return blank(data_get($translations, $channel.'.title')) || blank(data_get($translations, $channel.'.description'));
            })
            ->values()
            ->all();
    }

    /** @param array<int, string> $channels */
    private function categoryMapping(Part $part, array $channels, string $readinessChannel): ?MarketplaceCategoryMapping
    {
        if ($override = $this->manualOverrideMapping($part, $readinessChannel)) {
            return $override;
        }

        if (! Schema::hasTable('marketplace_category_mappings') || blank($part->category_id ?? null)) {
            return null;
        }

        $mappings = MarketplaceCategoryMapping::query()
            ->where('local_category_id', $part->category_id)
            ->whereIn('channel', $channels)
            ->whereNotNull('external_category_id')
            ->orderBy('is_blocked')
            ->get();

        foreach ($channels as $channel) {
            $mapping = $mappings->first(fn (MarketplaceCategoryMapping $mapping): bool => $mapping->channel === $channel);
            if ($mapping) {
                return $mapping;
            }
        }

        return null;
    }


    private function manualOverrideMapping(Part $part, string $readinessChannel): ?MarketplaceCategoryMapping
    {
        $key = match ($readinessChannel) {
            'allegro_main' => 'allegro',
            'ovoko' => 'ovoko',
            'ebay_de', 'ebay_fr' => 'ebay',
            default => $readinessChannel,
        };

        $override = data_get((array) ($part->review_metadata ?: []), 'marketplace_category_overrides.'.$key);

        if (! is_array($override) || blank($override['external_category_id'] ?? null)) {
            return null;
        }

        return new MarketplaceCategoryMapping([
            'local_category_id' => $part->category_id,
            'channel' => (string) ($override['channel'] ?? $readinessChannel),
            'external_category_id' => (string) $override['external_category_id'],
            'external_category_name' => $override['external_category_name'] ?? null,
            'external_category_path' => $override['external_category_path'] ?? null,
            'source' => 'manual_part_edit_marketplace_preparation',
            'confidence' => 1,
            'is_blocked' => false,
            'metadata' => ['part_id' => $part->id, 'override_key' => $key],
        ]);
    }

    /** @return array<int, string> */
    private function okItems(array $readiness, ?MarketplaceCategoryMapping $mapping, string $label): array
    {
        $items = [];
        foreach ([
            'title_ready' => 'tytuł produktu',
            'description_ready' => 'opis',
            'has_required_images' => 'zdjęcia',
            'stock_ready' => 'ilość / stan magazynowy',
            'vehicle_ready' => 'dane pojazdu',
        ] as $key => $text) {
            if ((bool) ($readiness[$key] ?? false)) {
                $items[] = $text;
            }
        }

        if (is_numeric($readiness['marketplace_price'] ?? null) && (float) $readiness['marketplace_price'] > 0) {
            $items[] = 'cena marketplace';
        }
        if ($mapping && ! $mapping->is_blocked) {
            $items[] = 'mapowanie kategorii '.$label;
        }
        if (! in_array('stan / jakość', $items, true)) {
            $items[] = 'stan / jakość sprawdzany lokalnie';
        }

        return array_values(array_unique($items));
    }


    /** @param array<int, mixed> $fields @return array<int, mixed> */
    private function locallyRequiredMissingFields(array $fields): array
    {
        return array_values(array_diff(array_map('strval', $fields), [
            'translation_credentials',
            'description_template',
            'business_policies',
            'marketplace_country',
        ]));
    }

    /** @param array<int, mixed> $fields @return array<int, string> */
    private function polishFields(array $fields): array
    {
        $map = [
            'title' => 'tytuł produktu', 'price' => 'cena', 'quantity' => 'ilość', 'images' => 'zdjęcia',
            'allegro_price_pln' => 'cena Allegro', 'ovoko_price_pln' => 'cena Ovoko', 'ebay_price_pln' => 'cena eBay',
            'allegro_category_mapping' => 'mapowanie kategorii Allegro', 'ovoko_category_mapping' => 'mapowanie kategorii Ovoko', 'ebay_category_mapping' => 'mapowanie kategorii eBay',
            'description' => 'opis', 'description_or_condition' => 'opis albo stan / jakość', 'vehicle' => 'dane pojazdu',
            'translation_credentials' => 'konfiguracja tłumaczeń', 'description_template' => 'szablon opisu eBay', 'business_policies' => 'Brak ustawień polityk eBay', 'marketplace_country' => 'kraj marketplace',
            'category_shipping_group' => 'Brak grupy wysyłkowej dla kategorii', 'shipping_policy_mapping' => 'Brak mapowania polityki wysyłki',
            'payment_policy' => 'Brak polityki płatności', 'return_policy' => 'Brak polityki zwrotów',
            'allegro_required_category_parameters_missing' => 'Brakuje wymaganych parametrów Allegro', 'prepared_translations' => 'Brak przygotowanego tłumaczenia eBay DE',
        ];

        return array_map(fn ($field): string => $map[(string) $field] ?? (string) $field, $fields);
    }

    /** @param array<int, mixed> $warnings @return array<int, string> */
    private function polishWarnings(array $warnings): array
    {
        return array_map(fn ($warning): string => (string) $warning, $warnings);
    }
}
