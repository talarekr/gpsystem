<?php

namespace App\Services;

use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Support\Str;

class PartCategorySuggestionService
{
    /** @var array<string, string> */
    private array $internalRules = [
        'lamp' => 'Oświetlenie',
        'reflektor' => 'Oświetlenie',
        'zderzak' => 'Nadwozie',
        'drzwi' => 'Nadwozie',
        'alternator' => 'Elektryka',
        'rozrusznik' => 'Elektryka',
        'pompa' => 'Silnik',
        'turbo' => 'Silnik',
    ];

    public function suggest(Part $part): Part
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $part->name,
            $part->part_number,
            $part->oem_number,
            $part->manufacturer_code,
        ])));

        foreach ($this->internalRules as $needle => $categoryName) {
            if (! Str::contains($haystack, $needle)) {
                continue;
            }

            $category = PartCategory::query()->firstOrCreate(
                ['name' => $categoryName],
                ['slug' => Str::slug($categoryName)]
            );

            if (! $part->category_id) {
                $part->category_id = $category->id;
            }

            $part->suggested_category_id = $category->id;
            $part->category_confidence = 90;
            $part->category_suggestion_reason = 'Dopasowanie wewnętrznej reguły dla: '.$needle;
            $part->category_needs_review = false;

            return $part;
        }

        $part->category_needs_review = ! (bool) $part->category_id;
        $part->category_confidence ??= null;
        $part->category_suggestion_reason ??= 'Brak pewnego dopasowania w wewnętrznych regułach OEM/numeru części.';

        return $part;
    }
}
