<?php

namespace App\Filament\Resources\CarResource\Pages;

use App\Filament\Resources\CarResource;
use App\Http\Controllers\Tools\OvokoImportCarController;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Http\Request;

class EditCar extends EditRecord
{
    protected static string $resource = CarResource::class;

    protected static ?string $title = 'Edytuj samochód';

    private const MODAL_SIMPLIFIED_MARKER = 'car_form_send_to_ovoko_modal_simplified_v2';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existingLegacyPayload = is_array($this->record->legacy_payload) ? $this->record->legacy_payload : [];

        return CarResource::normalizeOvokoLocalMappingData($data, $existingLegacyPayload);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Usuń samochód'),
        ];
    }

    /**
     * @return array<\Filament\Actions\Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getSendToOvokoFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()->label('Zapisz samochód');
    }

    protected function getSendToOvokoFormAction(): Actions\Action
    {
        return Actions\Action::make('sendToOvoko')
            ->label('Wyślij samochód do Ovoko')
            ->color('warning')
            ->icon('heroicon-o-cloud-arrow-up')
            ->extraAttributes(['data-marker' => self::MODAL_SIMPLIFIED_MARKER])
            ->visible(fn (): bool => blank(data_get($this->record->legacy_payload, 'ovoko_car_id')))
            ->requiresConfirmation()
            ->modalHeading('Wyślij samochód do Ovoko')
            ->modalSubmitActionLabel('Wyślij samochód do Ovoko')
            ->modalCancelActionLabel('Anuluj')
            ->action(function (OvokoCarDictionaryService $dictionaryService, MarketplaceApiManager $apiManager): void {
                $readiness = $dictionaryService->readiness($this->record->refresh());

                if (filled($readiness['ovoko_car_id'] ?? null)) {
                    Notification::make()
                        ->title('Samochód ma już ovoko_car_id')
                        ->body('Ponowna wysyłka do Ovoko jest zablokowana.')
                        ->warning()
                        ->send();

                    return;
                }

                if (! (bool) ($readiness['ready_for_future_import_car'] ?? false)) {
                    Notification::make()
                        ->title('Samochód nie jest gotowy do wysyłki do Ovoko')
                        ->body('Brakujące pola: '.implode(', ', (array) ($readiness['missing_fields_for_future_import_car'] ?? [])))
                        ->danger()
                        ->send();

                    return;
                }

                $request = Request::create(route('admin.tools.ovoko.import-car'), 'POST', [
                    'car_id' => $this->record->getKey(),
                    'confirm' => OvokoImportCarController::CONFIRM,
                ]);

                /** @var \Illuminate\Http\JsonResponse $response */
                $response = app(OvokoImportCarController::class)->__invoke($request, $dictionaryService, $apiManager);
                $result = (array) $response->getData(true);

                if ($response->isSuccessful() && (bool) ($result['ok'] ?? false)) {
                    $this->record->refresh();

                    Notification::make()
                        ->title('Samochód wysłany do Ovoko')
                        ->body('ovoko_car_id: '.($result['ovoko_car_id'] ?? 'brak'))
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Nie udało się wysłać samochodu do Ovoko')
                    ->body($this->formatOvokoImportFailure($result))
                    ->danger()
                    ->send();
            });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function formatOvokoImportFailure(array $result): string
    {
        $parts = array_filter([
            $result['reason'] ?? null,
            isset($result['missing_fields_for_future_import_car']) ? 'Brakujące pola: '.implode(', ', (array) $result['missing_fields_for_future_import_car']) : null,
            $result['message'] ?? null,
            isset($result['ovoko_car_id']) ? 'ovoko_car_id: '.$result['ovoko_car_id'] : null,
        ]);

        return $parts === [] ? 'Nieznany błąd wysyłki do Ovoko.' : implode("\n", $parts);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Samochód został zapisany';
    }
}
