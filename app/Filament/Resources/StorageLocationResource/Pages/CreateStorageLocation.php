<?php

namespace App\Filament\Resources\StorageLocationResource\Pages;

use App\Filament\Resources\StorageLocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStorageLocation extends CreateRecord
{
    protected static string $resource = StorageLocationResource::class;

    protected static ?string $title = 'Dodaj miejsce składowania';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Miejsce składowania zostało dodane';
    }
}
