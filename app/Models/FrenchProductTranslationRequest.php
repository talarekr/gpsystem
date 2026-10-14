<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FrenchProductTranslationRequest extends Model
{
    protected $table = 'storefront_fr_product_translation_requests';

    protected $fillable = ['part_id', 'source_hash', 'status', 'error_code', 'requested_at', 'finished_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
