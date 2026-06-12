<?php

namespace App\Filament\Resources\StorageLocationResource\Pages;

use App\Filament\Resources\StorageLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStorageLocation extends EditRecord
{
    protected static string $resource = StorageLocationResource::class;

    protected static ?string $title = 'Edytuj miejsce składowania';

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()
                ->label('Podgląd'),
            Actions\DeleteAction::make()
                ->label('Usuń miejsce składowania'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Miejsce składowania zostało zapisane';
    }
}
