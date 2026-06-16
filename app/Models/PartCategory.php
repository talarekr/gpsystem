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
    ];

    protected function casts(): array
    {
        return ['legacy_payload' => 'array'];
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
}
