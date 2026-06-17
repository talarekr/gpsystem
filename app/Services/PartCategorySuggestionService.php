<?php

namespace App\Services;

use App\Models\Part;
use Illuminate\Support\Str;

class PartCategorySuggestionService
{
    /**
     * @return array{category_id: int|null, confidence: int|null, reason: string|null, auto_fill: bool}
     */
    public function suggestionForInput(array $data, ?int $ignorePartId = null): array
    {
        $terms = $this->searchTerms($data);

        if ($terms === []) {
            return ['category_id' => null, 'confidence' => null, 'reason' => null, 'auto_fill' => false];
        }

        $matches = Part::query()
            ->select(['id', 'category_id', 'name', 'sku', 'part_number', 'oem_number', 'manufacturer_code'])
            ->whereNotNull('category_id')
            ->when($ignorePartId, fn ($query) => $query->whereKeyNot($ignorePartId))
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $query->orWhere('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('part_number', 'like', $like)
                        ->orWhere('oem_number', 'like', $like)
                        ->orWhere('manufacturer_code', 'like', $like);
                }
            })
            ->latest('id')
            ->limit(25)
            ->get();

        if ($matches->isEmpty()) {
            return ['category_id' => null, 'confidence' => null, 'reason' => 'Brak podobnych części.', 'auto_fill' => false];
        }

        $categoryCounts = $matches->groupBy('category_id')->map->count()->sortDesc();
        $categoryId = (int) $categoryCounts->keys()->first();
        $topCount = (int) $categoryCounts->first();
        $confidence = (int) round(($topCount / $matches->count()) * 100);

        return [
            'category_id' => $categoryId,
            'confidence' => $confidence,
            'reason' => 'Sugestia z podobnych części: '.$topCount.'/'.$matches->count().' w tej kategorii.',
            'auto_fill' => $matches->count() >= 3 && $confidence >= 70,
        ];
    }

    public function suggest(Part $part): Part
    {
        $suggestion = $this->suggestionForInput($part->only(['name', 'sku', 'part_number', 'oem_number', 'manufacturer_code']), $part->exists ? $part->id : null);

        if (! $suggestion['category_id']) {
            $part->category_needs_review = ! (bool) $part->category_id;
            $part->category_suggestion_reason ??= $suggestion['reason'] ?? 'Brak pewnego dopasowania kategorii.';

            return $part;
        }

        $part->suggested_category_id = $suggestion['category_id'];
        $part->category_confidence = $suggestion['confidence'];
        $part->category_suggestion_reason = $suggestion['reason'];
        $part->category_needs_review = ! $suggestion['auto_fill'];

        if (! $part->category_id && $suggestion['auto_fill']) {
            $part->category_id = $suggestion['category_id'];
        }

        return $part;
    }

    /**
     * @return array<int, string>
     */
    private function searchTerms(array $data): array
    {
        return collect(['name', 'sku', 'part_number', 'oem_number', 'manufacturer_code'])
            ->flatMap(fn (string $field): array => preg_split('/\s+/', Str::lower((string) ($data[$field] ?? ''))) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B.,;:/\\|-_()[]{}"))
            ->filter(fn (string $term): bool => Str::length($term) >= 3)
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }
}
