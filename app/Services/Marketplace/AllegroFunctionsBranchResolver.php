<?php

namespace App\Services\Marketplace;

use App\Models\PartCategory;
use Illuminate\Support\Str;

class AllegroFunctionsBranchResolver
{
    public const BUSINESS_PATH = ['Wyposażenie elektryczne', 'Przełączniki i przyciski'];

    public function matches(?PartCategory $category): bool
    {
        if (! $category) {
            return false;
        }

        $path = $this->path($category);
        $needle = array_map(fn (string $value): string => $this->normalize($value), self::BUSINESS_PATH);
        $normalized = array_map(fn (string $value): string => $this->normalize($value), $path);

        if (count($normalized) < count($needle)) {
            return false;
        }

        for ($offset = 0; $offset <= count($normalized) - count($needle); $offset++) {
            if (array_slice($normalized, $offset, count($needle)) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function path(?PartCategory $category): array
    {
        if (! $category) {
            return [];
        }

        $nodes = [];
        $current = $category;
        $guard = 0;
        while ($current && $guard++ < 50) {
            $nodes[] = (string) $current->name;
            $current = $current->relationLoaded('parent') ? $current->parent : $current->parent()->first();
        }

        return array_values(array_reverse($nodes));
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }
}
