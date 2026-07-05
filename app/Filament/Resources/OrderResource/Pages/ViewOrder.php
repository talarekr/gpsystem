<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\MarketplaceSyncLog;
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
            $result = $updater->updateWithSyncResult($this->record, $status);
            $this->record = $result['order'];

            $this->sendStatusUpdateNotification($result['sync_log'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Nie zapisano statusu')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private function sendStatusUpdateNotification(?MarketplaceSyncLog $syncLog): void
    {
        if ($syncLog === null) {
            Notification::make()
                ->title('Status lokalny bez zmian.')
                ->body('Marketplace sync: nie uruchomiono, bo status lokalny się nie zmienił.')
                ->success()
                ->send();

            return;
        }

        $level = $syncLog->status === 'error' ? 'danger' : 'success';
        $statusLabel = match ($syncLog->status) {
            'success' => 'success',
            'skipped' => 'skipped',
            'error' => 'error',
            default => (string) $syncLog->status,
        };

        $notification = Notification::make()
            ->title('Status lokalny zmieniony. Marketplace sync: '.$statusLabel.'.')
            ->body('Szczegóły zapisano w logu API #'.$syncLog->id.'.'.($syncLog->message ? ' Powód: '.$syncLog->message.'.' : ''));

        $notification->{$level}()->send();
    }

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make()->label('Zmień status')];
    }
}
