<?php

namespace App\Models;

use App\Services\Storefront\FrenchCategoryUrls;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class CategoryTranslation extends StorefrontTranslation
{
    protected $fillable = [
        'category_id', 'locale', 'name', 'description', 'provider', 'source_hash',
        'status', 'translated_at', 'reviewed_at', 'error_code', 'error_message', 'attempts',
    ];

    protected function casts(): array
    {
        return parent::casts() + ['slug_history' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $translation): void {
            if ($translation->locale !== 'fr' || ! $translation->isReady() || trim((string) $translation->name) === ''
                || ! Schema::hasColumns('category_translations', ['slug', 'slug_name_hash', 'slug_history'])) {
                return;
            }
            $urls = FrenchCategoryUrls::class;
            $hash = $urls::nameHash($translation->name);
            if ($translation->slug_name_hash === $hash) {
                return;
            }
            $slug = $urls::generatedSlug($translation->name, $translation->category_id);
            $history = (array) $translation->slug_history;
            if (filled($translation->slug) && $translation->slug !== $slug) {
                $history[] = $translation->slug;
            }
            $translation->slug = $slug;
            $translation->slug_name_hash = $hash;
            $translation->slug_history = array_values(array_unique($history));
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PartCategory::class, 'category_id');
    }
}
