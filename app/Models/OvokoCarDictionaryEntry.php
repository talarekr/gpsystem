<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvokoCarDictionaryEntry extends Model
{
    protected $fillable = ['dictionary', 'ovoko_id', 'name', 'ovoko_brand_id', 'year_from', 'year_to', 'raw_payload', 'synced_at'];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
            'year_from' => 'integer',
            'year_to' => 'integer',
        ];
    }
}
