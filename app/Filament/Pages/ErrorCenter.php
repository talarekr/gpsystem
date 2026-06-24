<?php

namespace App\Filament\Pages;

class ErrorCenter extends OperationalPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';
    protected static ?string $navigationLabel = 'Zadania';
    protected static ?string $title = 'Zadania';
    protected static ?int $navigationSort = 80;

    public function getPlaceholderDescription(): string
    {
        return 'Operacyjne miejsce na przyszłe zadania, błędy, kolejki uwagi i kontrole gotowości. Automatyzacja nie jest tu uruchamiana.';
    }

    public function getPlaceholderDetails(): array
    {
        return [
            'Mapuje wcześniejsze pozycje Error Center, Readiness oraz Automation / Queue Settings do spokojnej sekcji Zadania.',
            'Nie wykonuje retry, kolejek, automatyzacji ani reguł gotowości.',
            'Zewnętrzne zapisy i publikacje pozostają wyłączone.',
        ];
    }
}
