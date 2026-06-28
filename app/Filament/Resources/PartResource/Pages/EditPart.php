<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Services\Marketplace\PreparePartMarketplaceListingService;
use App\Services\Marketplace\PublishPartToMarketplacesService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public function movePartImage(int $imageId, string $direction): void
    {
        $images = $this->record->images()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $currentIndex = $images->search(fn (PartImage $image): bool => (int) $image->getKey() === $imageId);

        if ($currentIndex === false) {
            return;
        }

        $targetIndex = $direction === 'left' ? $currentIndex - 1 : $currentIndex + 1;

        if (! $images->has($targetIndex)) {
            return;
        }

        $ordered = $images->values()->all();
        [$moved] = array_splice($ordered, $currentIndex, 1);
        array_splice($ordered, $targetIndex, 0, [$moved]);

        $this->persistPartImageOrder(collect($ordered)->pluck('id')->all());

        $this->record->refresh();
        $this->record->load('images');

        Notification::make()
            ->title('Kolejność zdjęć została zapisana')
            ->success()
            ->send();
    }

    /**
     * @param  array<int, mixed>  $orderedImageIds
     */
    public function reorderPartImages(array $orderedImageIds): void
    {
        $orderedImageIds = array_values(array_map('intval', $orderedImageIds));
        $requestedOrder = $orderedImageIds;

        if ($orderedImageIds === []) {
            return;
        }

        $currentImageIds = $this->record->images()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        sort($orderedImageIds);
        $sortedCurrentImageIds = $currentImageIds;
        sort($sortedCurrentImageIds);

        if ($orderedImageIds !== $sortedCurrentImageIds) {
            Notification::make()
                ->title('Nie zapisano kolejności zdjęć')
                ->body('Lista zdjęć jest nieaktualna. Odśwież stronę i spróbuj ponownie.')
                ->danger()
                ->send();

            return;
        }

        $this->persistPartImageOrder($requestedOrder);

        $this->record->refresh();
        $this->record->load('images');

        Notification::make()
            ->title('Kolejność zdjęć została zapisana')
            ->success()
            ->send();
    }

    /**
     * @param  array<int, int>  $orderedImageIds
     */
    private function persistPartImageOrder(array $orderedImageIds): void
    {
        DB::transaction(function () use ($orderedImageIds): void {
            foreach (array_values($orderedImageIds) as $index => $imageId) {
                PartImage::query()
                    ->where('part_id', $this->record->getKey())
                    ->whereKey($imageId)
                    ->update([
                        'sort_order' => $index,
                        'is_primary' => $index === 0,
                    ]);
            }
        });
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

        $this->data['category_id'] = $category->getKey();
        $this->data['marketplace_category_mappings_state'] = app(\App\Services\PartCategorySuggestionService::class)->marketplaceMappingsForCategory($category->getKey());
        $this->unmountFormComponentAction(false, false);
        $this->dispatch('close-modal', id: 'form-component-action');

        Notification::make()
            ->title('Kategoria części została wybrana')
            ->body('Nowa kategoria zostanie zapisana dopiero po użyciu głównego przycisku „Zapisz”.')
            ->success()
            ->send();

        return true;
    }

    private function marketplacePublishMessage(string $message): string
    {
        return match ($message) {
            'category_shipping_group' => 'Brak grupy wysyłkowej dla kategorii',
            'shipping_policy_mapping' => 'Brak mapowania polityki wysyłki',
            'payment_policy' => 'Brak polityki płatności',
            'return_policy' => 'Brak polityki zwrotów',
            'business_policies' => 'Brak ustawień polityk eBay',
            'allegro_required_category_parameters_missing' => 'Brakuje wymaganych parametrów Allegro',
            'prepared_translations' => 'Brak przygotowanego tłumaczenia eBay DE',
            'publish_not_confirmed_or_disabled', 'marketplace_publish_disabled_or_not_confirmed' => 'Wystawianie marketplace jest wyłączone albo niepotwierdzone',
            default => str_replace('_', ' ', $message),
        };
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

                    $published = $result['published_channels'] ?? $result['ready_channels'] ?? [];
                    $skipped = $result['skipped_channels'] ?? [];
                    $messages = collect($result['channels'] ?? [])->flatMap(fn (array $channel): array => $channel['errors'] ?? $channel['readiness']['blockers'] ?? [])->map(fn (mixed $message): string => $this->marketplacePublishMessage((string) $message))->filter()->values()->all();

                    if (! $enabled) {
                        Notification::make()
                            ->title('Realne wystawianie marketplace jest wyłączone — wykonano tylko preview.')
                            ->body($messages === [] ? 'MARKETPLACE_PUBLISH_ENABLED=false. Nie wykonano żadnego zapisu do marketplace.' : implode(' | ', $messages))
                            ->danger()
                            ->send();
                        return;
                    }

                    if ($published !== [] && $skipped !== []) {
                        Notification::make()
                            ->title('Część zapisana. Wystawiono gotowe kanały, a kanały z brakami pominięto.')
                            ->body('Wystawione/przygotowane: '.implode(', ', $published).'. Pominięte: '.implode(', ', array_keys($skipped)).'. Powody: '.($messages === [] ? 'readiness wymaga uzupełnienia.' : implode(' | ', $messages)))
                            ->warning()
                            ->send();
                        return;
                    }

                    if (($result['blocked'] ?? false) || $published === []) {
                        Notification::make()
                            ->title('Nie udało się wystawić części.')
                            ->body($messages === [] ? 'Readiness wymaga uzupełnienia.' : implode(' | ', $messages))
                            ->danger()
                            ->send();
                        return;
                    }

                    Notification::make()
                        ->title('Część zapisana i wystawiona w gotowych kanałach.')
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
