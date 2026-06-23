<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class PartsToList extends ListRecords
{
    protected static string $resource = PartResource::class;

    protected static ?string $title = 'Części do wystawienia';

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Dodaj część')];
    }

    protected function getTableQuery(): ?Builder
    {
        return parent::getTableQuery()?->where('needs_listing', true)->where(fn (Builder $query) => $query->where('needs_review', false)->orWhereNull('needs_review'));
    }
}
