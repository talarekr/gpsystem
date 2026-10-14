<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FR exposure only. Source price/quantity are never copied or changed. */
class JarekFrProduct extends Model
{
    protected $fillable = ['jarek_gearbox_id', 'is_active', 'published_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'published_at' => 'datetime'];
    }

    public function gearbox(): BelongsTo
    {
        return $this->belongsTo(JarekGearbox::class, 'jarek_gearbox_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereHas('gearbox', fn ($q) => $q
            ->where('currency', 'PLN')->where('price', '>', 0)->where('title', '<>', ''));
    }
}
