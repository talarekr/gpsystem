<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceCategory extends Model
{
    protected $fillable = [
        'channel', 'external_category_id', 'parent_external_category_id', 'level', 'name', 'full_path', 'raw_payload', 'active', 'imported_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'active' => 'boolean',
        'imported_at' => 'datetime',
    ];
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_external_category_id', 'external_category_id');
    }
}
