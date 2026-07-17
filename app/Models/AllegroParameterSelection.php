<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllegroParameterSelection extends Model
{
    protected $fillable = [
        'part_id', 'allegro_category_id', 'parameter_id', 'parameter_name', 'value_id', 'value_label',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
