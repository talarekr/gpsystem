<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use Illuminate\Database\Eloquent\Builder;

class ListSoldParts extends ListParts
{
    protected static string $resource = PartResource::class;

    protected static ?string $title = 'Sprzedane';

    protected function getPartsBaseQuery(): Builder
    {
        return PartResource::adminSoldPartsQuery();
    }
}
