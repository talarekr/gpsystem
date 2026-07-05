<?php

namespace App\Filament\Resources\StorageLocationResource\Pages;

use App\Filament\Resources\StorageLocationResource;
use App\Models\StorageLocation;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ListStorageLocations extends Page
{
    use WithPagination;

    protected static string $resource = StorageLocationResource::class;
    protected static string $view = 'filament.resources.storage-locations.pages.list-storage-locations';
    protected static ?string $title = 'Miejsca składowania';

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'active')]
    public ?string $isActive = null;

    #[Url(as: 'sort')]
    public string $sort = 'name_asc';

    #[Url(as: 'per_page')]
    public string $perPage = '25';

    public function getMaxContentWidth(): MaxWidth|string|null { return MaxWidth::Full; }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Dodaj miejsce składowania')
                ->modalHeading('Dodaj miejsce składowania')
                ->model(StorageLocation::class)
                ->form(StorageLocationResource::formSchema())
                ->successNotificationTitle('Miejsce składowania zostało dodane')
                ->createAnother(false)
                ->after(function (): void {
                    $this->resetPage();
                }),
        ];
    }

    public function updating(string $property): void
    {
        if ($property !== 'page') $this->resetPage();
    }

    public function getLocationsProperty(): LengthAwarePaginator
    {
        return $this->getLocationsQuery()->paginate($this->normalizedPerPage())->withQueryString();
    }

    public function getPerPageOptionsProperty(): array
    {
        return ['25' => '25', '50' => '50', '100' => '100', '250' => '250'];
    }

    protected function normalizedPerPage(): int
    {
        return (int) (array_key_exists($this->perPage, $this->perPageOptions) ? $this->perPage : '25');
    }

    protected function getLocationsQuery(): Builder
    {
        return StorageLocation::query()
            ->withCount('parts')
            ->when(filled($this->search), function (Builder $query): void {
                $search = trim($this->search);
                $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('id', $search));
            })
            ->when(filled($this->isActive), fn (Builder $query) => $query->where('is_active', $this->isActive === '1'))
            ->tap(fn (Builder $query) => $this->applySort($query));
    }

    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'id_desc' => $query->orderByDesc('id'),
            'id_asc' => $query->orderBy('id'),
            'name_desc' => $query->orderByDesc('name'),
            'updated_desc' => $query->orderByDesc('updated_at')->orderBy('name'),
            default => $query->orderBy('name'),
        };
    }
}
