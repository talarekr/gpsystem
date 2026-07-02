<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JarekGearbox extends Model
{
    protected $fillable = [
        'source_account','allegro_offer_id','allegro_offer_url','title','description','plain_description','price','currency','quantity','allegro_status','main_image_url','images','category_id','category_name','parameters','raw_payload','import_status','imported_at','updated_from_allegro_at','ebay_status','ebay_listing_id','ebay_offer_id','ebay_inventory_sku','ebay_payload_snapshot','ebay_published_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quantity' => 'integer',
            'images' => 'array',
            'parameters' => 'array',
            'raw_payload' => 'array',
            'ebay_payload_snapshot' => 'array',
            'imported_at' => 'datetime',
            'updated_from_allegro_at' => 'datetime',
            'ebay_published_at' => 'datetime',
        ];
    }

    public function ebaySourcePrice(): ?float
    {
        return is_numeric($this->price) ? round((float) $this->price, 2) : null;
    }

    public function ebayPreviewPrice(): ?float
    {
        return is_numeric($this->price) ? round((float) $this->price * 1.25, 2) : null;
    }
}
