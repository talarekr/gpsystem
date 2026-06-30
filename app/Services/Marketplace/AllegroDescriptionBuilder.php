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
        $description = $this->cleanText((string) ($part->description ?? ''));

        if ($description === '') {
            $blockers[] = 'missing_part_description';
        }

        if (! $part->car) {
            $blockers[] = 'missing_donor_vehicle';
        }

        $vehicle = $part->car;
        $values = [];
        $diagnostics = [];
        foreach (self::REQUIRED_VEHICLE_FIELDS as $field => $label) {
            $value = $vehicle ? $this->cleanText((string) ($vehicle->{$field} ?? '')) : '';
            $values[$field] = $value;
            if ($vehicle && $value === '') {
                $blockers[] = 'missing_donor_vehicle_field:'.$label;
            }
        }

        foreach (self::OPTIONAL_VEHICLE_FIELDS as $field => $label) {
            $value = $vehicle ? $this->cleanText((string) ($vehicle->{$field} ?? '')) : '';
            $values[$field] = $value;
            if ($vehicle && $value === '') {
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
            return ['description' => null, 'blockers' => array_values(array_unique($blockers)), 'diagnostics' => $diagnostics + ['main_image_url' => $mainImageUrl, 'offer_images_contains_main' => $mainImageUrl !== null && in_array($mainImageUrl, $offerImageUrls, true)]];
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

        return [
            'description' => ['sections' => [['items' => [['type' => 'TEXT', 'content' => $content], ['type' => 'IMAGE', 'url' => $mainImageUrl]]]]],
            'blockers' => [],
            'diagnostics' => $diagnostics + ['main_image_url' => $mainImageUrl, 'offer_images_contains_main' => true],
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

    private function e(string $value): string
    {
        return e(Str::limit($value, 4000, ''));
    }
}
