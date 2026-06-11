<?php

namespace App\Filament\Pages;

class Readiness extends OperationalPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel = 'Gotowość';
    protected static ?string $title = 'Gotowość';

    public function getPlaceholderDescription(): string
    {
        return 'Wcześniejszy placeholder Readiness został zmapowany do sekcji Zadania. Reguły gotowości nie są wykonywane.';
    }
}
