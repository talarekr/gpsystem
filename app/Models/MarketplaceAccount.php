<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceAccount extends Model
{
    protected $fillable = [
        'marketplace',
        'name',
        'code',
        'status',
        'api_enabled',
        'api_base_url',
        'api_mode',
        'api_credentials',
        'api_settings',
        'last_connection_check_at',
        'last_connection_status',
        'last_connection_message',
        'config',
        'last_connected_at',
    ];

    protected function casts(): array
    {
        return [
            'api_enabled' => 'boolean',
            'api_credentials' => 'encrypted:array',
            'api_settings' => 'array',
            'last_connection_check_at' => 'datetime',
            'config' => 'array',
            'last_connected_at' => 'datetime',
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }
}
