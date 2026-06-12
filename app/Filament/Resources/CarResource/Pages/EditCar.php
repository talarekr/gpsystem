<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Filament\Resources\CarResource;
use App\Filament\Resources\CarResource\Pages\Concerns\ManagesCarImages;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCar extends EditRecord
{
    use ManagesCarImages;

    protected static string $resource = CarResource::class;

    protected static ?string $title = 'Edytuj samochód';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Usuń samochód'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractPhotoPaths($data);
    }

    protected function afterSave(): void
    {
        $this->syncCarImages($this->record);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Samochód został zapisany';
    }
}
