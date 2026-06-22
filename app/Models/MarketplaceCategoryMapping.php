<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceCategoryMapping extends Model
{
    protected $fillable = [
        'local_category_id', 'channel', 'external_category_id', 'external_category_name', 'external_category_path',
        'local_category_name', 'local_category_path', 'old_category_id', 'source', 'confidence', 'is_blocked',
        'block_reason', 'shipping_group', 'fulfillment_policy_id', 'metadata', 'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_blocked' => 'boolean',
            'imported_at' => 'datetime',
        ];
    }

    public function localCategory(): BelongsTo
    {
        return $this->belongsTo(PartCategory::class, 'local_category_id');
    }
}
