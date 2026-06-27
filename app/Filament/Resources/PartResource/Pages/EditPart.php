<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Services\Marketplace\PreparePartMarketplaceListingService;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditPart extends EditRecord
{
    protected static string $resource = PartResource::class;

    protected array $partPhotoPaths = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (PartResource::isMissingAdminDefaultValue($data['condition_notes'] ?? null)) {
            $data['condition_notes'] = PartResource::DEFAULT_CONDITION_VALUE;
        }

        $currentSteering = $this->record->vehicle_snapshot['steering_side'] ?? null;
        $data[PartResource::ADMIN_STEERING_FORM_STATE] = PartResource::isMissingAdminDefaultValue($currentSteering)
            ? PartResource::EXPECTED_LEFT_STEERING_VALUE
            : PartResource::adminSteeringFormValue($currentSteering);

        // Existing images are rendered by the edit gallery; keep FileUpload empty so it only adds new photos.
        $data['part_photo_paths'] = [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->partPhotoPaths = array_values(array_filter((array) ($data['part_photo_paths'] ?? []), fn (mixed $path): bool => filled($path)));
        unset($data['part_photo_paths']);

        $data['condition_notes'] = PartResource::defaultConditionValue($data['condition_notes'] ?? null);
        $data = PartResource::applyAdminSteeringFormStateToData($data, $this->record);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->partPhotoPaths === []) {
            return;
        }

        $this->record->load('images');

        PartResource::syncPartImages($this->record, array_merge(
            PartResource::partImagePaths($this->record),
            $this->partPhotoPaths,
        ));

        $this->record->refresh();
        $this->record->load('images');
        $this->data['part_photo_paths'] = [];
    }

    public function deletePartImage(int $imageId): void
    {
        $image = PartImage::query()
            ->where('part_id', $this->record->getKey())
            ->find($imageId);

        if (! $image) {
            Notification::make()
                ->title('Nie znaleziono zdjęcia części')
                ->danger()
                ->send();

            return;
        }

        $image->delete();

        $this->record->refresh();
        $this->record->load('images');

        Notification::make()
            ->title('Zdjęcie części zostało usunięte')
            ->success()
            ->send();
    }

    public function setPartCategoryFromPicker(mixed $categoryId = null): bool
    {
        if (blank($categoryId)) {
            Notification::make()
                ->title('Nie wybrano kategorii')
                ->body('Kliknij docelową kategorię w panelu, a następnie ponownie użyj przycisku „Ustaw kategorię”.')
                ->danger()
                ->send();

            return false;
        }

        $category = PartCategory::query()
            ->withCount('children')
            ->find($categoryId);

        if (! $category) {
            Log::warning('Admin part category picker received an invalid category id.', [
                'part_id' => $this->record?->getKey(),
                'category_id' => $categoryId,
            ]);

            Notification::make()
                ->title('Nie znaleziono wybranej kategorii')
                ->body('Odśwież stronę i spróbuj ponownie. Jeśli problem wróci, sprawdź logi aplikacji.')
                ->danger()
                ->send();

            return false;
        }

        if ($category->children_count > 0) {
            Notification::make()
                ->title('Nie można wybrać grupy nadrzędnej')
                ->body('Wybierz kategorię końcową, nie grupę nadrzędną.')
                ->danger()
                ->send();

            return false;
        }

        try {
            $this->record->forceFill(['category_id' => $category->getKey()])->save();
            $this->record->refresh();
            $this->data['category_id'] = $category->getKey();
            $this->data['marketplace_category_mappings_state'] = app(\App\Services\PartCategorySuggestionService::class)->marketplaceMappingsForCategory($category->getKey());

            Notification::make()
                ->title('Kategoria części została zapisana')
                ->body('Nowa kategoria: '.$category->name)
                ->success()
                ->send();

            return true;
        } catch (Throwable $exception) {
            Log::error('Admin part category picker failed to save part category.', [
                'part_id' => $this->record?->getKey(),
                'category_id' => $category->getKey(),
                'exception' => $exception,
            ]);

            Notification::make()
                ->title('Nie udało się zapisać kategorii')
                ->body('Zmiana nie została zapisana. Szczegóły błędu zapisano w logach aplikacji.')
                ->danger()
                ->send();

            return false;
        }
    }

    public function selectSuggestedPartCategory(mixed $categoryId = null): bool
    {
        return $this->setPartCategoryFromPicker($categoryId);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markListingReady')
                ->label('Zapisz i wystaw')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => (bool) $this->record->needs_listing)
                ->action(function (PublishPartToMarketplacesService $publishService): void {
                    $this->save(false, false);
                    $this->record->refresh();

                    $enabled = (bool) config('marketplace.publish_enabled', false);
                    $result = $enabled
                        ? $publishService->confirm($this->record, 'all', dryRun: false, confirm: true)
                        : $publishService->preview($this->record, 'all', includePayload: true);

                    if (! $enabled || ($result['blocked'] ?? false) || ! ($result['readiness_ok'] ?? false)) {
                        $messages = collect($result['channels'] ?? [])->flatMap(fn (array $channel): array => $channel['errors'] ?? $channel['readiness']['blockers'] ?? [])->filter()->values()->all();
                        Notification::make()
                            ->title($enabled ? 'Nie udało się wystawić części.' : 'Realne wystawianie marketplace jest wyłączone — wykonano tylko preview.')
                            ->body($messages === [] ? 'MARKETPLACE_PUBLISH_ENABLED=false albo readiness wymaga uzupełnienia.' : implode(' | ', $messages))
                            ->danger()
                            ->send();
                        return;
                    }

                    Notification::make()
                        ->title('Część zapisana i wystawiona w wymaganych kanałach.')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('storefrontPreview')
                ->label('Podgląd')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): ?string => PartResource::publicProductUrl($this->record))
                ->openUrlInNewTab()
                ->visible(fn (): bool => PartResource::publicProductUrl($this->record) !== null),
            Actions\DeleteAction::make()->label('Usuń'),
        ];
    }
}
