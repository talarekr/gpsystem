<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JarekGearboxTranslation extends StorefrontTranslation
{
    protected $fillable = [
        'jarek_gearbox_id', 'locale', 'title', 'description', 'provider', 'source_hash', 'status',
        'attempts', 'error_code', 'error_message', 'translated_at', 'reviewed_at',
    ];

    public function gearbox(): BelongsTo
    {
        return $this->belongsTo(JarekGearbox::class, 'jarek_gearbox_id');
    }
}
