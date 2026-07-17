<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Support\Parts\AdditionalPartCodes;
use App\Models\MarketplaceCategory;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Services\Marketplace\AllegroCategoryResolver;
use App\Services\Marketplace\AllegroManualParameterSelectionService;
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

    protected array $marketplaceCategorySelections = [];

    protected array $allegroManualParameterValues = [];

    protected array $allegroDynamicParameterDefinitionsForSync = [];

    protected array $allegroDynamicParameterRepeaterStateForSync = [];

    protected array $pendingAllegroDynamicParameters = [];

    public bool $marketplacePublishInProgress = false;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (PartResource::isMissingAdminDefaultValue($data['condition_notes'] ?? null)) {
            $data['condition_notes'] = PartResource::DEFAULT_CONDITION_VALUE;
        }

        $data['part_position'] = PartResource::partPositionFormValue($this->record);

        $currentSteering = $this->record->vehicle_snapshot['steering_side'] ?? null;
        $data[PartResource::ADMIN_STEERING_FORM_STATE] = PartResource::isMissingAdminDefaultValue($currentSteering)
            ? PartResource::EXPECTED_LEFT_STEERING_VALUE
            : PartResource::adminSteeringFormValue($currentSteering);

        // Existing images are rendered by the edit gallery; keep FileUpload empty so it only adds new photos.
        $data['part_photo_paths'] = [];
        $data['marketplace_category_selections'] = [];
        $data[PartResource::ALLEGRO_DYNAMIC_PARAMETER_FIELDS] = PartResource::dynamicAllegroParameterDefinitions($this->record);
        $data[PartResource::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER] = PartResource::dynamicAllegroParameterItems($this->record, $data[PartResource::ALLEGRO_DYNAMIC_PARAMETER_FIELDS]);
        $data[PartResource::ALLEGRO_MANUAL_PARAMETERS_FIELD] = PartResource::savedAllegroManualParameterValues($this->record);
        $this->logAllegroDynamicHydrationState($data[PartResource::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER]);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->partPhotoPaths = array_values(array_filter((array) ($data['part_photo_paths'] ?? []), fn (mixed $path): bool => filled($path)));
        $this->marketplaceCategorySelections = (array) ($data['marketplace_category_selections'] ?? []);

        $this->captureAllegroDynamicParameterSaveState($data);
        unset($data['part_photo_paths'], $data['marketplace_category_selections'], $data[PartResource::ALLEGRO_MANUAL_PARAMETERS_FIELD], $data[PartResource::ALLEGRO_DYNAMIC_PARAMETER_FIELDS], $data[PartResource::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER]);

        $data['additional_part_codes'] = AdditionalPartCodes::normalize($data['additional_part_codes'] ?? null, $data['part_number'] ?? null);
        $data['condition_notes'] = PartResource::defaultConditionValue($data['condition_notes'] ?? null);
        $data = PartResource::applyPartPositionFormStateToData($data, $this->record);
        $data = PartResource::applyAdminSteeringFormStateToData($data, $this->record);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->persistMarketplaceCategorySelections();
        $this->syncAllegroManualParameterSelections();

        if ($this->partPhotoPaths !== []) {
            $this->record->load('images');

            PartResource::syncPartImages($this->record, array_merge(
                PartResource::partImagePaths($this->record),
                $this->partPhotoPaths,
            ));

            $this->record->refresh();
            $this->record->load('images');
            $this->data['part_photo_paths'] = [];
        }
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

    public function setMarketplaceCategoryFromPicker(string $channel, mixed $categoryId = null, ?string $categoryName = null, ?string $categoryPath = null): bool
    {
        if (! in_array($channel, ['allegro_main', 'ovoko', 'ebay_de', 'ebay_fr'], true) || blank($categoryId)) {
            Notification::make()->title('Nie wybrano kategorii marketplace')->danger()->send();

            return false;
        }

        $category = MarketplaceCategory::query()
            ->where('channel', $channel)
            ->where('external_category_id', (string) $categoryId)
            ->first();

        $overrideKey = $this->marketplaceCategoryOverrideKey($channel);
        $selection = [
            'channel' => $channel,
            'external_category_id' => (string) ($category?->external_category_id ?? $categoryId),
            'external_category_name' => $category?->name ?: $categoryName,
            'external_category_path' => $category?->full_path ?: $categoryPath ?: $categoryName,
            'source' => 'manual_part_edit_marketplace_preparation',
        ];

        $this->data['marketplace_category_selections'] = array_replace(
            (array) ($this->data['marketplace_category_selections'] ?? []),
            [$overrideKey => $selection],
        );

        Notification::make()
            ->title('Kategoria marketplace została wybrana')
            ->body('Zmiana zostanie zapisana dopiero po użyciu głównego przycisku „Zapisz”.')
            ->success()
            ->send();

        return true;
    }

    private function persistMarketplaceCategorySelections(): void
    {
        $selections = array_filter($this->marketplaceCategorySelections, fn (mixed $selection): bool => is_array($selection) && filled($selection['external_category_id'] ?? null));

        if ($selections === []) {
            return;
        }

        $metadata = (array) ($this->record->review_metadata ?: []);
        $metadata['marketplace_category_overrides'] ??= [];

        foreach ($selections as $key => $selection) {
            if (! in_array($key, ['allegro', 'ovoko', 'ebay'], true)) {
                continue;
            }

            $metadata['marketplace_category_overrides'][$key] = [
                'channel' => (string) ($selection['channel'] ?? match ($key) {
                    'allegro' => 'allegro_main',
                    'ovoko' => 'ovoko',
                    'ebay' => 'ebay_de',
                }),
                'external_category_id' => (string) $selection['external_category_id'],
                'external_category_name' => $selection['external_category_name'] ?? null,
                'external_category_path' => $selection['external_category_path'] ?? null,
                'source' => 'manual_part_edit_marketplace_preparation',
                'selected_at' => now()->toISOString(),
            ];
        }

        $this->record->forceFill(['review_metadata' => $metadata])->save();
        $this->record->refresh();
        $this->data['marketplace_category_selections'] = [];
        $this->marketplaceCategorySelections = [];
    }

    private function marketplaceCategoryOverrideKey(string $channel): string
    {
        return match ($channel) {
            'allegro_main' => 'allegro',
            'ovoko' => 'ovoko',
            'ebay_de', 'ebay_fr' => 'ebay',
            default => $channel,
        };
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
        $this->unmountFormComponentAction(true, true);
        $this->dispatch('close-modal', id: 'form-component-action');

        Notification::make()
            ->title('Kategoria części została wybrana')
            ->body('Nowa kategoria zostanie zapisana dopiero po użyciu głównego przycisku „Zapisz”.')
            ->success()
            ->send();

        return true;
    }



    public function applyAllegroDynamicParametersFromPrepare(?array $fields = null): void
    {
        $definitions = [];

        foreach (($fields ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $definition = PartResource::normalizeDynamicAllegroParameterField($field);

            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }

        $this->data[PartResource::ALLEGRO_DYNAMIC_PARAMETER_FIELDS] = $definitions;
        $this->data[PartResource::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER] = PartResource::dynamicAllegroParameterItems($this->record, $definitions);

        foreach ($definitions as $definition) {
            $parameterId = (string) $definition['id'];
            $savedValueIds = array_values((array) ($definition['saved_value_ids'] ?? []));
            $this->data[PartResource::ALLEGRO_MANUAL_PARAMETERS_FIELD][$parameterId] = ($definition['multiple_choices'] ?? false)
                ? $savedValueIds
                : ($savedValueIds[0] ?? null);
        }
    }

    private function captureAllegroDynamicParameterSaveState(array $data): void
    {
        $dataState = (array) ($data[PartResource::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER] ?? []);
        $livewireState = (array) data_get($this->data, PartResource::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER, []);
        $formState = [];

        try {
            $formState = (array) data_get($this->form->getState(), PartResource::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER, []);
        } catch (\Throwable $exception) {
            Log::warning('Allegro dynamic parameter form state could not be read before save.', [
                'part_id' => (int) $this->record->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        $source = $this->bestAllegroDynamicParameterStateSource($dataState, $livewireState, $formState);
        $state = $source['state'];

        $this->allegroDynamicParameterRepeaterStateForSync = $state;
        $this->allegroDynamicParameterDefinitionsForSync = $this->dynamicAllegroParameterDefinitionsFromRepeater($state);
        $this->allegroManualParameterValues = PartResource::mapDynamicAllegroParameterItemsToManualValues($state);
        $this->pendingAllegroDynamicParameters = $state;

        $this->logAllegroDynamicSaveStateCaptured($dataState, $livewireState, $formState, $source['name']);
    }

    private function bestAllegroDynamicParameterStateSource(array $dataState, array $livewireState, array $formState): array
    {
        foreach ([
            'form_get_state' => $formState,
            'data_argument' => $dataState,
            'livewire_data' => $livewireState,
        ] as $name => $state) {
            if ($this->containsSelectedValueIds($state)) {
                return ['name' => $name, 'state' => $state];
            }
        }

        foreach ([
            'form_get_state' => $formState,
            'data_argument' => $dataState,
            'livewire_data' => $livewireState,
        ] as $name => $state) {
            if ($state !== []) {
                return ['name' => $name, 'state' => $state];
            }
        }

        return ['name' => 'none', 'state' => []];
    }

    private function containsSelectedValueIds(array $state): bool
    {
        foreach ($state as $item) {
            if (is_array($item) && array_key_exists('selected_value_ids', $item)) {
                return true;
            }
        }

        return false;
    }

    private function syncAllegroManualParameterSelections(): void
    {
        $resolved = app(AllegroCategoryResolver::class)->resolve($this->record, data_get($this->marketplaceCategorySelections, 'allegro.external_category_id'));
        $categoryId = (string) ($resolved['id'] ?? '');
        if ($categoryId === '' || $this->pendingAllegroDynamicParameters === []) return;

        $this->logAllegroDynamicSyncEvent('allegro_dynamic_sync_started', $categoryId, $this->pendingAllegroDynamicParameters);

        foreach ($this->pendingAllegroDynamicParameters as $item) {
            if (! is_array($item)) continue;

            $parameterId = (string) ($item['parameter_id'] ?? '');
            if ($parameterId === '' || ! array_key_exists('selected_value_ids', $item)) {
                continue;
            }

            $definition = [
                'id' => $parameterId,
                'name' => (string) ($item['parameter_name'] ?? $parameterId),
                'dictionary' => array_map(fn (array $row): array => ['id' => $row['id'] ?? '', 'value' => $row['label'] ?? $row['value'] ?? ''], (array) ($item['official_values'] ?? [])),
            ];
            $rawValue = $item['selected_value_ids'];
            $valueIds = filter_var($item['multiple_choices'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ? (array) $rawValue
                : ($rawValue === null || $rawValue === '' ? [] : [$rawValue]);

            app(AllegroManualParameterSelectionService::class)->sync(
                $this->record,
                $categoryId,
                $definition,
                $valueIds,
                PartResource::ALLEGRO_DYNAMIC_PARAMETERS_REPEATER.'.'.$parameterId.'.selected_value_ids',
            );
        }

        $persistedRows = $this->record->allegroParameterSelections()
            ->where('allegro_category_id', $categoryId)
            ->orderBy('parameter_id')
            ->orderBy('value_id')
            ->get(['part_id', 'allegro_category_id', 'parameter_id', 'parameter_name', 'value_id', 'value_label'])
            ->map(fn ($row): array => $row->toArray())
            ->values()
            ->all();

        $this->logAllegroDynamicSyncEvent('allegro_dynamic_sync_completed', $categoryId, $this->pendingAllegroDynamicParameters, ['persisted_rows' => $persistedRows]);
        $this->pendingAllegroDynamicParameters = [];
    }

    private function dynamicAllegroParameterDefinitionsFromRepeater(array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'id' => (string) ($item['parameter_id'] ?? ''),
                'name' => (string) ($item['parameter_name'] ?? $item['parameter_id'] ?? ''),
                'multiple_choices' => filter_var($item['multiple_choices'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'dictionary' => array_values((array) ($item['official_values'] ?? [])),
            ])
            ->filter(fn (array $item): bool => $item['id'] !== '' && $item['dictionary'] !== [])
            ->values()
            ->all();
    }

    private function logAllegroDynamicSaveStateCaptured(array $dataState, array $livewireState, array $formState, string $selectedSource): void
    {
        Log::info('allegro_dynamic_save_state_captured', [
            'part_id' => (int) $this->record->getKey(),
            'record_id' => (int) $this->record->getKey(),
            'selected_source' => $selectedSource,
            'sources' => [
                'data_argument' => $this->summarizeAllegroDynamicState($dataState),
                'livewire_data' => $this->summarizeAllegroDynamicState($livewireState),
                'form_get_state' => $this->summarizeAllegroDynamicState($formState),
            ],
            'repeater_state' => $this->summarizeAllegroDynamicState($this->pendingAllegroDynamicParameters),
            'mapped_dynamic_values' => $this->allegroManualParameterValues,
        ]);
    }

    private function logAllegroDynamicSyncEvent(string $event, string $categoryId, array $state, array $extra = []): void
    {
        Log::info($event, array_merge([
            'part_id' => (int) $this->record->getKey(),
            'category_id' => $categoryId,
            'parameters' => $this->summarizeAllegroDynamicState($state),
        ], $extra));
    }

    private function summarizeAllegroDynamicState(array $state): array
    {
        return collect($state)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'parameter_id' => (string) ($item['parameter_id'] ?? ''),
                'parameter_name' => (string) ($item['parameter_name'] ?? ''),
                'selected_value_ids_present' => array_key_exists('selected_value_ids', $item),
                'selected_value_ids' => array_values((array) ($item['selected_value_ids'] ?? [])),
                'official_values_count' => count((array) ($item['official_values'] ?? [])),
            ])
            ->values()
            ->all();
    }


    private function logAllegroDynamicHydrationState(array $repeaterState): void
    {
        $categoryId = PartResource::currentAllegroCategoryIdForDiagnostics($this->record);

        Log::info('allegro_dynamic_repeater_hydrated', [
            'part_id' => (int) $this->record->getKey(),
            'category_id' => $categoryId,
            'saved_rows' => $categoryId === '' ? [] : $this->record->allegroParameterSelections()
                ->where('allegro_category_id', $categoryId)
                ->orderBy('parameter_id')
                ->orderBy('value_id')
                ->get(['part_id', 'allegro_category_id', 'parameter_id', 'parameter_name', 'value_id', 'value_label'])
                ->map(fn ($row): array => $row->toArray())
                ->values()
                ->all(),
            'repeater_after_fill' => $this->summarizeAllegroDynamicState($repeaterState),
        ]);
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
            'marketplace_api_error' => 'Szczegóły są w Logach.',
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
            $this->getSaveAndPublishAction('markListingReadyHeader'),
            Actions\Action::make('saveHeader')
                ->label('Zapisz')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->extraAttributes(['class' => 'gps-part-edit-layout-action gps-part-edit-layout-action--save'])
                ->action(fn (): mixed => $this->save(false, false)),
            Actions\DeleteAction::make()
                ->label('Usuń')
                ->extraAttributes(['class' => 'gps-part-edit-layout-action gps-part-edit-layout-action--delete']),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveAndPublishAction('markListingReadyFooter'),
            $this->getSaveFormAction()
                ->extraAttributes(['class' => 'gps-part-edit-footer-action gps-part-edit-layout-action--save']),
            $this->getCancelFormAction()
                ->extraAttributes(['class' => 'gps-part-edit-footer-action gps-part-edit-layout-action--cancel']),
        ];
    }

    public function publishMarketplaceChannel(string $channel, PublishPartToMarketplacesService $publishService): void
    {
        if (! in_array($channel, PublishPartToMarketplacesService::CHANNELS, true)) {
            Notification::make()->title('Nieobsługiwany kanał sprzedaży.')->danger()->send();
            return;
        }

        $this->publishMarketplaceChannels($publishService, [$channel], $channel);
    }

    private function publishMarketplaceChannels(PublishPartToMarketplacesService $publishService, array|string $channels, ?string $singleChannel = null): void
    {
        if ($this->marketplacePublishInProgress) {
            Notification::make()->title('Wystawianie marketplace już trwa.')->body('Poczekaj na zakończenie aktualnej operacji; drugi request nie został wysłany.')->warning()->send();
            return;
        }

        $this->marketplacePublishInProgress = true;

        try {
        $this->save(false, false);
        $this->record->refresh();

        $enabled = (bool) config('marketplace.publish_enabled', false);
        $result = $enabled
            ? $publishService->confirm($this->record, $channels, dryRun: false, confirm: true)
            : $publishService->preview($this->record, $channels, includePayload: true);

        $published = $result['published_channels'] ?? $result['ready_channels'] ?? [];
        $skipped = $result['skipped_channels'] ?? [];
        $messages = collect($result['channels'] ?? [])->flatMap(fn (array $channel): array => $channel['errors'] ?? $channel['readiness']['blockers'] ?? [])->map(fn (mixed $message): string => $this->marketplacePublishMessage((string) $message))->filter()->values()->all();
        $channelLabel = $singleChannel ? match ($singleChannel) {
            'allegro_main', 'allegro' => 'Allegro',
            'ebay_de', 'ebay' => 'eBay',
            'ovoko', 'ovoko_main' => 'Ovoko',
            default => ucfirst($singleChannel),
        } : null;

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
                ->title($singleChannel ? 'Część zapisana. Wystawiono kanał '.$channelLabel.'.' : 'Część zapisana. Wystawiono gotowe kanały, a kanały z brakami pominięto.')
                ->body('Wystawione/przygotowane: '.implode(', ', $published).'. Pominięte: '.implode(', ', array_keys($skipped)).'. Powody: '.($messages === [] ? 'readiness wymaga uzupełnienia.' : implode(' | ', $messages)))
                ->warning()
                ->send();
            return;
        }

        if (($result['blocked'] ?? false) || $published === []) {
            Notification::make()
                ->title($singleChannel ? 'Nie udało się wystawić kanału '.$channelLabel.'.' : 'Nie udało się wystawić części.')
                ->body($messages === [] ? 'Readiness wymaga uzupełnienia.' : implode(' | ', $messages))
                ->danger()
                ->send();
            return;
        }

        Notification::make()
            ->title($singleChannel ? 'Część zapisana i wystawiona w kanale '.$channelLabel.'.' : 'Część zapisana i wystawiona w gotowych kanałach.')
            ->success()
            ->send();

        return;
        } finally {
            $this->marketplacePublishInProgress = false;
        }
    }

    private function getSaveAndPublishAction(string $name): Actions\Action
    {
        return Actions\Action::make($name)
            ->label('Zapisz i wystaw')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => (bool) $this->record->needs_listing)
            ->extraAttributes([
                'class' => 'gps-part-edit-layout-action gps-part-edit-layout-action--publish' . (str_ends_with($name, 'Footer') ? ' gps-part-edit-footer-action' : ''),
                'wire:loading.attr' => 'disabled',
                'wire:target' => $name,
            ])
            ->disabled(fn (): bool => $this->marketplacePublishInProgress)
            ->action(function (PublishPartToMarketplacesService $publishService): void {
                $this->publishMarketplaceChannels($publishService, 'all');
            });
    }
}
