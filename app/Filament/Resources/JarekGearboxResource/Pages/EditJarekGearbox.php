<?php

namespace App\Filament\Resources\JarekGearboxResource\Pages;

use App\Filament\Resources\JarekGearboxResource;
use Filament\Resources\Pages\EditRecord;

class EditJarekGearbox extends EditRecord
{
    protected static string $resource = JarekGearboxResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['name'] = $data['title'] ?? null;
        $data['part_number'] = $data['allegro_offer_id'] ?? $data['ebay_inventory_sku'] ?? null;
        $data['condition_notes'] = $data['import_status'] ?? 'Używany';
        $data['allegro_price'] = $data['price'] ?? null;
        $data['ebay_price'] = $data['price'] ?? null;
        $data['part_photo_paths'] = [];
        $data['marketplace_category_selections'] = [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            'title' => $data['name'] ?? $this->record->title,
            'allegro_offer_id' => $data['part_number'] ?? $this->record->allegro_offer_id,
            'description' => $data['description'] ?? $this->record->description,
            'price' => $data['price'] ?? $this->record->price,
            'currency' => $data['currency'] ?? $this->record->currency ?? 'PLN',
            'import_status' => $data['condition_notes'] ?? $this->record->import_status,
        ];
    }
}
