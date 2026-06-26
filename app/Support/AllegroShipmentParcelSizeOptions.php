<?php

namespace App\Support;

use Illuminate\Support\Str;

class AllegroShipmentParcelSizeOptions
{
    private const DIMENSIONS = [
        'small' => ['length' => 64, 'width' => 38, 'height' => 8],
        'medium' => ['length' => 64, 'width' => 38, 'height' => 19],
        'large' => ['length' => 64, 'width' => 38, 'height' => 41],
    ];

    public function resolve(?string $methodName = null, ?string $methodId = null, ?string $carrierCode = null): array
    {
        $haystack = Str::lower(implode(' ', array_filter([(string) $methodName, (string) $methodId, (string) $carrierCode])));

        if (Str::contains($haystack, ['inpost', 'paczkomat', 'paczkomaty'])) {
            return [
                'mode' => 'size_code',
                'family' => 'inpost',
                'options' => $this->options(['A', 'B', 'C']),
                'weight_limit_kg' => 25,
            ];
        }

        if (Str::contains($haystack, ['orlen', 'allegro one', 'allegro automat', 'allegro one box'])) {
            return [
                'mode' => 'size_code',
                'family' => 'orlen_allegro_one',
                'options' => $this->options(['S', 'M', 'L']),
                'weight_limit_kg' => null,
            ];
        }

        return ['mode' => 'manual', 'family' => 'manual', 'options' => [], 'weight_limit_kg' => null];
    }

    public function dimensionsForCode(?string $code): ?array
    {
        return match (Str::upper((string) $code)) {
            'A', 'S' => self::DIMENSIONS['small'],
            'B', 'M' => self::DIMENSIONS['medium'],
            'C', 'L' => self::DIMENSIONS['large'],
            default => null,
        };
    }

    private function options(array $codes): array
    {
        $keys = ['small', 'medium', 'large'];

        return array_map(fn (string $code, string $key): array => [
            'code' => $code,
            'label' => 'Gabaryt '.$code,
        ] + self::DIMENSIONS[$key], $codes, $keys);
    }
}
