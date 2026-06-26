<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use Illuminate\Database\Eloquent\Builder;

class PartsToList extends ListParts
{
    protected static ?string $title = 'Części do wystawienia';

    protected function basePartsQuery(): Builder
    {
        return PartResource::adminPartsToListQuery();
    }

    public function getListContextBadge(): ?string
    {
        return 'Do wystawienia';
    }
}
