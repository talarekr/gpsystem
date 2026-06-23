<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class PartsNeedsReview extends ListRecords
{
    protected static string $resource = PartResource::class;

    protected static ?string $title = 'Części do wyjaśnienia';

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Dodaj część')];
    }

    protected function getTableQuery(): ?Builder
    {
        return parent::getTableQuery()?->where('needs_review', true);
    }
}
