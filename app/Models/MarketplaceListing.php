<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceListing extends Model
{
    protected $fillable = ['marketplace','marketplace_account_id','part_id','external_offer_id','external_listing_id','external_inventory_id','sku','title','price','quantity','currency','status','url','raw_payload','sync_status','match_status','match_confidence','match_reason','last_synced_at','last_error'];

    protected function casts(): array
    {
        return ['raw_payload' => 'array', 'price' => 'decimal:2', 'quantity' => 'integer', 'last_synced_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(MarketplaceSyncLog::class);
    }
}
