<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Support\Parts\AdditionalPartCodes;
use App\Models\PartCategory;
use App\Services\PartCategorySuggestionService;
use App\Services\Marketplace\AllegroCategoryResolver;
use App\Services\Marketplace\AllegroFunctionsParameterService;
use App\Services\Marketplace\AllegroFunctionsSelectionService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePart extends CreateRecord
{
    protected static string $resource = PartResource::class;

    protected array $partPhotoPaths = [];

    protected array $allegroFunctionsValueIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->partPhotoPaths = $data['part_photo_paths'] ?? [];
        $this->allegroFunctionsValueIds = (array) ($data[PartResource::ALLEGRO_FUNCTIONS_FIELD] ?? []);
        unset($data['part_photo_paths'], $data[PartResource::ALLEGRO_FUNCTIONS_FIELD]);

        $data['quantity'] = 1;
        $data['additional_part_codes'] = AdditionalPartCodes::normalize($data['additional_part_codes'] ?? null, $data['part_number'] ?? null);
        $data['condition_notes'] = PartResource::defaultConditionValue($data['condition_notes'] ?? null);
        $data = PartResource::applyAdminSteeringFormStateToData($data);

        return $data;
    }

    protected function afterCreate(): void
    {
        PartResource::syncPartImages($this->record, $this->partPhotoPaths);
        $this->syncAllegroFunctionsSelections();
    }


    private function syncAllegroFunctionsSelections(): void
    {
        $resolved = app(AllegroCategoryResolver::class)->resolve($this->record);
        if (blank($resolved['id'] ?? null) || $this->allegroFunctionsValueIds === []) return;
        $definition = app(AllegroFunctionsParameterService::class)->definition((string) $resolved['id']);
        if (($definition['found'] ?? false) !== true) return;
        app(AllegroFunctionsSelectionService::class)->sync($this->record, (string) $resolved['id'], $definition['definition'], $this->allegroFunctionsValueIds);
    }

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()->label('Dodaj część');
    }

    public function setPartCategoryFromPicker(mixed $categoryId = null): bool
    {
        if (blank($categoryId)) {
            Notification::make()->title('Nie wybrano kategorii')->danger()->send();

            return false;
        }

        $category = PartCategory::query()->withCount('children')->find($categoryId);

        if (! $category || $category->children_count > 0) {
            Notification::make()->title('Wybierz kategorię końcową')->danger()->send();

            return false;
        }

        $this->data['category_id'] = $category->getKey();
        $this->data['marketplace_category_mappings_state'] = app(PartCategorySuggestionService::class)->marketplaceMappingsForCategory($category->getKey());
        $this->dispatch('close-modal', id: 'form-component-action');

        return true;
    }

    public function selectSuggestedPartCategory(mixed $categoryId = null): bool
    {
        return $this->setPartCategoryFromPicker($categoryId);
    }
}
