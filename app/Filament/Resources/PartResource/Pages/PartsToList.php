<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use Illuminate\Database\Eloquent\Builder;

class PartsToList extends ListParts
{
    protected static string $resource = PartResource::class;

    protected static ?string $title = 'Części do wystawienia';

    protected function getPartsBaseQuery(): Builder
    {
        return PartResource::adminPartsToListQuery();
    }

}
