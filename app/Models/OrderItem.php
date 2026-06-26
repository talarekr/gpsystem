<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'marketplace', 'marketplace_order_id', 'marketplace_item_id', 'offer_id', 'part_id', 'product_name', 'part_number', 'sku', 'external_product_id', 'unit_price', 'quantity', 'line_total', 'currency', 'meta', 'raw_payload'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'quantity' => 'integer', 'meta' => 'array', 'raw_payload' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function marketplaceListing(): HasOne
    {
        return $this->hasOne(MarketplaceListing::class, 'external_offer_id', 'offer_id');
    }
}
