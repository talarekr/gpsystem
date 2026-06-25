<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Models\PartCategory;
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
        $data[PartResource::ADMIN_STEERING_FORM_STATE] = PartResource::adminSteeringFormValue($this->record->vehicle_snapshot['steering_side'] ?? null);

        $data['part_photo_paths'] = PartResource::partImagePaths($this->record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->partPhotoPaths = array_key_exists('part_photo_paths', $data)
            ? $data['part_photo_paths']
            : PartResource::partImagePaths($this->record);
        unset($data['part_photo_paths']);

        return $data;
    }

    protected function afterSave(): void
    {
        PartResource::syncPartImages($this->record, $this->partPhotoPaths);
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

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markListingReady')
                ->label('Oznacz jako gotowe')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => (bool) $this->record->needs_listing)
                ->action(function (): void {
                    $this->record->update(['needs_listing' => false]);

                    Notification::make()
                        ->title('Część oznaczona jako gotowa')
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
