<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Filament\Resources\CarResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCar extends EditRecord
{
    protected static string $resource = CarResource::class;

    protected static ?string $title = 'Edytuj samochód';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Usuń samochód'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Samochód został zapisany';
    }
}
