<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Str;

class AllegroGpSwissDescriptionTemplate
{
    public const SOURCE = 'allegro_gp_swiss_template';
    public const TEMPLATE = 'text_image_50_50';

    /** @param array<string, string> $vehicleValues */
    public function render(string $partDescription, array $vehicleValues, string $mainImageUrl): array
    {
        $content = '<p>Witam oferta dotyczy:</p>'
            .'<p><b>'.$this->e($partDescription).'</b></p>'
            .'<ul>'
            .$this->line('Marka', $vehicleValues['make'] ?? '')
            .$this->line('Model', $this->vehicleModel($vehicleValues))
            .$this->line('Rok', $vehicleValues['production_year'] ?? '')
            .$this->line('Oznaczenie silnika', $vehicleValues['engine_code'] ?? '')
            .$this->line('Moc silnika', $vehicleValues['engine_power_kw'] ?? '')
            .'</ul>'
            .'<p><b>CZĘŚĆ SPRAWNA. STAN WIDOCZNY NA ZDJĘCIACH</b></p>';

        return [
            'sections' => [[
                'items' => [
                    ['type' => 'TEXT', 'content' => $content],
                    ['type' => 'IMAGE', 'url' => $mainImageUrl],
                ],
            ]],
        ];
    }

    /** @param array<string, string> $vehicleValues */
    private function vehicleModel(array $vehicleValues): string
    {
        return trim(collect([$vehicleValues['model'] ?? null, $vehicleValues['model_variant'] ?? null])->filter()->implode(' '));
    }

    private function line(string $label, string $value): string
    {
        $value = trim($value);

        return $value !== '' ? '<li>'.$this->e($label).': <b>'.$this->e($value).'</b></li>' : '';
    }

    private function e(string $value): string
    {
        return e(Str::limit($value, 4000, ''));
    }
}
