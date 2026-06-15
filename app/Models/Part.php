<?php

namespace App\Models;

use App\Services\PartCategorySuggestionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Part extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku','name','slug','part_number','oem_number','manufacturer_code','short_description','description','condition_notes',
        'category_id','suggested_category_id','category_confidence','category_suggestion_reason','category_needs_review',
        'car_id','vehicle_snapshot','storage_location_id','price','currency','allegro_price','ebay_price','quantity','status',
        'is_visible_storefront','created_by',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_snapshot' => 'array',
            'category_confidence' => 'decimal:2',
            'category_needs_review' => 'boolean',
            'price' => 'decimal:2',
            'allegro_price' => 'decimal:2',
            'ebay_price' => 'decimal:2',
            'quantity' => 'integer',
            'is_visible_storefront' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Part $part): void {
            $part->created_by ??= Auth::id();
            $part->status ??= 'draft';
            $part->currency ??= 'PLN';
            $part->quantity ??= 1;
            $part->is_visible_storefront ??= false;
            $part->fillVehicleSnapshot();
            app(PartCategorySuggestionService::class)->suggest($part);
        });

        static::saving(function (Part $part): void {
            if ($part->isDirty('car_id')) {
                $part->fillVehicleSnapshot();
            }
        });
    }

    public static function statusOptions(): array
    {
        return ['draft'=>'Szkic','needs_review'=>'Do sprawdzenia','ready'=>'Gotowa','published'=>'Opublikowana','sold'=>'Sprzedana','archived'=>'Archiwalna'];
    }

    public function fillVehicleSnapshot(): void
    {
        $car = $this->car ?: ($this->car_id ? Car::query()->find($this->car_id) : null);
        $this->vehicle_snapshot = $car ? [
            'make'=>$car->make,'model'=>$car->model,'model_variant'=>$car->model_variant,'production_year'=>$car->production_year,
            'fuel_type'=>$car->fuel_type,'gearbox_type'=>$car->gearbox_type,'engine_capacity_cm3'=>$car->engine_capacity_cm3,
            'engine_code'=>$car->engine_code,'color'=>$car->color,'steering_side'=>$car->steering_side,
        ] : null;
    }

    public function primaryImage(): ?PartImage
    {
        return $this->images()->orderByDesc('is_primary')->orderBy('sort_order')->first();
    }

    public function getPrimaryImagePathAttribute(): ?string
    {
        return $this->primaryImage()?->path;
    }

    public function images(): HasMany { return $this->hasMany(PartImage::class)->orderBy('sort_order'); }
    public function category(): BelongsTo { return $this->belongsTo(PartCategory::class, 'category_id'); }
    public function suggestedCategory(): BelongsTo { return $this->belongsTo(PartCategory::class, 'suggested_category_id'); }
    public function car(): BelongsTo { return $this->belongsTo(Car::class); }
    public function storageLocation(): BelongsTo { return $this->belongsTo(StorageLocation::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
