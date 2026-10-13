<?php

namespace App\Services\Storefront;

use App\Models\StorefrontTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class FrenchCatalogSearch
{
    public function apply(Builder $query, string $phrase): Builder
    {
        $phrase = trim($phrase);
        $number = preg_replace('/[^A-Za-z0-9]/', '', $phrase) ?? '';
        if ($phrase === '' || (! preg_match('/\s/u', $phrase) && strlen($number) >= 3 && preg_match('/\d/', $number))
            || ! Schema::hasTable('part_translations') || ! Schema::hasTable('category_translations')) {
            return $query->searchStorefront($phrase);
        }
        foreach (array_unique(preg_split('/\s+/u', $phrase) ?: []) as $token) {
            $like = '%'.mb_strtolower($token).'%';
            $query->where(function (Builder $names) use ($like): void {
                $this->nameMatch($names, 'parts', $like);
                $names->orWhereHas('category', function (Builder $category) use ($like): void {
                    $category->visibleForPublic()->where(function (Builder $names) use ($like): void {
                        $this->nameMatch($names, 'part_categories', $like);
                    });
                });
            });
        }

        return $query;
    }

    private function nameMatch(Builder $query, string $table, string $like): void
    {
        $ready = fn (Builder $translation) => $translation->where('locale', 'fr')
            ->whereIn('status', StorefrontTranslation::PUBLIC_STATUSES)->whereRaw("TRIM(COALESCE(name, '')) <> ''");
        $query->whereHas('translations', function (Builder $translation) use ($ready, $like): void {
            $ready($translation)->whereRaw('LOWER(name) LIKE ?', [$like]);
        })->orWhere(function (Builder $source) use ($ready, $table, $like): void {
            $source->whereDoesntHave('translations', $ready)->whereRaw("LOWER(COALESCE($table.name, '')) LIKE ?", [$like]);
        });
    }

    public function categoryFilter(Builder $query, string $value): void
    {
        $query->where(function (Builder $category) use ($value): void {
            $category->where('slug', $value)->orWhere(function (Builder $names) use ($value): void {
                if (Schema::hasTable('category_translations')) {
                    $this->nameMatch($names, 'part_categories', '%'.mb_strtolower($value).'%');
                } else {
                    $names->where('name', 'like', '%'.$value.'%');
                }
            });
        });
    }
}
