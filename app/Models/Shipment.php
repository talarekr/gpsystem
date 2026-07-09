<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    use HasFactory;

    public const CARRIERS = ['dhl' => 'DHL', 'dpd' => 'DPD'];
    public const STATUSES = ['draft', 'previewed', 'created', 'label_created', 'label_missing', 'failed'];

    protected $fillable = [
        'order_id', 'carrier', 'service_code', 'shipment_status', 'tracking_number', 'carrier_shipment_id', 'label_path', 'label_format',
        'sender_snapshot', 'receiver_snapshot', 'parcel_snapshot', 'request_payload', 'response_payload', 'test_mode',
    ];

    protected function casts(): array
    {
        return [
            'sender_snapshot' => 'array', 'receiver_snapshot' => 'array', 'parcel_snapshot' => 'array', 'request_payload' => 'array', 'response_payload' => 'array', 'test_mode' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
