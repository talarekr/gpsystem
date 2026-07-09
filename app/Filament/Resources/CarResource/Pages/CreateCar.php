<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Filament\Resources\CarResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCar extends CreateRecord
{
    protected static string $resource = CarResource::class;

    protected static ?string $title = 'Dodaj samochód';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return CarResource::normalizeOvokoLocalMappingData($data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Samochód został dodany';
    }
}
