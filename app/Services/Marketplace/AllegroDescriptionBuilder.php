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
        $description = $this->cleanText((string) ($part->description ?? ''));

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
        ];
        foreach (self::REQUIRED_VEHICLE_FIELDS as $field => $label) {
            $value = $vehicle ? $this->cleanText((string) ($vehicle->{$field} ?? '')) : $this->cleanText((string) ($vehicleSnapshot[$field] ?? ''));
            $values[$field] = $value;
            if (($vehicle || $vehicleSnapshot !== []) && $value === '') {
                $blockers[] = 'missing_donor_vehicle_field:'.$label;
                $diagnostics['required_donor_vehicle_fields_missing'][] = $label;
            }
        }

        $values['model_variant'] = $vehicle ? $this->cleanText((string) ($vehicle->model_variant ?? '')) : $this->cleanText((string) ($vehicleSnapshot['model_variant'] ?? ''));

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

    private function cleanText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
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
            'main_image_url' => $mainImageUrl,
            'offer_images_contains_main' => $offerImagesContainsMain,
        ];
    }
}
