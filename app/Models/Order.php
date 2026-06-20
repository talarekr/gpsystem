<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    public const STATUSES = ['new', 'processing', 'completed', 'cancelled'];

    protected $fillable = [
        'order_number', 'customer_id', 'status', 'currency', 'subtotal', 'shipping_total', 'total',
        'customer_name', 'email', 'phone', 'company_name', 'nip', 'address_line1', 'postal_code',
        'city', 'country', 'notes', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'total' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public static function statusOptions(): array
    {
        return ['new' => 'Nowe', 'processing' => 'W realizacji', 'completed' => 'Zakończone', 'cancelled' => 'Anulowane'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
