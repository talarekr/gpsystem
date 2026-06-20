<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceSyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['marketplace','marketplace_listing_id','part_id','action','status','message','payload','created_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function marketplaceListing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
