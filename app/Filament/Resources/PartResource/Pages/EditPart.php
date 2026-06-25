<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Models\PartCategory;
use App\Services\Images\PartImagePresentationService;
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
        $data['part_photo_paths'] = $this->record->images()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('path')
            ->filter()
            ->values()
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->partPhotoPaths = $data['part_photo_paths'] ?? [];
        unset($data['part_photo_paths']);

        return $data;
    }

    protected function afterSave(): void
    {
        PartResource::syncPartImages($this->record, $this->partPhotoPaths);
    }

    public function setPartCategoryFromPicker(mixed $categoryId = null): void
    {
        if (blank($categoryId)) {
            Notification::make()
                ->title('Nie wybrano kategorii')
                ->body('Kliknij docelową kategorię w panelu, a następnie ponownie użyj przycisku „Ustaw kategorię”.')
                ->danger()
                ->send();

            return;
        }

        $category = PartCategory::query()->find($categoryId);

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

            return;
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
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('processPartImages')
                ->label('Przetwórz zdjęcia produktu')
                ->icon('heroicon-o-photo')
                ->action(function (): void {
                    $processed = 0;

                    foreach ($this->record->images as $image) {
                        if (! $image->path) {
                            continue;
                        }

                        $image->legacy_payload = app(PartImagePresentationService::class)->process($image, true);
                        $image->saveQuietly();
                        $processed++;
                    }

                    Notification::make()->title("Przetworzono zdjęcia: {$processed}")->success()->send();
                }),
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
            Actions\ViewAction::make()->label('Podgląd'),
            Actions\DeleteAction::make()->label('Usuń'),
        ];
    }
}
