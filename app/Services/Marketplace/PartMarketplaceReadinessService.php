<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use Illuminate\Support\Facades\Schema;

class PartMarketplaceReadinessService
{
    public function __construct(private readonly MarketplaceListingReadinessService $listingReadinessService) {}

    /** @return array<string, array<string, mixed>> */
    public function check(Part $part): array
    {
        return [
            'allegro' => $this->forMarketplace($part, 'allegro', 'allegro_main', ['allegro_main', 'allegro']),
            'ovoko' => $this->forMarketplace($part, 'ovoko', 'ovoko', ['ovoko']),
            'ebay' => $this->forMarketplace($part, 'eBay', 'ebay_de', ['ebay_de', 'ebay'], true),
        ];
    }

    /** @param array<int, string> $mappingChannels @return array<string, mixed> */
    private function forMarketplace(Part $part, string $label, string $channel, array $mappingChannels, bool $requiresEbayTranslations = false): array
    {
        try {
            $readiness = $this->listingReadinessService->checkPartReadiness($part, $channel);
            $missing = $this->polishFields($this->locallyRequiredMissingFields((array) ($readiness['missing_fields'] ?? [])));
            $warnings = $this->polishWarnings((array) ($readiness['warnings'] ?? []));
            $mapping = $this->categoryMapping($part, $mappingChannels);
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
            'message' => $status === 'ready' ? 'Produkt gotowy' : 'Uzupełnij braki',
            'missing' => array_values(array_unique(array_filter($missing))),
            'category' => $this->categoryPresentation($mapping, $label),
            'requires_translations' => $requiresEbayTranslations,
            'translations' => $requiresEbayTranslations ? [
                ['label' => 'eBay DE', 'ready' => ! in_array('tłumaczenie eBay DE', $missing, true)],
                ['label' => 'eBay FR', 'ready' => ! in_array('tłumaczenie eBay FR', $missing, true)],
            ] : [],
            'safe_preview_only' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function categoryPresentation(?MarketplaceCategoryMapping $mapping, string $label): array
    {
        if (! $mapping) {
            return ['label' => 'Kategoria', 'value' => 'Brak mapowania '.$label, 'mapped' => false];
        }

        $parts = array_filter([
            $mapping->external_category_path ?: $mapping->external_category_name,
            $mapping->external_category_id ? 'ID: '.$mapping->external_category_id : null,
        ]);

        return ['label' => 'Kategoria', 'value' => implode(' · ', $parts), 'mapped' => ! $mapping->is_blocked];
    }

    /** @return array<int, string> */
    private function missingEbayTranslations(Part $part): array
    {
        $translations = (array) (data_get($part->review_metadata, 'marketplace_translations') ?: data_get($part->legacy_payload, 'marketplace_translations') ?: []);

        return collect(['de' => 'tłumaczenie eBay DE', 'fr' => 'tłumaczenie eBay FR'])
            ->filter(fn (string $label, string $locale): bool => blank(data_get($translations, 'ebay_'.$locale.'.title')) || blank(data_get($translations, 'ebay_'.$locale.'.description')))
            ->values()
            ->all();
    }

    /** @param array<int, string> $channels */
    private function categoryMapping(Part $part, array $channels): ?MarketplaceCategoryMapping
    {
        if (! Schema::hasTable('marketplace_category_mappings') || blank($part->category_id ?? null)) {
            return null;
        }

        return MarketplaceCategoryMapping::query()
            ->where('local_category_id', $part->category_id)
            ->whereIn('channel', $channels)
            ->whereNotNull('external_category_id')
            ->orderBy('is_blocked')
            ->first();
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
            'translation_credentials' => 'konfiguracja tłumaczeń', 'description_template' => 'szablon opisu eBay', 'business_policies' => 'polityki płatności/wysyłki/zwrotów eBay', 'marketplace_country' => 'kraj marketplace',
        ];

        return array_map(fn ($field): string => $map[(string) $field] ?? (string) $field, $fields);
    }

    /** @param array<int, mixed> $warnings @return array<int, string> */
    private function polishWarnings(array $warnings): array
    {
        return array_map(fn ($warning): string => (string) $warning, $warnings);
    }
}
