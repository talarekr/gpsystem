<?php

namespace App\Services\Marketplace;

use App\Models\Part;

class AllegroDescriptionBuilder
{
    public const REQUIRED_VEHICLE_FIELDS = [
        'make' => 'Marka',
        'model' => 'Model',
    ];

    public const OPTIONAL_VEHICLE_FIELDS = [
        'production_year' => 'Rok',
        'engine_code' => 'Oznaczenie silnika',
        'engine_power_kw' => 'Moc silnika',
    ];

    public function __construct(private readonly MarketplaceImageSelectionService $imageSelectionService, private readonly AllegroGpSwissDescriptionTemplate $template) {}

    /** @return array{description: ?array<string, mixed>, blockers: array<int, string>, diagnostics: array<string, mixed>} */
    public function build(Part $part, array $offerImageUrls): array
    {
        $part->loadMissing(['car', 'images']);
        $blockers = [];
        $description = $this->cleanMultilineText((string) ($part->description ?? ''));

        if ($description === '') {
            $blockers[] = 'missing_part_description';
        }

        $vehicle = $part->car;
        $vehicleSnapshot = is_array($part->vehicle_snapshot ?? null) ? $part->vehicle_snapshot : [];
        if (! $vehicle && $vehicleSnapshot === []) {
            $blockers[] = 'missing_donor_vehicle';
        }
        $values = [];
        $diagnostics = [
            'description_source' => AllegroGpSwissDescriptionTemplate::SOURCE,
            'description_part_description_present' => $description !== '',
            'description_template' => AllegroGpSwissDescriptionTemplate::TEMPLATE,
            'description_builder_class' => self::class,
            'description_publish_blocked_if_template_missing' => true,
        ];
        $values['model_variant'] = $vehicle ? $this->cleanText((string) ($vehicle->model_variant ?? '')) : $this->cleanText((string) ($vehicleSnapshot['model_variant'] ?? ''));

        foreach (self::REQUIRED_VEHICLE_FIELDS as $field => $label) {
            $value = $vehicle ? $this->cleanText((string) ($vehicle->{$field} ?? '')) : $this->cleanText((string) ($vehicleSnapshot[$field] ?? ''));

            if ($field === 'model' && $value === '' && $values['model_variant'] !== '') {
                $value = $values['model_variant'];
                $diagnostics['description_vehicle_model_source'] = 'variant_fallback';
            }

            $values[$field] = $value;
            if (($vehicle || $vehicleSnapshot !== []) && $value === '') {
                $blockers[] = 'missing_donor_vehicle_field:'.$label;
                $diagnostics['required_donor_vehicle_fields_missing'][] = $label;
            }
        }

        $diagnostics['description_vehicle_model_source'] ??= 'model';
        $diagnostics['description_vehicle_variant_hidden'] = true;

        foreach (self::OPTIONAL_VEHICLE_FIELDS as $field => $label) {
            $value = $vehicle ? $this->cleanText((string) ($vehicle->{$field} ?? '')) : $this->cleanText((string) ($vehicleSnapshot[$field] ?? ''));
            $values[$field] = $value;
            if (($vehicle || $vehicleSnapshot !== []) && $value === '') {
                $diagnostics['optional_donor_vehicle_fields_missing'][] = $label;
            }
        }

        $mainImageUrl = $this->mainImageUrl($part);
        if ($mainImageUrl === null) {
            $blockers[] = 'missing_main_image';
        } elseif (! in_array($mainImageUrl, $offerImageUrls, true)) {
            $blockers[] = 'main_image_url_not_in_offer_images';
        }

        if ($blockers !== []) {
            return ['description' => null, 'blockers' => array_values(array_unique($blockers)), 'diagnostics' => $this->diagnostics($diagnostics, $values, null, $mainImageUrl, $mainImageUrl !== null && in_array($mainImageUrl, $offerImageUrls, true))];
        }

        $descriptionPayload = $this->template->render($description, $values, $mainImageUrl);

        return [
            'description' => $descriptionPayload,
            'blockers' => [],
            'diagnostics' => $this->diagnostics($diagnostics, $values, $descriptionPayload, $mainImageUrl, true),
        ];
    }

    private function mainImageUrl(Part $part): ?string
    {
        $selected = $this->imageSelectionService->selectForPart($part, 1)['urls'][0] ?? null;
        return is_string($selected) && $selected !== '' ? $selected : null;
    }

    private function descriptionTextContains(?array $descriptionPayload, string $needle): bool
    {
        $text = '';
        foreach ((array) ($descriptionPayload['sections'] ?? []) as $section) {
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (is_array($item) && strtoupper((string) ($item['type'] ?? '')) === 'TEXT') {
                    $text .= ' '.strip_tags((string) ($item['content'] ?? ''));
                }
            }
        }

        return str_contains($text, $needle);
    }

    private function cleanText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
    }

    private function cleanMultilineText(string $value): string
    {
        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~<\s*br\s*/?\s*>~iu', "\n", $text) ?? $text;
        $text = preg_replace('~<\s*/\s*p\s*>~iu', "\n\n", $text) ?? $text;
        $text = preg_replace('~<\s*p\b[^>]*>~iu', '', $text) ?? $text;
        $text = preg_replace('~<\s*/\s*li\s*>~iu', "\n", $text) ?? $text;
        $text = preg_replace('~<\s*li\b[^>]*>~iu', '- ', $text) ?? $text;
        $text = preg_replace('~<\s*/?\s*(?:ul|ol)\b[^>]*>~iu', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** @param array<string, mixed> $diagnostics @param array<string, string> $values */
    private function diagnostics(array $diagnostics, array $values, ?array $descriptionPayload, ?string $mainImageUrl, bool $offerImagesContainsMain): array
    {
        return $diagnostics + [
            'description_vehicle_fields_present' => collect(array_merge(self::REQUIRED_VEHICLE_FIELDS, self::OPTIONAL_VEHICLE_FIELDS))->keys()->filter(fn (string $field): bool => ($values[$field] ?? '') !== '')->values()->all(),
            'required_donor_vehicle_fields_missing' => $diagnostics['required_donor_vehicle_fields_missing'] ?? [],
            'optional_donor_vehicle_fields_missing' => $diagnostics['optional_donor_vehicle_fields_missing'] ?? [],
            'description_engine_power_present' => ($values['engine_power_kw'] ?? '') !== '',
            'description_sections_count' => is_array($descriptionPayload) ? count($descriptionPayload['sections'] ?? []) : 0,
            'description_contains_gp_swiss_intro' => $this->descriptionTextContains($descriptionPayload, 'Witam oferta dotyczy'),
            'description_contains_gp_swiss_footer' => $this->descriptionTextContains($descriptionPayload, 'CZĘŚĆ SPRAWNA. STAN WIDOCZNY NA ZDJĘCIACH'),
            'description_contains_vehicle_fields' => $this->descriptionTextContains($descriptionPayload, 'Marka:') && $this->descriptionTextContains($descriptionPayload, 'Model:'),
            'main_image_url' => $mainImageUrl,
            'offer_images_contains_main' => $offerImagesContainsMain,
        ];
    }
}
