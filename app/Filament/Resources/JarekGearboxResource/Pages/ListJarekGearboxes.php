<?php

namespace App\Filament\Resources\JarekGearboxResource\Pages;

use App\Filament\Resources\JarekGearboxResource;
use App\Filament\Resources\PartResource\Pages\ListParts;
use App\Models\JarekGearbox;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class ListJarekGearboxes extends ListParts
{
    protected static string $resource = JarekGearboxResource::class;

    protected static string $view = 'filament.resources.parts.pages.list-parts';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function saveInternalNote(int $partId, ?string $note = null): void
    {
        $gearbox = $this->getPartsBaseQuery()->whereKey($partId)->first();

        if (! $gearbox) {
            return;
        }

        $gearbox->forceFill(['plain_description' => filled($note) ? trim((string) $note) : null])->save();

        Notification::make()->title('Notatka Skrzyni Jarka zapisana lokalnie w jarek_gearboxes.')->success()->send();
    }

    public function saveManualMarketplaceLink(int $partId, string $marketplace, ?string $url = null): void
    {
        Notification::make()->title('Mapowanie marketplace dla Skrzyń Jarka jest tylko podglądem; nie wykonano zapisu ani wysyłki.')->warning()->send();
    }

    protected function getPartsBaseQuery(): Builder
    {
        return JarekGearbox::query();
    }

    protected function getPartsQuery(): Builder
    {
        $query = $this->getPartsBaseQuery();

        return $query
            ->when(filled($this->search), fn (Builder $q) => $this->applySearch($q, trim($this->search)))
            ->when(filled($this->status), fn (Builder $q) => $q->where('allegro_status', $this->status))
            ->when(filled($this->categoryId), fn (Builder $q) => $q->where('category_id', $this->categoryId))
            ->when(filled($this->conditionNotes), fn (Builder $q) => $q->where('import_status', 'like', '%'.$this->conditionNotes.'%'))
            ->when(filled($this->createdFrom), fn (Builder $q) => $q->whereDate('imported_at', '>=', $this->createdFrom))
            ->when(filled($this->createdUntil), fn (Builder $q) => $q->whereDate('imported_at', '<=', $this->createdUntil))
            ->tap(fn (Builder $q) => $this->applyTernaryFilters($q))
            ->tap(fn (Builder $q) => $this->applyRange($q, 'price', $this->priceFrom, $this->priceUntil))
            ->tap(fn (Builder $q) => $this->applyRange($q, 'price', $this->allegroPriceFrom, $this->allegroPriceUntil))
            ->tap(fn (Builder $q) => $this->applyRange($q, 'price', $this->ebayPriceFrom, $this->ebayPriceUntil))
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

    protected function applyTernaryFilters(Builder $query): void
    {
        if (filled($this->missingImages)) {
            ($this->missingImages === '1')
                ? $query->where(fn (Builder $q) => $q->whereNull('main_image_url')->orWhere('main_image_url', ''))
                : $query->whereNotNull('main_image_url')->where('main_image_url', '<>', '');
        }

        if (filled($this->missingPrice)) {
            ($this->missingPrice === '1')
                ? $query->where(fn (Builder $q) => $q->whereNull('price')->orWhere('price', '<=', 0))
                : $query->whereNotNull('price')->where('price', '>', 0);
        }
    }

    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'id_asc' => $query->orderBy('id'),
            'quantity_desc' => $query->orderByDesc('quantity')->orderByDesc('id'),
            'quantity_asc' => $query->orderBy('quantity')->orderByDesc('id'),
            'status_asc' => $query->orderBy('allegro_status')->orderByDesc('id'),
            'created_desc' => $query->orderByDesc('imported_at')->orderByDesc('id'),
            'created_asc' => $query->orderBy('imported_at')->orderByDesc('id'),
            'updated_desc' => $query->orderByDesc('updated_from_allegro_at')->orderByDesc('id'),
            'updated_asc' => $query->orderBy('updated_from_allegro_at')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };
    }
}
