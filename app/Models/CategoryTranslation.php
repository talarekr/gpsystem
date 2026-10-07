<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryTranslation extends StorefrontTranslation
{
    protected $fillable = [
        'category_id', 'locale', 'name', 'description', 'provider', 'source_hash',
        'status', 'translated_at', 'reviewed_at', 'error_code', 'error_message', 'attempts',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PartCategory::class, 'category_id');
    }
}
