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
            .$this->partDescriptionParagraphs($partDescription)
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
        return trim((string) (($vehicleValues['model'] ?? '') ?: ($vehicleValues['model_variant'] ?? '')));
    }

    private function partDescriptionParagraphs(string $partDescription): string
    {
        $paragraphs = preg_split("/\n{2,}/u", str_replace(["\r\n", "\r"], "\n", trim($partDescription))) ?: [];
        $content = '';

        foreach ($paragraphs as $paragraph) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $paragraph)), fn (string $line): bool => $line !== ''));

            if ($lines === []) {
                continue;
            }

            $content .= '<p><b>'.implode('<br />', array_map(fn (string $line): string => $this->e($line), $lines)).'</b></p>';
        }

        return $content;
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
