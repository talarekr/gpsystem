<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    public const STATUSES = ['new', 'processing', 'on_hold', 'ready_to_ship', 'ready_for_pickup', 'shipped', 'picked_up', 'completed', 'cancelled'];

    protected $fillable = [
        'order_number', 'customer_id', 'marketplace', 'marketplace_order_id', 'marketplace_status', 'ordered_at', 'status', 'status_changed_at', 'currency', 'subtotal', 'shipping_total', 'payment_status', 'delivery_method', 'total',
        'customer_name', 'email', 'phone', 'company_name', 'nip', 'address_line1', 'postal_code',
        'city', 'country', 'invoice_data', 'raw_payload', 'imported_at', 'test_import', 'source_batch', 'notes', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'total' => 'decimal:2',
            'ordered_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'invoice_data' => 'array',
            'raw_payload' => 'array',
            'imported_at' => 'datetime',
            'test_import' => 'boolean',
            'meta' => 'array',
        ];
    }

    // Legacy PayU checkout rows retain their stored values until an approved backfill.
    public function isPolishStorefront(): bool
    {
        return data_get($this->meta, 'storefront_code') === 'gpswiss_pl'
            || ($this->marketplace === 'payu'
                && data_get($this->meta, 'source') === 'storefront'
                && parse_url((string) data_get($this->meta, 'payu.request.continueUrl'), PHP_URL_HOST) === 'gpswiss.pl');
    }

    public function salesChannel(): string
    {
        return $this->isPolishStorefront() ? 'sklep' : ($this->marketplace ?: 'sklep');
    }

    public function paymentProvider(): ?string
    {
        return data_get($this->meta, 'payment_provider')
            ?: data_get($this->meta, 'payu.provider');
    }

    public function scopeStorefront(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where(function ($query): void {
            $query->whereNull('marketplace')->orWhereIn('marketplace', ['', 'sklep', 'storefront'])
                ->orWhere(function ($query): void {
                    $query->where('marketplace', 'payu')->where('meta->source', 'storefront')
                        ->where(function ($query): void {
                            $query->where('meta->storefront_code', 'gpswiss_pl')
                                ->orWhere('meta->payu->request->continueUrl', 'like', 'https://gpswiss.pl/%')
                                ->orWhere('meta->payu->request->continueUrl', 'like', 'http://gpswiss.pl/%');
                        });
                });
        });
    }

    public static function statusOptions(): array
    {
        return ['new' => 'Nowe', 'processing' => 'W realizacji', 'on_hold' => 'Wstrzymane', 'ready_to_ship' => 'Do wysłania', 'ready_for_pickup' => 'Do odbioru', 'shipped' => 'Wysłane', 'picked_up' => 'Odebrane', 'completed' => 'Zrealizowane', 'cancelled' => 'Anulowane'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
