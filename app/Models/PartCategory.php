<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartCategory extends Model
{
    protected $fillable = [
        'parent_id', 'source_system', 'external_id', 'name', 'slug', 'sort_order', 'category_path',
        'full_slug_path', 'woo_product_count', 'description', 'thumbnail_url', 'legacy_payload',
        'active', 'is_active', 'visible', 'is_visible', 'show_in_menu', 'status',
    ];

    protected function casts(): array
    {
        return ['legacy_payload' => 'array', 'is_visible' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    public function parts(): HasMany
    {
        return $this->hasMany(Part::class, 'category_id');
    }

    public function marketplaceMappings(): HasMany
    {
        return $this->hasMany(MarketplaceCategoryMapping::class, 'local_category_id');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeWoo(Builder $query): Builder
    {
        return $query->where('source_system', 'woo');
    }

    public function scopeVisibleForPublic(Builder $query): Builder
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn($this->getTable(), 'is_visible')) {
            $query->where('is_visible', true);
        }

        $query->where(function (Builder $query): void {
            $query->whereNull('name')->orWhereRaw('LOWER(TRIM(name)) <> ?', ['bez kategorii']);
        });

        return $query;
    }

    public function publicDisplayName(): string
    {
        $name = trim((string) $this->name);
        $categoryPath = trim((string) ($this->category_path ?? ''));

        if ($name === '') {
            return $name;
        }

        foreach ([' — ', ' – ', ' - '] as $separator) {
            if (! str_contains($name, $separator)) {
                continue;
            }

            [$shortName, $suffix] = array_map('trim', explode($separator, $name, 2));

            if ($shortName === '') {
                continue;
            }

            if ($categoryPath === '' || $this->normalizeCategoryDisplayText($suffix) === $this->normalizeCategoryDisplayText($categoryPath)) {
                return $shortName;
            }
        }

        return $name;
    }

    public function getPublicNameAttribute(): string
    {
        return $this->publicDisplayName();
    }

    private function normalizeCategoryDisplayText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return str_replace([' / ', '/', ' > '], '>', $value);
    }

    public function isSystemUncategorized(): bool
    {
        return mb_strtolower(trim((string) $this->name)) === 'bez kategorii';
    }
}
