<?php

namespace App\Filament\Resources\EmailTemplateResource\Pages;

use App\Filament\Resources\EmailTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getFormActions(): array
    {
        return [
            Actions\Action::make('save')
                ->label('Zapisz szablon')
                ->submit('save'),
            Actions\Action::make('cancel')
                ->label('Wróć do listy')
                ->url(EmailTemplateResource::getUrl('index'))
                ->color('gray'),
        ];
    }
}
