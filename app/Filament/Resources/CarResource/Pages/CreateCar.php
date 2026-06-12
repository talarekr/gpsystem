<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Filament\Resources\CarResource;
use App\Filament\Resources\CarResource\Pages\Concerns\ManagesCarImages;
use Filament\Resources\Pages\CreateRecord;

class CreateCar extends CreateRecord
{
    use ManagesCarImages;

    protected static string $resource = CarResource::class;

    protected static ?string $title = 'Dodaj samochód';

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->extractPhotoPaths($data);
    }

    protected function afterCreate(): void
    {
        $this->syncCarImages($this->record);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Samochód został dodany';
    }
}
