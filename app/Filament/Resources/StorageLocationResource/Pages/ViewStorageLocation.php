<?php

namespace App\Filament\Resources\StorageLocationResource\Pages;

use App\Filament\Resources\StorageLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewStorageLocation extends ViewRecord
{
    protected static string $resource = StorageLocationResource::class;

    protected static ?string $title = 'Miejsce składowania';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Edytuj'),
        ];
    }
}
