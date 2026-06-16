<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Services\Images\PartImagePresentationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPart extends EditRecord
{
    protected static string $resource = PartResource::class;

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
            Actions\ViewAction::make()->label('Podgląd'),
            Actions\DeleteAction::make()->label('Usuń'),
        ];
    }
}
