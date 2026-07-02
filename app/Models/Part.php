<?php

namespace App\Models;

use App\Services\PartCategorySuggestionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class Part extends Model
{
    use HasFactory;

    /** @var array<string, bool> */
    private static array $columnExistsCache = [];

    protected $fillable = [
        'source_system','external_id','sku','name','slug','legacy_url','legacy_slug','part_number','oem_number','manufacturer_code','short_description','description','condition_notes','internal_note','code_photo_path',
        'category_id','suggested_category_id','category_confidence','category_suggestion_reason','category_needs_review',
        'car_id','vehicle_snapshot','legacy_payload','storage_location_id','weight_kg','length_cm','width_cm','height_cm','price','currency','allegro_price','ovoko_price','ebay_price','allegro_shipping_rate_name','quantity','status','sale_source','sold_at',
        'is_visible_storefront','needs_listing','needs_review','review_reason','review_detected_at','review_source','review_metadata','created_by',
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
            'ovoko_price' => 'decimal:2',
            'weight_kg' => 'decimal:3',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'ebay_price' => 'decimal:2',
            'quantity' => 'integer',
            'sold_at' => 'datetime',
            'is_visible_storefront' => 'boolean',
            'needs_listing' => 'boolean',
            'needs_review' => 'boolean',
            'review_detected_at' => 'datetime',
            'review_metadata' => 'array',
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
            $part->needs_listing ??= false;
            $part->needs_review ??= false;
            $part->fillVehicleSnapshot();
            app(PartCategorySuggestionService::class)->suggest($part);
        });

        static::saving(function (Part $part): void {
            if ($part->isDirty('car_id')) {
                $part->fillVehicleSnapshot();
            }

            // eBay price is stored in PLN. EUR conversion will happen later during eBay listing/sync using NBP table A.
            if (is_numeric($part->price)) {
                $storefrontPrice = round((float) $part->price, 2);

                $part->allegro_price = $storefrontPrice;
                $part->ebay_price = round($storefrontPrice * 1.25, 2);
            }
        });
    }


    public static function saleSourceOptions(): array
    {
        return [
            'local_sale' => 'Sprzedaż lokalna',
            'allegro' => 'Allegro',
            'ovoko' => 'Ovoko',
            'ebay' => 'eBay',
            'sklep' => 'Sklep',
            'storefront' => 'Sklep',
            'local' => 'Sklep',
        ];
    }

    public static function saleSourceLabel(?string $source): string
    {
        $normalized = mb_strtolower(trim((string) $source));

        return match ($normalized) {
            'local_sale', 'local sale', 'sprzedaż lokalna', 'sprzedaz lokalna' => 'Sprzedaż lokalna',
            'allegro' => 'Allegro',
            'ovoko' => 'Ovoko',
            'ebay', 'ebay_de', 'ebay_pl' => 'eBay',
            'sklep', 'storefront', 'local' => 'Sklep',
            '' => '—',
            default => (string) $source,
        };
    }

    public function markSoldViaLocalSale(?\DateTimeInterface $soldAt = null): void
    {
        $this->forceFill([
            'status' => 'sold',
            'quantity' => 0,
            'is_visible_storefront' => false,
            'needs_listing' => false,
            'sale_source' => 'local_sale',
            'sold_at' => $soldAt ?: now(),
        ]);
    }

    public static function statusOptions(): array
    {
        return ['draft'=>'Szkic','needs_review'=>'Do sprawdzenia','ready'=>'W sprzedaży','published'=>'Opublikowana','sold'=>'Sprzedana','archived'=>'Archiwalna'];
    }

    public function uiStatusLabel(): string
    {
        return self::statusOptions()[(string) $this->status] ?? (string) $this->status;
    }

    public function localAvailabilityForMarketplaceSync(): string
    {
        if ($this->sold_at !== null || in_array((string) $this->status, ['sold', 'archived'], true)) {
            return 'sold';
        }

        if (in_array((string) $this->status, ['ready', 'published'], true)) {
            return 'for_sale';
        }

        return 'sold';
    }

    public function localAvailabilitySourceForMarketplaceSync(): string
    {
        if ($this->sold_at !== null) {
            return 'parts.sold_at';
        }

        return 'parts.status via Part::statusOptions UI label';
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'ready' => 'success',
            'sold' => 'danger',
            'needs_review' => 'warning',
            'published' => 'info',
            default => 'gray',
        };
    }

    public static function statusTextClass(?string $status): string
    {
        return match ($status) {
            'ready' => 'gps-part-status-text--ready',
            'sold' => 'gps-part-status-text--sold',
            default => 'gps-part-status-text--default',
        };
    }

    public static function statusBadgeClass(?string $status): string
    {
        return static::statusTextClass($status);
    }

    public function fillVehicleSnapshot(): void
    {
        $car = $this->car ?: ($this->car_id ? Car::query()->find($this->car_id) : null);
        $this->vehicle_snapshot = $car ? [
            'make'=>$car->make,'model'=>$car->model,'model_variant'=>$car->model_variant,'production_year'=>$car->production_year,
            'fuel_type'=>$car->fuel_type,'gearbox_type'=>$car->gearbox_type,'engine_capacity_cm3'=>$car->engine_capacity_cm3,
            'engine_code'=>$car->engine_code,'color'=>$car->color,'color_code'=>$car->color_code,'drivetrain'=>$car->drivetrain,'body_type'=>$car->body_type,'steering_side'=>$car->steering_side,
        ] : null;
    }


    public function storefrontDescription(): string
    {
        return $this->cleanStorefrontValue($this->description)
            ?: $this->cleanStorefrontValue($this->short_description)
            ?: 'Opis produktu zostanie uzupełniony.';
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function storefrontDetails(): array
    {
        $details = [];

        $this->addStorefrontDetail($details, 'Numer części', $this->part_number);
        $this->addStorefrontDetail($details, 'OEM', $this->oem_number);
        $this->addStorefrontDetail($details, 'Kod producenta', $this->manufacturer_code);

        if ($this->isMeaningfulStorefrontValue($this->sku)
            && ! in_array($this->normalizeStorefrontValue($this->sku), array_filter([
                $this->normalizeStorefrontValue($this->part_number),
                $this->normalizeStorefrontValue($this->oem_number),
                $this->normalizeStorefrontValue($this->manufacturer_code),
            ]), true)) {
            $this->addStorefrontDetail($details, 'SKU', $this->sku);
        }

        $this->addStorefrontDetail($details, 'Stan', $this->condition_notes ?: 'Używany / sprawdzony');

        $vehicle = $this->storefrontVehicleData();

        foreach ([
            'make' => 'Producent / marka',
            'model' => 'Model',
            'model_variant' => 'Modyfikacja / wersja',
            'production_year' => 'Rok produkcji samochodu',
            'production_period' => 'Okres produkcji',
            'engine_capacity_cm3' => 'Pojemność silnika',
            'engine_code' => 'Kod silnika',
            'visible_code' => 'Kod widoczny',
            'engine_power_kw' => 'Moc silnika',
            'fuel_type' => 'Typ paliwa',
            'gearbox_type' => 'Typ skrzyni biegów',
            'drivetrain' => 'Koła napędowe / napęd',
            'steering_side' => 'Pozycja kierownicy / strona',
            'body_type' => 'Typ sylwetki / nadwozie',
            'color' => 'Kolor',
            'color_code' => 'Kod koloru',
            'mileage_km' => 'Przebieg',
        ] as $key => $label) {
            $this->addStorefrontDetail($details, $label, $this->formatStorefrontDetailValue($key, $vehicle[$key] ?? null));
        }

        return $details;
    }

    public function storefrontDetailValue(string $key): ?string
    {
        return $this->cleanStorefrontValue($this->formatStorefrontDetailValue($key, $this->storefrontVehicleData()[$key] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function storefrontVehicleData(): array
    {
        $data = [];
        $car = $this->relationLoaded('car') ? $this->car : null;

        if ($car) {
            $data = $car->only([
                'make', 'model', 'model_variant', 'production_year', 'first_registration_year', 'steering_side',
                'mileage_km', 'fuel_type', 'engine_power_kw', 'engine_capacity_cm3', 'engine_code', 'drivetrain',
                'gearbox_type', 'gearbox_code', 'body_type', 'color_code', 'color',
            ]);
        }

        $fallbacks = array_replace(
            $this->legacyVehiclePayload(),
            is_array($this->vehicle_snapshot) ? $this->vehicle_snapshot : []
        );

        foreach ($fallbacks as $key => $value) {
            if (! array_key_exists($key, $data) || ! $this->isMeaningfulStorefrontValue($data[$key] ?? null)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyVehiclePayload(): array
    {
        $payload = is_array($this->legacy_payload) ? $this->legacy_payload : [];
        $legacy = [];

        foreach (['woo_product', 'meta', 'attributes'] as $section) {
            if (isset($payload[$section]) && is_array($payload[$section])) {
                $legacy = array_replace($legacy, $payload[$section]);
            }
        }

        $map = [
            'vehicle_make' => 'make', 'make' => 'make', 'brand' => 'make',
            'vehicle_model' => 'model', 'model' => 'model',
            'vehicle_generation' => 'model_variant', 'vehicle_engine_marketing' => 'model_variant', 'model_variant' => 'model_variant',
            'vehicle_year' => 'production_year', 'car_years' => 'production_period', 'production_year' => 'production_year',
            'engine_capacity_cm3' => 'engine_capacity_cm3', 'engine_capacity' => 'engine_capacity_cm3',
            'engine_code' => 'engine_code', 'engine_power_kw' => 'engine_power_kw', 'fuel_type' => 'fuel_type',
            'gearbox_type' => 'gearbox_type', 'drivetrain' => 'drivetrain', 'steering_side' => 'steering_side',
            'body_type' => 'body_type', 'color' => 'color', 'color_code' => 'color_code', 'mileage_km' => 'mileage_km',
            'visible_code' => 'visible_code',
        ];

        $result = [];
        foreach ($map as $legacyKey => $targetKey) {
            if (array_key_exists($legacyKey, $legacy) && $this->isMeaningfulStorefrontValue($legacy[$legacyKey])) {
                $result[$targetKey] = $legacy[$legacyKey];
            }
        }

        return $result;
    }

    private function addStorefrontDetail(array &$details, string $label, mixed $value): void
    {
        $value = $this->cleanStorefrontValue($value);

        if ($value !== null) {
            $details[] = ['label' => $label, 'value' => $value];
        }
    }

    private function formatStorefrontDetailValue(string $key, mixed $value): mixed
    {
        if (! $this->isMeaningfulStorefrontValue($value)) {
            return null;
        }

        return match ($key) {
            'engine_capacity_cm3' => $this->formatEngineCapacity($value),
            'engine_power_kw' => is_numeric($value) ? ((int) $value).' kW / '.round((int) $value * 1.35962).' KM' : $value,
            'mileage_km' => is_numeric($value) ? number_format((int) $value, 0, ',', ' ').' km' : $value,
            default => $value,
        };
    }

    private function formatEngineCapacity(mixed $value): mixed
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return $value;
        }

        if (preg_match('/^\d+$/', $raw) === 1) {
            return ((int) $raw).' cm³';
        }

        if (preg_match('/^(\d+)\s*(?:c\.?m\.?\s*(?:3|³)|cc|ccm|cm\s*sześc(?:ienne|iennych)?)$/iu', $raw, $matches) === 1) {
            return ((int) $matches[1]).' cm³';
        }

        return $value;
    }

    private function cleanStorefrontValue(mixed $value): ?string
    {
        if (! $this->isMeaningfulStorefrontValue($value)) {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?: '');
    }

    private function isMeaningfulStorefrontValue(mixed $value): bool
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return false;
        }

        $value = trim(strip_tags((string) $value));

        return $value !== '' && ! in_array(mb_strtolower($value), ['-', '—', '?', '0', 'null', 'n/a', 'brak'], true);
    }

    private function normalizeStorefrontValue(mixed $value): ?string
    {
        if (! $this->isMeaningfulStorefrontValue($value)) {
            return null;
        }

        return mb_strtoupper(str_replace([' ', '-', '_'], '', (string) $value));
    }

    public function primaryImage(): ?PartImage
    {
        if ($this->relationLoaded('images')) {
            return $this->images
                ->sortBy([
                    ['is_primary', 'desc'],
                    ['sort_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->first();
        }

        return $this->images()->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')->first();
    }

    public function primaryImageUrl(): ?string
    {
        return $this->primaryImage()?->absolutePublicUrl();
    }

    public function primaryImageRelativeUrl(): ?string
    {
        return $this->primaryImage()?->relativePublicUrl();
    }

    public function listingImage(): ?PartImage
    {
        if ($this->relationLoaded('images')) {
            return $this->selectListingImageFromCollection($this->images);
        }

        $images = $this->images()->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')->get();

        return $this->selectListingImageFromCollection($images);
    }

    public function listingImageUrl(): ?string
    {
        return $this->listingImage()?->listingUrl();
    }

    public function storefrontImageUrl(): ?string
    {
        return $this->primaryImage()?->productUrl() ?? $this->primaryImageUrl();
    }

    public function adminTableImageUrl(): ?string
    {
        return $this->listingImage()?->absolutePublicUrl() ?? $this->primaryImageUrl();
    }

    public function adminTableImageVariantSource(): string
    {
        return $this->listingImage()?->absolutePublicUrl() ? 'original' : ($this->primaryImageUrl() ? 'primary' : 'fallback');
    }

    private function adminTableShouldUseProductVariant(PartImage $image): bool
    {
        if ($image->productUrl() === null) {
            return false;
        }

        $listingPadding = $this->presentationVariantMayHavePadding($image, 'listing');
        $productPadding = $this->presentationVariantMayHavePadding($image, 'product');

        return $listingPadding === true && $productPadding === false;
    }

    private function presentationVariantMayHavePadding(PartImage $image, string $variant): ?bool
    {
        $presentation = $image->legacy_payload['presentation'] ?? null;

        if (! is_array($presentation)) {
            return null;
        }

        $widthRatio = data_get($presentation, "metrics.{$variant}.fill_ratio.width_ratio", $presentation["{$variant}_fill_width_ratio"] ?? null);
        $heightRatio = data_get($presentation, "metrics.{$variant}.fill_ratio.height_ratio", $presentation["{$variant}_fill_height_ratio"] ?? null);
        $dominantRatio = data_get($presentation, "metrics.{$variant}.fill_ratio.dominant_ratio", $presentation["{$variant}_dominant_ratio"] ?? null);

        if (! is_numeric($widthRatio) && ! is_numeric($heightRatio) && ! is_numeric($dominantRatio)) {
            return null;
        }

        return (is_numeric($widthRatio) && (float) $widthRatio < 0.72)
            || (is_numeric($heightRatio) && (float) $heightRatio < 0.72)
            || (is_numeric($dominantRatio) && (float) $dominantRatio < 0.82);
    }

    private function selectListingImageFromCollection($images): ?PartImage
    {
        if ($images->isEmpty()) {
            return null;
        }

        $scored = $images->map(fn (PartImage $image): array => ['image' => $image, 'score' => $image->listingScore()])
            ->filter(fn (array $item): bool => $item['score'] !== null);

        if ($scored->isNotEmpty()) {
            return $scored->sort(function (array $a, array $b): int {
                return [$b['score'], (int) $b['image']->is_primary, $a['image']->sort_order, $a['image']->id]
                    <=> [$a['score'], (int) $a['image']->is_primary, $b['image']->sort_order, $b['image']->id];
            })->first()['image'];
        }

        return $images->sortBy([
            ['is_primary', 'desc'],
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ])->first();
    }

    public function getPrimaryImagePathAttribute(): ?string
    {
        return $this->primaryImage()?->path;
    }

    public function getPrimaryImageUrlAttribute(): ?string
    {
        return $this->primaryImage()?->absolutePublicUrl();
    }

    public function getPrimaryImageRelativeUrlAttribute(): ?string
    {
        return $this->primaryImage()?->relativePublicUrl();
    }


    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeNotSold(Builder $query): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query
            ->whereNull('status')
            ->orWhereNotIn('status', ['sold', 'archived']));
    }

    public function scopeStorefrontVisible(Builder $query): Builder
    {
        return $query
            ->where('needs_listing', false)
            ->where(fn (Builder $query) => $query->where('needs_review', false)->orWhereNull('needs_review'))
            ->inStock()
            ->notSold();
    }

    public function scopeSearchStorefront(Builder $query, ?string $value): Builder
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $query;
        }

        if ($this->looksLikePartNumberQuery($value)) {
            return $query->partNumberSearch($value);
        }

        $tokens = preg_split('/\s+/u', $value) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, static fn (string $token): bool => $token !== '')));

        if ($tokens === []) {
            return $query->whereRaw('1 = 0');
        }

        // Storefront text q version: name_only_text_q.
        // Text search intentionally filters only parts.name and combines tokens with AND.
        foreach ($tokens as $token) {
            $this->applyStorefrontNameSearchToken($query, $token);
        }

        return $query;
    }

    private function applyStorefrontNameSearchToken(Builder $query, string $token): void
    {
        $grammar = $query->getQuery()->getGrammar();
        $driver = $query->getConnection()->getDriverName();
        $wrapped = $grammar->wrap('parts.name');
        $cast = in_array($driver, ['mysql', 'mariadb'], true)
            ? "CAST($wrapped AS CHAR)"
            : "CAST($wrapped AS TEXT)";

        $query->whereRaw("LOWER(COALESCE($cast, '')) LIKE ?", ['%'.mb_strtolower($token).'%']);
    }

    private function looksLikePartNumberQuery(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || preg_match('/\s/u', $value)) {
            return false;
        }

        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';

        return strlen($normalized) >= 3 && preg_match('/\d/', $normalized) === 1;
    }

    public function scopePartNumberSearch(Builder $query, ?string $value): Builder
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $query;
        }

        $values = array_values(array_unique(array_filter([
            $value,
            mb_strtoupper($value),
            mb_strtolower($value),
        ], static fn (string $candidate): bool => $candidate !== '')));

        return $query->where(function (Builder $query) use ($values): void {
            foreach ($this->existingColumns('parts', ['part_number', 'oem_number', 'manufacturer_code', 'sku']) as $column) {
                foreach ($values as $candidate) {
                    $query->orWhere($column, $candidate)
                        ->orWhere($column, 'like', $candidate.'%');
                }
            }
        });
    }

    /**
     * @param array<int, string> $columns
     */
    private function applyCaseInsensitiveLike(Builder $query, array $columns, string $value, bool $includeNormalized = false, string $table = 'parts'): void
    {
        $grammar = $query->getQuery()->getGrammar();
        $driver = $query->getConnection()->getDriverName();
        $like = '%'.mb_strtolower($value).'%';
        $normalized = '%'.str_replace([' ', '-', '_'], '', mb_strtoupper($value)).'%';

        foreach ($this->existingColumns($table, $columns) as $column) {
            $wrapped = $grammar->wrap($column);
            $cast = in_array($driver, ['mysql', 'mariadb'], true)
                ? "CAST($wrapped AS CHAR)"
                : "CAST($wrapped AS TEXT)";

            $query->orWhereRaw("LOWER(COALESCE($cast, '')) LIKE ?", [$like]);

            if ($includeNormalized) {
                $query->orWhereRaw("UPPER(REPLACE(REPLACE(REPLACE(COALESCE($cast, ''), ' ', ''), '-', ''), '_', '')) LIKE ?", [$normalized]);
            }
        }
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function existingColumns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column): bool => $this->hasDbColumn($table, $column)));
    }

    private function hasDbColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        return self::$columnExistsCache[$key] ??= Schema::hasColumn($table, $column);
    }

    public function scopePriceBetween(Builder $query, mixed $min, mixed $max): Builder
    {
        return $query
            ->when(is_numeric($min), fn (Builder $query) => $query->where('price', '>=', (float) $min))
            ->when(is_numeric($max), fn (Builder $query) => $query->where('price', '<=', (float) $max));
    }

    public function scopeWhereStorefrontDetail(Builder $query, string $key, ?string $value): Builder
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $query;
        }

        $carColumn = match ($key) {
            'make', 'model' => $key,
            default => null,
        };

        $legacyKeys = match ($key) {
            'make' => ['vehicle_make', 'make', 'brand', 'manufacturer'],
            'model' => ['vehicle_model', 'model'],
            default => [$key],
        };

        return $query->where(function (Builder $query) use ($key, $value, $carColumn, $legacyKeys): void {
            if ($carColumn && $this->hasDbColumn('cars', $carColumn)) {
                $query->orWhereHas('car', fn (Builder $carQuery) => $carQuery->where($carColumn, $value));
            }

            if ($this->hasDbColumn('parts', 'vehicle_snapshot')) {
                $query->orWhere('vehicle_snapshot->'.$key, $value);
            }

            if ($this->hasDbColumn('parts', 'legacy_payload')) {
                foreach ($legacyKeys as $legacyKey) {
                    foreach (['woo_product', 'meta', 'attributes'] as $section) {
                        $query->orWhere('legacy_payload->'.$section.'->'.$legacyKey, $value);
                    }

                    $query->orWhere('legacy_payload->'.$legacyKey, $value);
                }
            }
        });
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
    public function marketplaceListings(): HasMany { return $this->hasMany(MarketplaceListing::class); }
    public function marketplaceSyncLogs(): HasMany { return $this->hasMany(MarketplaceSyncLog::class); }
}
