<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPart extends ViewRecord
{
    protected static string $resource = PartResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make()->label('Edytuj')];
    }
}
