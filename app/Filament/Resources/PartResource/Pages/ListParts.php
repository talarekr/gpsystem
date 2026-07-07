<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Models\Car;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Services\Marketplace\ManualMarketplaceLinkMappingService;
use App\Services\Admin\PartLocalAvailabilityUpdater;
use App\Services\Marketplace\ManualMarketplaceMappingConflictException;
use App\Services\Marketplace\PreparePartMarketplaceListingService;
use Filament\Notifications\Notification;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;
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
    public string $sort = 'updated_desc';

    #[Url(as: 'per_page')]
    public string $perPage = '25';

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



    /**
     * @return array{ok: bool, part_id: int, old_status: ?string, new_status: ?string, old_availability: ?string, new_availability: ?string}|null
     */
    public function updateLocalAvailability(int $partId, int|string $availabilityFlag): ?array
    {
        if (! in_array((string) $availabilityFlag, ['0', '1'], true)) {
            $this->addError('availabilityFlag', 'Dozwolone wartości to 0 albo 1.');

            return null;
        }

        $part = $this->getPartsBaseQuery()->whereKey($partId)->first();

        if (! $part) {
            $this->addError('availabilityFlag', 'Nie znaleziono części.');

            return null;
        }

        $result = app(PartLocalAvailabilityUpdater::class)->update($part, $availabilityFlag);

        Notification::make()
            ->title((string) $availabilityFlag === '1' ? 'Część przywrócona lokalnie do sprzedaży.' : 'Część oznaczona lokalnie jako sprzedana.')
            ->body('old_status='.$result['old_status'].' | new_status='.$result['new_status'].' | old_availability='.$result['old_availability'].' | new_availability='.$result['new_availability'].' | marketplace_write=false')
            ->success()
            ->send();

        return $result;
    }

    public function saveInternalNote(int $partId, ?string $note = null): void
    {
        $part = $this->getPartsBaseQuery()->whereKey($partId)->first();

        if (! $part) {
            return;
        }

        $part->forceFill([
            'internal_note' => filled($note) ? trim((string) $note) : null,
        ])->save();

        Notification::make()
            ->title('Notatka wewnętrzna zapisana lokalnie.')
            ->success()
            ->send();
    }


    public function saveManualMarketplaceLink(int $partId, string $marketplace, ?string $url = null): void
    {
        $part = $this->getPartsBaseQuery()->whereKey($partId)->first();

        if (! $part) {
            return;
        }

        try {
            $result = app(ManualMarketplaceLinkMappingService::class)->save($part, $marketplace, (string) $url);
        } catch (ManualMarketplaceMappingConflictException $exception) {
            Log::warning('manual_marketplace_link_conflict', [
                'part_id' => $partId,
                'marketplace' => $marketplace,
                'existing_id' => $exception->existingId,
                'new_id' => $exception->newId,
                'existing_listing_id' => $exception->existingListingId,
                'existing_part_id' => $exception->existingPartId,
            ]);

            if ($exception->existingPartId !== null) {
                $body = 'Ten link jest już powiązany z inną częścią. ID oferty: '.$exception->newId.' | istniejąca część ID: '.$exception->existingPartId;
            } else {
                $body = 'Część ma już inne ID oferty. Obecne ID: '.$exception->existingId.' | Nowe ID: '.$exception->newId;
            }

            Notification::make()
                ->title('Nie można zapisać linku marketplace.')
                ->body($body)
                ->danger()
                ->send();

            return;
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Nieprawidłowy link '.ucfirst($marketplace).'.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } catch (Throwable $exception) {
            Log::error('manual_marketplace_link_save_failed', [
                'part_id' => $partId,
                'marketplace' => $marketplace,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title('Nie udało się zapisać linku marketplace.')
                ->body('Wystąpił błąd podczas zapisu linku. Sprawdź poprawność danych albo skontaktuj się z administratorem.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Lokalne mapowanie '.ucfirst($result['marketplace']).' zapisane.')
            ->body('marketplace='.$result['marketplace'].' | extracted_external_id='.$result['external_id'].' | saved_listing_id='.$result['listing']->id.' | mapping_ready='.(($result['mapping_ready'] ?? false) ? 'true' : 'false').' | marketplace_write=false | sync_triggered=false')
            ->success()
            ->send();
    }

    public function markListingReady(int $partId): void
    {
        $part = Part::query()->whereKey($partId)->where('needs_listing', true)->first();

        if (! $part) {
            return;
        }

        $prepareService = app(PreparePartMarketplaceListingService::class);
        $blockers = $prepareService->localPublishBlockers($part);

        if ($blockers !== []) {
            Notification::make()
                ->title('Uzupełnij wymagane dane przed wystawieniem części.')
                ->body(implode(' | ', $blockers))
                ->danger()
                ->send();

            return;
        }

        $prepareService->preview($part, dryRun: true);
        $prepareService->markLocallyListed($part);

        Notification::make()
            ->title('Część zapisana i zdjęta z kolejki do wystawienia. Marketplace: przygotowano lokalną walidację, bez wysyłki ofert.')
            ->success()
            ->send();
    }

    public function getShowListingReadyActionProperty(): bool
    {
        return false;
    }

    public function getShowAddedAtInPartTitleProperty(): bool
    {
        return false;
    }

    public function getActiveFiltersCountProperty(): int
    {
        return collect([$this->status,$this->categoryId,$this->categoryNeedsReview,$this->isVisibleStorefront,$this->needsListing,$this->needsReview,$this->missingImages,$this->missingPrice,$this->missingSku,$this->missingPartNumber,$this->createdFrom,$this->createdUntil,$this->carId,$this->storageLocationId,$this->conditionNotes,$this->priceFrom,$this->priceUntil,$this->allegroPriceFrom,$this->allegroPriceUntil,$this->ebayPriceFrom,$this->ebayPriceUntil,$this->createdBy])->filter(fn ($value): bool => filled($value))->count();
    }

    public function getPartsProperty(): LengthAwarePaginator
    {
        $perPage = $this->normalizedPerPage();

        if ($perPage === 'all') {
            $items = $this->getPartsQuery()->get();

            return new LaravelLengthAwarePaginator(
                $items,
                $items->count(),
                max($items->count(), 1),
                1,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }

        return $this->getPartsQuery()->paginate($perPage)->withQueryString();
    }


    public function getPerPageOptionsProperty(): array
    {
        return [
            '25' => '25',
            '50' => '50',
            '100' => '100',
            '250' => '250',
            'all' => 'Wszystkie',
        ];
    }

    protected function normalizedPerPage(): int|string
    {
        return array_key_exists($this->perPage, $this->perPageOptions) ? $this->perPage : '25';
    }

    public function getStorageLocationOptionsProperty(): array
    {
        return StorageLocation::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function getCarOptionsProperty(): array
    {
        return Car::query()->orderBy('make')->orderBy('model')->limit(300)->get()->mapWithKeys(fn (Car $car): array => [$car->id => PartResource::carLabel($car)])->all();
    }


    protected function getPartsBaseQuery(): Builder
    {
        return PartResource::adminAllPartsQuery();
    }

    protected function getPartsQuery(): Builder
    {
        $query = $this->getPartsBaseQuery()->with([
            'images:id,part_id,path,sort_order,is_primary',
            'marketplaceListings:id,part_id,marketplace,external_offer_id,external_listing_id,price,currency,status,sync_status,match_status,last_error,url,last_api_status,last_seen_at,not_seen_in_active_api_at',
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
            ->tap(fn (Builder $q) => $this->applyMarketplaceGapsFilter($q))
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

    protected function applyMarketplaceGapsFilter(Builder $query): void
    {
        if ($this->sort !== 'marketplace_gaps') {
            return;
        }

        $query
            ->whereIn('status', ['ready', 'published'])
            ->where('quantity', '>', 0)
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('marketplaceListings', fn (Builder $listing) => $this->activeListingConstraint($listing, ['allegro', 'allegro_main'], true))
                ->orWhereDoesntHave('marketplaceListings', fn (Builder $listing) => $this->activeListingConstraint($listing, ['ovoko']))
                ->orWhereDoesntHave('marketplaceListings', fn (Builder $listing) => $this->activeListingConstraint($listing, ['ebay_de', 'ebay_fr']))
            );
    }

    /**
     * @param array<int, string> $marketplaces
     */
    protected function activeListingConstraint(Builder $query, array $marketplaces, bool $allegroStrict = false): Builder
    {
        $endedStatuses = ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'NOT_FOUND_IN_ACTIVE_API'];

        $query
            ->whereIn('marketplace', $marketplaces)
            ->where(fn (Builder $q) => $q
                ->whereRaw("NULLIF(TRIM(COALESCE(external_offer_id, '')), '') IS NOT NULL")
                ->orWhereRaw("NULLIF(TRIM(COALESCE(external_listing_id, '')), '') IS NOT NULL")
            )
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhereNotIn('status', $endedStatuses))
            ->where(fn (Builder $q) => $q->whereNull('last_api_status')->orWhereNotIn('last_api_status', $endedStatuses));

        if ($allegroStrict) {
            $query->where(fn (Builder $q) => $q
                ->where('last_api_status', 'ACTIVE')
                ->orWhereIn('status', ['ACTIVE', 'published', 'publication_pending'])
            );
        }

        return $query;
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
            'marketplace_gaps' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };
    }
}
