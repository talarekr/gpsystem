<?php

namespace App\Models;

use App\Services\PartCategorySuggestionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Part extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_system','external_id','sku','name','slug','legacy_url','legacy_slug','part_number','oem_number','manufacturer_code','short_description','description','condition_notes',
        'category_id','suggested_category_id','category_confidence','category_suggestion_reason','category_needs_review',
        'car_id','vehicle_snapshot','legacy_payload','storage_location_id','price','currency','allegro_price','ebay_price','quantity','status',
        'is_visible_storefront','created_by',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_snapshot' => 'array',
            'legacy_payload' => 'array',
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


    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeNotSold(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['sold', 'archived']);
    }

    public function scopeStorefrontVisible(Builder $query): Builder
    {
        // Temporary for staging/dev: imported Woo products may remain draft before final publishing workflow is enabled.
        return $query->inStock()->notSold()->whereIn('status', ['draft', 'needs_review', 'ready', 'published'])->where(function (Builder $query): void {
            $query->where('is_visible_storefront', true)->orWhereIn('status', ['draft', 'needs_review', 'ready', 'published']);
        });
    }

    public function scopeSearchStorefront(Builder $query, ?string $value): Builder
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($value): void {
            foreach (['name', 'sku', 'part_number', 'oem_number', 'manufacturer_code', 'short_description', 'description'] as $column) {
                $query->orWhere($column, 'like', '%'.$value.'%');
            }
        });
    }

    public function scopePartNumberSearch(Builder $query, ?string $value): Builder
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $query;
        }

        $normalized = str_replace([' ', '-'], '', mb_strtoupper($value));

        return $query->where(function (Builder $query) use ($value, $normalized): void {
            foreach (['part_number', 'oem_number', 'manufacturer_code', 'sku'] as $column) {
                $query->orWhere($column, 'like', '%'.$value.'%')
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(COALESCE($column, ''), ' ', ''), '-', '')) LIKE ?", ['%'.$normalized.'%']);
            }
        });
    }

    public function scopePriceBetween(Builder $query, mixed $min, mixed $max): Builder
    {
        return $query
            ->when(is_numeric($min), fn (Builder $query) => $query->where('price', '>=', (float) $min))
            ->when(is_numeric($max), fn (Builder $query) => $query->where('price', '<=', (float) $max));
    }

    public function scopeForCategory(Builder $query, PartCategory|int|null $category): Builder
    {
        $categoryId = $category instanceof PartCategory ? $category->id : $category;

        return $categoryId ? $query->where('category_id', $categoryId) : $query;
    }

    public function scopeForCar(Builder $query, Car|int|null $car): Builder
    {
        $carId = $car instanceof Car ? $car->id : $car;

        return $carId ? $query->where('car_id', $carId) : $query;
    }

    public function images(): HasMany { return $this->hasMany(PartImage::class)->orderBy('sort_order'); }
    public function category(): BelongsTo { return $this->belongsTo(PartCategory::class, 'category_id'); }
    public function suggestedCategory(): BelongsTo { return $this->belongsTo(PartCategory::class, 'suggested_category_id'); }
    public function car(): BelongsTo { return $this->belongsTo(Car::class); }
    public function storageLocation(): BelongsTo { return $this->belongsTo(StorageLocation::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
