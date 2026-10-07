<?php

namespace App\Services\Storefront;

use App\Models\Part;
use App\Models\PartCategory;
use App\Models\StorefrontTranslation;
use Illuminate\Support\Facades\Schema;

class FrTranslationsPreview
{
    public function report(int $exampleLimit = 5): array
    {
        $report = ['locale' => 'fr', 'read_only' => true, 'schema_missing' => [],
            'scope' => 'Visible storefront products; public Woo/imported or local categories, including empty categories.',
            'character_estimate' => 'Unicode characters in all source fields of records needing translation; no API calls.'];

        foreach (['products' => [Part::class, 'part_translations'], 'categories' => [PartCategory::class, 'category_translations']] as $kind => [$model, $table]) {
            $hasTable = Schema::hasTable($table);
            if (! $hasTable) {
                $report['schema_missing'][] = $table;
            }
            $summary = ['total' => 0, 'current' => 0, 'needing_translation' => 0, 'estimated_characters' => 0,
                'reasons' => ['missing' => 0, 'needs_update' => 0, 'failed' => 0, 'not_ready' => 0], 'examples' => []];
            $query = $model::query();
            if ($kind === 'products') {
                $query->storefrontVisible();
            } else {
                $query->visibleForPublic()->where(fn ($q) => $q->whereNull('source_system')->orWhere('source_system', 'woo'));
            }
            if ($hasTable) {
                $query->with('translations');
            }
            $query->chunkById(200, function ($records) use (&$summary, $hasTable, $exampleLimit): void {
                foreach ($records as $record) {
                    $translation = $hasTable ? $record->translationForLocale('fr') : null;
                    $hash = $record->translationSourceHash();
                    $reason = $this->reason($record->translationSource(), $hash, $translation);
                    $summary['total']++;
                    if ($reason === null) {
                        $summary['current']++;

                        continue;
                    }
                    $summary['needing_translation']++;
                    $summary['reasons'][$reason]++;
                    $summary['estimated_characters'] += array_sum(array_map('mb_strlen', $record->translationSource()));
                    if (count($summary['examples']) < $exampleLimit) {
                        $summary['examples'][] = [
                            $record instanceof Part ? 'part_id' : 'category_id' => $record->id,
                            'sku' => $record instanceof Part ? $record->sku : null,
                            'name' => $record->name, 'translation_exists' => $translation !== null,
                            'status' => $translation?->status, 'source_hash_current' => $hash,
                            'source_hash_existing' => $translation?->source_hash, 'reason' => $reason,
                        ];
                    }
                }
            });
            $report[$kind] = $summary;
        }

        return $report;
    }

    private function reason(array $source, string $hash, ?StorefrontTranslation $translation): ?string
    {
        if (! $translation || $translation->status === 'missing') {
            return 'missing';
        }
        if ($translation->status === 'failed') {
            return 'failed';
        }
        if ($translation->status === 'needs_update') {
            return 'needs_update';
        }
        if (! $translation->isReady()) {
            return 'not_ready';
        }
        if (! $translation->matchesSourceHash($hash)) {
            return 'needs_update';
        }
        foreach ($source as $field => $value) {
            if (trim($value) !== '' && trim((string) $translation->getAttribute($field)) === '') {
                return 'not_ready';
            }
        }

        return null;
    }
}
