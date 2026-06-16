<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'part_id',
        'part_snapshot',
        'amount',
        'currency',
        'payment_method',
        'quantity',
        'sold_at',
        'created_by',
        'notes',
        'marketplace_sync_status',
        'marketplace_sync_payload',
    ];

    protected function casts(): array
    {
        return [
            'part_snapshot' => 'array',
            'amount' => 'decimal:2',
            'quantity' => 'integer',
            'sold_at' => 'datetime',
            'marketplace_sync_payload' => 'array',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
