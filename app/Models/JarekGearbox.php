<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Car;
use App\Models\MarketplaceListing;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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




    public function category(): BelongsTo
    {
        return $this->belongsTo(PartCategory::class, 'category_id');
    }

    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id');
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class, 'car_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PartImage::class, 'part_id')->whereRaw('1 = 0');
    }

    public function marketplaceListings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'part_id')->whereRaw('1 = 0');
    }

    public function getNameAttribute(): ?string
    {
        return $this->title;
    }

    public function getPartNumberAttribute(): ?string
    {
        return $this->allegro_offer_id ?: $this->ebay_inventory_sku;
    }

    public function getStatusAttribute(): ?string
    {
        return $this->allegro_status ?: $this->import_status ?: $this->ebay_status;
    }

    public function getAllegroPriceAttribute(): mixed
    {
        return $this->price;
    }

    public function getEbayPriceAttribute(): mixed
    {
        return $this->price;
    }

    public function getInternalNoteAttribute(): ?string
    {
        return $this->plain_description;
    }

    public function getStorageLocationAttribute(): ?object
    {
        return $this->source_account ? (object) ['name' => $this->source_account] : null;
    }

    public function getReviewReasonAttribute(): ?string
    {
        return $this->import_status;
    }

    public function getReviewDetectedAtAttribute(): mixed
    {
        return $this->updated_from_allegro_at;
    }

    public function getReviewSourceAttribute(): ?string
    {
        return 'jarek_gearboxes';
    }

    public function adminTableImageUrl(): ?string
    {
        return $this->main_image_url ?: (is_array($this->images) ? ($this->images[0] ?? null) : null);
    }

    public static function statusOptions(): array
    {
        return static::query()->whereNotNull('allegro_status')->distinct()->orderBy('allegro_status')->pluck('allegro_status', 'allegro_status')->filter()->all();
    }

    public static function statusTextClass(?string $status): string
    {
        return match ($status) {
            'ACTIVE', 'active', 'published', 'ready' => 'gps-part-status-text--ready',
            'ENDED', 'ended', 'sold' => 'gps-part-status-text--sold',
            default => 'gps-part-status-text--default',
        };
    }
}
