<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Filament\Resources\CarResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCar extends CreateRecord
{
    protected static string $resource = CarResource::class;

    protected static ?string $title = 'Dodaj samochód';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Samochód został dodany';
    }
}
