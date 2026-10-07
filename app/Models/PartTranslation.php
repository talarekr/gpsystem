<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartTranslation extends StorefrontTranslation
{
    protected $fillable = [
        'part_id', 'locale', 'name', 'short_description', 'description', 'condition_notes',
        'provider', 'source_hash', 'status', 'translated_at', 'reviewed_at',
        'error_code', 'error_message', 'attempts',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
