<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceAccount extends Model
{
    protected $fillable = ['marketplace', 'name', 'code', 'status', 'config', 'last_connected_at'];

    protected function casts(): array
    {
        return ['config' => 'array', 'last_connected_at' => 'datetime'];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }
}
