<?php

namespace App\Services\Images;

/** Shared ranking for ordinary images and supplier view adapters. */
class ListingImageSelector
{
    public function select($images): ?object
    {
        if ($images->isEmpty()) {
            return null;
        }
        $scored = $images->map(fn ($image): array => ['image' => $image, 'score' => $image->listingScore()])
            ->filter(fn (array $item): bool => $item['score'] !== null);
        if ($scored->isNotEmpty()) {
            return $scored->sort(function (array $a, array $b): int {
                return [$b['score'], (int) $b['image']->is_primary, $a['image']->sort_order, $a['image']->id]
                    <=> [$a['score'], (int) $a['image']->is_primary, $b['image']->sort_order, $b['image']->id];
            })->first()['image'];
        }

        return $images->sortBy([['is_primary', 'desc'], ['sort_order', 'asc'], ['id', 'asc']])->first();
    }
}
