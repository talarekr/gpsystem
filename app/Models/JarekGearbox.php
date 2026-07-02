<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JarekGearbox extends Model
{
    protected $fillable = [
        'source_account', 'allegro_account', 'allegro_offer_id', 'allegro_offer_url', 'title',
        'description', 'plain_description', 'price', 'currency', 'quantity', 'allegro_status',
        'main_image_url', 'images', 'category_id', 'category_name', 'category_path', 'category_payload', 'parameters', 'raw_payload',
        'import_status', 'imported_at', 'updated_from_allegro_at', 'ebay_status', 'ebay_listing_id',
        'ebay_offer_id', 'ebay_inventory_sku', 'ebay_payload_snapshot', 'ebay_published_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'images' => 'array',
        'category_path' => 'array',
        'category_payload' => 'array',
        'parameters' => 'array',
        'raw_payload' => 'array',
        'ebay_payload_snapshot' => 'array',
        'imported_at' => 'datetime',
        'updated_from_allegro_at' => 'datetime',
        'ebay_published_at' => 'datetime',
    ];
}
