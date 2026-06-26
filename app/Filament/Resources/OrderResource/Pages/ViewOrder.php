<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Services\Admin\LocalOrderStatusUpdater;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected static string $view = 'filament.resources.orders.pages.view-order';


    public function updateOrderStatus(string $status, LocalOrderStatusUpdater $updater): void
    {
        try {
            $this->record = $updater->update($this->record, $status);

            Notification::make()
                ->title('Status zamówienia został zapisany lokalnie.')
                ->success()
                ->send();
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Nie zapisano statusu')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make()->label('Zmień status')];
    }
}
