<?php

namespace App\Services\Marketplace;

use App\Models\Part;
use Illuminate\Support\Str;

class AllegroDescriptionBuilder
{
    public const REQUIRED_VEHICLE_FIELDS = [
        'make' => 'Marka',
        'model' => 'Model',
        'production_year' => 'Rok',
        'engine_code' => 'Oznaczenie silnika',
    ];

    public const OPTIONAL_VEHICLE_FIELDS = [
        'engine_power_kw' => 'Moc silnika',
    ];

    public function __construct(private readonly MarketplaceImageSelectionService $imageSelectionService) {}

    /** @return array{description: ?array<string, mixed>, blockers: array<int, string>, diagnostics: array<string, mixed>} */
    public function build(Part $part, array $offerImageUrls): array
    {
        $part->loadMissing(['car', 'images']);
        $blockers = [];
        $source = $this->resolveLocalDescription($part);
        $description = $source['sanitized'];
        $diagnostics = $this->descriptionSourceDiagnostics($part, $source);

        if ($description === '') {
            $blockers[] = 'missing_part_description';
        }

        $vehicle = $part->car;
        $vehicleSnapshot = is_array($part->vehicle_snapshot ?? null) ? $part->vehicle_snapshot : [];
        if (! $vehicle && $vehicleSnapshot === []) {
            $blockers[] = 'missing_donor_vehicle';
        }
        $values = [];
        foreach (self::REQUIRED_VEHICLE_FIELDS as $field => $label) {
            $value = $vehicle ? $this->cleanText((string) ($vehicle->{$field} ?? '')) : $this->cleanText((string) ($vehicleSnapshot[$field] ?? ''));
            $values[$field] = $value;
            if (($vehicle || $vehicleSnapshot !== []) && $value === '') {
                $blockers[] = 'missing_donor_vehicle_field:'.$label;
            }
        }

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
            return ['description' => null, 'blockers' => array_values(array_unique($blockers)), 'diagnostics' => $diagnostics + $this->allegroSectionDiagnostics($source, []) + ['main_image_url' => $mainImageUrl, 'offer_images_contains_main' => $mainImageUrl !== null && in_array($mainImageUrl, $offerImageUrls, true)]];
        }

        $content = '<p>Witam oferta dotyczy:</p>'
            .'<p><b>'.$this->e($description).'</b></p>'
            .'<ul>'
            .'<li>Marka: <b>'.$this->e($values['make']).'</b></li>'
            .'<li>Model: <b>'.$this->e($values['model']).'</b></li>'
            .'<li>Rok: <b>'.$this->e($values['production_year']).'</b></li>'
            .'<li>Oznaczenie silnika: <b>'.$this->e($values['engine_code']).'</b></li>'
            .($values['engine_power_kw'] !== '' ? '<li>Moc silnika: <b>'.$this->e($values['engine_power_kw']).'</b></li>' : '')
            .'</ul>'
            .'<p><b>CZĘŚĆ SPRAWNA. STAN WIDOCZNY NA ZDJĘCIACH</b></p>';

        $sections = [['items' => [['type' => 'TEXT', 'content' => $content], ['type' => 'IMAGE', 'url' => $mainImageUrl]]]];

        return [
            'description' => ['sections' => $sections],
            'blockers' => [],
            'diagnostics' => $diagnostics + $this->allegroSectionDiagnostics($source, $sections) + ['main_image_url' => $mainImageUrl, 'offer_images_contains_main' => true],
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

    /** @return array{source: ?string, raw: string, sanitized: string} */
    private function resolveLocalDescription(Part $part): array
    {
        foreach ([
            'part.description' => $part->description ?? null,
            'part.short_description' => $part->short_description ?? null,
            'part.legacy_payload.woo_product.description' => data_get($part->legacy_payload, 'woo_product.description'),
            'part.legacy_payload.description' => data_get($part->legacy_payload, 'description'),
            'part.legacy_payload.meta.description' => data_get($part->legacy_payload, 'meta.description'),
        ] as $candidateSource => $value) {
            $raw = is_string($value) ? $value : '';
            $sanitized = $this->cleanText($raw);
            if ($sanitized !== '') {
                return ['source' => $candidateSource, 'raw' => $raw, 'sanitized' => $sanitized];
            }
        }

        return ['source' => null, 'raw' => '', 'sanitized' => ''];
    }

    private function descriptionSourceDiagnostics(Part $part, array $source): array
    {
        $ebaySource = $this->resolveLocalDescription($part);
        $ovokoSource = $this->resolveLocalDescription($part);

        return [
            'local_description_present' => $source['sanitized'] !== '',
            'local_description_source' => $source['source'],
            'ebay_description_present' => $ebaySource['sanitized'] !== '',
            'ebay_description_source' => $ebaySource['source'],
            'ovoko_description_present' => $ovokoSource['sanitized'] !== '',
            'ovoko_description_source' => $ovokoSource['source'],
            'allegro_description_source' => $source['source'],
            'allegro_description_raw_length' => mb_strlen($source['raw']),
            'allegro_description_sanitized_length' => mb_strlen($source['sanitized']),
            'description_source_mismatch' => count(array_unique(array_filter([$source['source'], $ebaySource['source'], $ovokoSource['source']]))) > 1,
        ];
    }

    private function allegroSectionDiagnostics(array $source, array $sections): array
    {
        $hasText = false;
        foreach ($sections as $section) {
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (($item['type'] ?? null) === 'TEXT' && $this->cleanText((string) ($item['content'] ?? '')) !== '') {
                    $hasText = true;
                }
            }
        }

        return [
            'allegro_description_raw_length' => mb_strlen($source['raw']),
            'allegro_description_sanitized_length' => mb_strlen($source['sanitized']),
            'allegro_description_sections_count' => count($sections),
            'allegro_description_has_non_empty_content' => $hasText,
        ];
    }

    private function e(string $value): string
    {
        return e(Str::limit($value, 4000, ''));
    }
}
