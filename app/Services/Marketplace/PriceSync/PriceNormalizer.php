<?php

namespace App\Services\Marketplace\PriceSync;

class PriceNormalizer
{
    public function normalize(mixed $value): ?string
    {
        if ($value === null) return null;
        $raw = trim(str_replace(',', '.', (string) $value));
        if ($raw === '' || ! is_numeric($raw)) return null;
        return number_format(round((float) $raw, 2), 2, '.', '');
    }

    public function positive(?string $value): bool
    {
        return $value !== null && (float) $value > 0;
    }
}
