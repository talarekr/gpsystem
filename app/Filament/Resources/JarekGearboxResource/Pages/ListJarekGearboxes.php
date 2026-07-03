<?php

namespace App\Filament\Resources\JarekGearboxResource\Pages;

use App\Filament\Resources\JarekGearboxResource;
use App\Models\JarekGearbox;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ListJarekGearboxes extends Page
{
    use WithPagination;

    protected static string $resource = JarekGearboxResource::class;

    protected static string $view = 'filament.resources.jarek-gearboxes.pages.list-jarek-gearboxes';

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'allegro_status')]
    public ?string $allegroStatus = null;

    #[Url(as: 'ebay_status')]
    public ?string $ebayStatus = null;

    #[Url(as: 'missing_images')]
    public ?string $missingImages = null;

    #[Url(as: 'missing_price')]
    public ?string $missingPrice = null;

    #[Url(as: 'price_from')]
    public ?string $priceFrom = null;

    #[Url(as: 'price_until')]
    public ?string $priceUntil = null;

    #[Url(as: 'imported_from')]
    public ?string $importedFrom = null;

    #[Url(as: 'imported_until')]
    public ?string $importedUntil = null;

    #[Url(as: 'sort')]
    public string $sort = 'updated_from_allegro_desc';

    #[Url(as: 'per_page')]
    public string $perPage = '25';

    public function getTitle(): string|Htmlable { return ''; }
    public function getHeading(): string|Htmlable { return ''; }
    public function getMaxContentWidth(): MaxWidth|string|null { return MaxWidth::Full; }

    public function updating(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        foreach (['allegroStatus', 'ebayStatus', 'missingImages', 'missingPrice', 'priceFrom', 'priceUntil', 'importedFrom', 'importedUntil'] as $property) {
            $this->{$property} = null;
        }

        $this->resetPage();
    }

    public function getActiveFiltersCountProperty(): int
    {
        return collect([$this->allegroStatus, $this->ebayStatus, $this->missingImages, $this->missingPrice, $this->priceFrom, $this->priceUntil, $this->importedFrom, $this->importedUntil])->filter(fn ($value): bool => filled($value))->count();
    }

    public function getGearboxesProperty(): LengthAwarePaginator
    {
        $perPage = $this->normalizedPerPage();
        $query = $this->getGearboxesQuery();

        if ($perPage === 'all') {
            $items = $query->get();

            return new LaravelLengthAwarePaginator($items, $items->count(), max($items->count(), 1), 1, [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function getPerPageOptionsProperty(): array
    {
        return ['25' => '25', '50' => '50', '100' => '100', '250' => '250', 'all' => 'Wszystkie'];
    }

    public function getAllegroStatusOptionsProperty(): array
    {
        return $this->distinctOptions('allegro_status');
    }

    public function getEbayStatusOptionsProperty(): array
    {
        return ['not_ready' => 'not_ready', 'previewed' => 'previewed', 'ready' => 'ready', 'published' => 'published'] + $this->distinctOptions('ebay_status');
    }

    protected function normalizedPerPage(): int|string
    {
        return array_key_exists($this->perPage, $this->perPageOptions) ? $this->perPage : '25';
    }

    protected function getGearboxesQuery(): Builder
    {
        return JarekGearbox::query()
            ->when(filled($this->search), fn (Builder $q) => $this->applySearch($q, trim($this->search)))
            ->when(filled($this->allegroStatus), fn (Builder $q) => $q->where('allegro_status', $this->allegroStatus))
            ->when(filled($this->ebayStatus), fn (Builder $q) => $q->where('ebay_status', $this->ebayStatus))
            ->when(filled($this->missingImages), fn (Builder $q) => $this->applyMissingImages($q))
            ->when(filled($this->missingPrice), fn (Builder $q) => $this->applyMissingPrice($q))
            ->when(filled($this->priceFrom), fn (Builder $q) => $q->where('price', '>=', $this->priceFrom))
            ->when(filled($this->priceUntil), fn (Builder $q) => $q->where('price', '<=', $this->priceUntil))
            ->when(filled($this->importedFrom), fn (Builder $q) => $q->whereDate('imported_at', '>=', $this->importedFrom))
            ->when(filled($this->importedUntil), fn (Builder $q) => $q->whereDate('imported_at', '<=', $this->importedUntil))
            ->tap(fn (Builder $q) => $this->applySort($q));
    }

    protected function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('id', $search)
            ->orWhere('title', 'like', "%{$search}%")
            ->orWhere('allegro_offer_id', 'like', "%{$search}%")
            ->orWhere('category_name', 'like', "%{$search}%")
            ->orWhere('ebay_inventory_sku', 'like', "%{$search}%")
        );
    }

    protected function applyMissingImages(Builder $query): void
    {
        ($this->missingImages === '1')
            ? $query->where(fn (Builder $q) => $q->whereNull('main_image_url')->orWhere('main_image_url', ''))
            : $query->whereNotNull('main_image_url')->where('main_image_url', '<>', '');
    }

    protected function applyMissingPrice(Builder $query): void
    {
        ($this->missingPrice === '1')
            ? $query->where(fn (Builder $q) => $q->whereNull('price')->orWhere('price', '<=', 0))
            : $query->whereNotNull('price')->where('price', '>', 0);
    }

    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'id_desc' => $query->orderByDesc('id'),
            'id_asc' => $query->orderBy('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'quantity_desc' => $query->orderByDesc('quantity')->orderByDesc('id'),
            'quantity_asc' => $query->orderBy('quantity')->orderByDesc('id'),
            'imported_desc' => $query->orderByDesc('imported_at')->orderByDesc('id'),
            'imported_asc' => $query->orderBy('imported_at')->orderByDesc('id'),
            'updated_desc' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            'updated_asc' => $query->orderBy('updated_at')->orderByDesc('id'),
            default => $query->orderByDesc('updated_from_allegro_at')->orderByDesc('id'),
        };
    }

    private function distinctOptions(string $field): array
    {
        return JarekGearbox::query()->whereNotNull($field)->distinct()->orderBy($field)->pluck($field)->filter()->mapWithKeys(fn (string $value): array => [$value => $value])->all();
    }
}
