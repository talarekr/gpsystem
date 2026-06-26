<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Models\Car;
use App\Models\Part;
use App\Models\StorageLocation;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ListParts extends Page
{
    use WithPagination;

    protected static string $resource = PartResource::class;

    protected static string $view = 'filament.resources.parts.pages.list-parts';

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'status')]
    public ?string $status = null;

    #[Url(as: 'category')]
    public ?string $categoryId = null;

    #[Url(as: 'category_review')]
    public ?string $categoryNeedsReview = null;

    #[Url(as: 'visible')]
    public ?string $isVisibleStorefront = null;

    #[Url(as: 'needs_listing')]
    public ?string $needsListing = null;

    #[Url(as: 'needs_review')]
    public ?string $needsReview = null;

    #[Url(as: 'missing_images')]
    public ?string $missingImages = null;

    #[Url(as: 'missing_price')]
    public ?string $missingPrice = null;

    #[Url(as: 'missing_sku')]
    public ?string $missingSku = null;

    #[Url(as: 'missing_part_number')]
    public ?string $missingPartNumber = null;

    #[Url(as: 'created_from')]
    public ?string $createdFrom = null;

    #[Url(as: 'created_until')]
    public ?string $createdUntil = null;

    #[Url(as: 'car')]
    public ?string $carId = null;

    #[Url(as: 'storage')]
    public ?string $storageLocationId = null;

    #[Url(as: 'condition')]
    public ?string $conditionNotes = null;

    #[Url(as: 'price_from')]
    public ?string $priceFrom = null;

    #[Url(as: 'price_until')]
    public ?string $priceUntil = null;

    #[Url(as: 'allegro_price_from')]
    public ?string $allegroPriceFrom = null;

    #[Url(as: 'allegro_price_until')]
    public ?string $allegroPriceUntil = null;

    #[Url(as: 'ebay_price_from')]
    public ?string $ebayPriceFrom = null;

    #[Url(as: 'ebay_price_until')]
    public ?string $ebayPriceUntil = null;

    #[Url(as: 'created_by')]
    public ?string $createdBy = null;

    #[Url(as: 'sort')]
    public string $sort = 'id_desc';

    public int $perPage = 25;

    public function getTitle(): string|Htmlable { return ''; }
    public function getHeading(): string|Htmlable { return ''; }
    public function getMaxContentWidth(): MaxWidth|string|null { return MaxWidth::Full; }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Dodaj część')];
    }

    public function updating(string $property): void
    {
        if ($property !== 'page') $this->resetPage();
    }

    public function resetFilters(): void
    {
        foreach (['status','categoryId','categoryNeedsReview','isVisibleStorefront','needsListing','needsReview','missingImages','missingPrice','missingSku','missingPartNumber','createdFrom','createdUntil','carId','storageLocationId','conditionNotes','priceFrom','priceUntil','allegroPriceFrom','allegroPriceUntil','ebayPriceFrom','ebayPriceUntil','createdBy'] as $property) {
            $this->{$property} = null;
        }
        $this->resetPage();
    }

    public function markListingReady(int $partId): void
    {
        Part::query()->whereKey($partId)->where('needs_listing', true)->update(['needs_listing' => false]);
    }

    public function getActiveFiltersCountProperty(): int
    {
        return collect([$this->status,$this->categoryId,$this->categoryNeedsReview,$this->isVisibleStorefront,$this->needsListing,$this->needsReview,$this->missingImages,$this->missingPrice,$this->missingSku,$this->missingPartNumber,$this->createdFrom,$this->createdUntil,$this->carId,$this->storageLocationId,$this->conditionNotes,$this->priceFrom,$this->priceUntil,$this->allegroPriceFrom,$this->allegroPriceUntil,$this->ebayPriceFrom,$this->ebayPriceUntil,$this->createdBy])->filter(fn ($value): bool => filled($value))->count();
    }

    public function getPartsProperty(): LengthAwarePaginator
    {
        return $this->getPartsQuery()->paginate($this->perPage);
    }

    public function getStorageLocationOptionsProperty(): array
    {
        return StorageLocation::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function getCarOptionsProperty(): array
    {
        return Car::query()->orderBy('make')->orderBy('model')->limit(300)->get()->mapWithKeys(fn (Car $car): array => [$car->id => PartResource::carLabel($car)])->all();
    }

    protected function getPartsQuery(): Builder
    {
        $query = PartResource::adminAllPartsQuery()->with([
            'images:id,part_id,path,sort_order,is_primary',
            'marketplaceListings:id,part_id,marketplace,external_offer_id,price,currency,status,sync_status,match_status,last_error,url,last_api_status,last_seen_at,not_seen_in_active_api_at',
            'storageLocation:id,name,description',
            'category:id,name',
            'car:id,make,model,model_variant,production_year,first_registration_year',
        ]);

        return $query
            ->when(filled($this->search), fn (Builder $q) => $this->applySearch($q, trim($this->search)))
            ->when(filled($this->status), fn (Builder $q) => $q->where('status', $this->status))
            ->when(filled($this->categoryId), fn (Builder $q) => $q->where('category_id', $this->categoryId))
            ->when(filled($this->carId), fn (Builder $q) => $q->where('car_id', $this->carId))
            ->when(filled($this->storageLocationId), fn (Builder $q) => $q->where('storage_location_id', $this->storageLocationId))
            ->when(filled($this->conditionNotes), fn (Builder $q) => $q->where('condition_notes', 'like', '%'.$this->conditionNotes.'%'))
            ->when(filled($this->createdFrom), fn (Builder $q) => $q->whereDate('created_at', '>=', $this->createdFrom))
            ->when(filled($this->createdUntil), fn (Builder $q) => $q->whereDate('created_at', '<=', $this->createdUntil))
            ->when(filled($this->createdBy), fn (Builder $q) => $q->whereHas('createdBy', fn (Builder $u) => $u->where('name', 'like', '%'.$this->createdBy.'%')->orWhere('email', 'like', '%'.$this->createdBy.'%')))
            ->tap(fn (Builder $q) => $this->applyTernaryFilters($q))
            ->tap(fn (Builder $q) => $this->applyRange($q, 'price', $this->priceFrom, $this->priceUntil))
            ->tap(fn (Builder $q) => $this->applyRange($q, 'allegro_price', $this->allegroPriceFrom, $this->allegroPriceUntil))
            ->tap(fn (Builder $q) => $this->applyRange($q, 'ebay_price', $this->ebayPriceFrom, $this->ebayPriceUntil))
            ->tap(fn (Builder $q) => $this->applySort($q));
    }

    protected function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('id', $search)
            ->orWhere('name', 'like', "%{$search}%")
            ->orWhere('sku', 'like', "%{$search}%")
            ->orWhere('part_number', 'like', "%{$search}%")
            ->orWhere('oem_number', 'like', "%{$search}%")
            ->orWhere('manufacturer_code', 'like', "%{$search}%")
            ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"))
            ->orWhereHas('car', fn (Builder $c) => $c->where('make', 'like', "%{$search}%")->orWhere('model', 'like', "%{$search}%"))
        );
    }

    protected function applyTernaryFilters(Builder $query): void
    {
        foreach ([['category_needs_review',$this->categoryNeedsReview], ['is_visible_storefront',$this->isVisibleStorefront], ['needs_listing',$this->needsListing], ['needs_review',$this->needsReview]] as [$field, $value]) {
            if (filled($value)) $query->where($field, $value === '1');
        }
        if (filled($this->missingImages)) ($this->missingImages === '1') ? $query->doesntHave('images') : $query->has('images');
        if (filled($this->missingPrice)) ($this->missingPrice === '1') ? $query->where(fn (Builder $q) => $q->whereNull('price')->orWhere('price', '<=', 0)) : $query->whereNotNull('price')->where('price', '>', 0);
        if (filled($this->missingSku)) ($this->missingSku === '1') ? $query->where(fn (Builder $q) => $q->whereNull('sku')->orWhere('sku', '')) : $query->whereNotNull('sku')->where('sku', '<>', '');
        if (filled($this->missingPartNumber)) ($this->missingPartNumber === '1') ? $query->where(fn (Builder $q) => $q->whereNull('part_number')->orWhere('part_number', '')) : $query->whereNotNull('part_number')->where('part_number', '<>', '');
    }

    protected function applyRange(Builder $query, string $field, ?string $from, ?string $until): void
    {
        if (filled($from)) $query->where($field, '>=', $from);
        if (filled($until)) $query->where($field, '<=', $until);
    }

    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'id_asc' => $query->orderBy('id'),
            'quantity_desc' => $query->orderByDesc('quantity')->orderByDesc('id'),
            'quantity_asc' => $query->orderBy('quantity')->orderByDesc('id'),
            'status_asc' => $query->orderBy('status')->orderByDesc('id'),
            'review_detected_desc' => $query->orderByDesc('review_detected_at')->orderByDesc('id'),
            'created_desc' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'created_asc' => $query->orderBy('created_at')->orderByDesc('id'),
            'updated_desc' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            'updated_asc' => $query->orderBy('updated_at')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };
    }
}
