<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartImage extends Model
{
    protected $fillable = ['source_system', 'external_id', 'part_id', 'path', 'alt_text', 'sort_order', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (PartImage $image): void {
            if (! $image->is_primary && $image->part_id && ! self::query()->where('part_id', $image->part_id)->exists()) {
                $image->is_primary = true;
            }
        });
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
