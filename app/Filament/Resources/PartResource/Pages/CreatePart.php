<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePart extends CreateRecord
{
    protected static string $resource = PartResource::class;

    protected array $partPhotoPaths = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->partPhotoPaths = $data['part_photo_paths'] ?? [];
        unset($data['part_photo_paths']);

        return $data;
    }

    protected function afterCreate(): void
    {
        PartResource::syncPartImages($this->record, $this->partPhotoPaths);
    }

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()->label('Dodaj część');
    }
}
