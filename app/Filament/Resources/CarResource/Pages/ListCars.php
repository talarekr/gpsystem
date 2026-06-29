<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Filament\Resources\CarResource;
use App\Models\Car;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ListCars extends Page
{
    use WithPagination;

    protected static string $resource = CarResource::class;
    protected static string $view = 'filament.resources.cars.pages.list-cars';
    protected static ?string $title = 'Wszystkie samochody';

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'status')]
    public ?string $status = null;

    #[Url(as: 'fuel')]
    public ?string $fuelType = null;

    #[Url(as: 'gearbox')]
    public ?string $gearboxType = null;

    #[Url(as: 'steering')]
    public ?string $steeringSide = null;

    #[Url(as: 'sort')]
    public string $sort = 'id_desc';

    #[Url(as: 'per_page')]
    public string $perPage = '25';

    public function getMaxContentWidth(): MaxWidth|string|null { return MaxWidth::Full; }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Dodaj samochód')];
    }

    public function updating(string $property): void
    {
        if ($property !== 'page') $this->resetPage();
    }

    public function resetFilters(): void
    {
        foreach (['status', 'fuelType', 'gearboxType', 'steeringSide'] as $property) {
            $this->{$property} = null;
        }
        $this->resetPage();
    }

    public function getActiveFiltersCountProperty(): int
    {
        return collect([$this->status, $this->fuelType, $this->gearboxType, $this->steeringSide])->filter(fn ($value): bool => filled($value))->count();
    }

    public function getCarsProperty(): LengthAwarePaginator
    {
        return $this->getCarsQuery()->paginate($this->normalizedPerPage())->withQueryString();
    }

    public function getPerPageOptionsProperty(): array
    {
        return ['25' => '25', '50' => '50', '100' => '100', '250' => '250'];
    }

    protected function normalizedPerPage(): int
    {
        return (int) (array_key_exists($this->perPage, $this->perPageOptions) ? $this->perPage : '25');
    }

    protected function getCarsQuery(): Builder
    {
        return Car::query()
            ->with('createdBy:id,name')
            ->when(filled($this->search), fn (Builder $query) => $this->applySearch($query, trim($this->search)))
            ->when(filled($this->status), fn (Builder $query) => $query->where('status', $this->status))
            ->when(filled($this->fuelType), fn (Builder $query) => $query->where('fuel_type', $this->fuelType))
            ->when(filled($this->gearboxType), fn (Builder $query) => $query->where('gearbox_type', $this->gearboxType))
            ->when(filled($this->steeringSide), fn (Builder $query) => $query->where('steering_side', $this->steeringSide))
            ->tap(fn (Builder $query) => $this->applySort($query));
    }

    protected function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('id', $search)
            ->orWhere('make', 'like', "%{$search}%")
            ->orWhere('model', 'like', "%{$search}%")
            ->orWhere('model_variant', 'like', "%{$search}%")
            ->orWhere('vin', 'like', "%{$search}%")
            ->orWhere('registration_number', 'like', "%{$search}%")
            ->orWhere('engine_code', 'like', "%{$search}%"));
    }

    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'id_asc' => $query->orderBy('id'),
            'make_asc' => $query->orderBy('make')->orderBy('model')->orderByDesc('id'),
            'purchase_desc' => $query->orderByDesc('purchase_date')->orderByDesc('id'),
            'purchase_asc' => $query->orderBy('purchase_date')->orderByDesc('id'),
            'status_asc' => $query->orderBy('status')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };
    }
}
