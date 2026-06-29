<?php

namespace App\Services\Marketplace;

use App\Models\Part;
use Illuminate\Support\Str;

class EbaySkuResolver
{
    public function resolve(Part $part): string
    {
        $identifier = $this->stablePartIdentifier($part);
        $partNumber = $this->sanitize($this->firstFilled($part->part_number, $part->manufacturer_code, $part->sku));

        return $partNumber !== '' ? "GPS-{$identifier}-{$partNumber}" : "GPS-{$identifier}";
    }

    private function stablePartIdentifier(Part $part): string
    {
        if (filled($part->id)) return (string) $part->id;

        return $this->sanitize($this->firstFilled($part->visible_code ?? null, $part->internal_code ?? null, $part->sku, $part->part_number)) ?: 'part';
    }

    private function firstFilled(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (filled($value)) return (string) $value;
        }

        return '';
    }

    private function sanitize(string $value): string
    {
        $value = Str::upper(trim($value));
        $value = (string) preg_replace('/[^A-Z0-9._-]+/', '-', $value);
        $value = trim($value, '-._');

        return $value;
    }
}
