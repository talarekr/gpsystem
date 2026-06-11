<?php

namespace App\Filament\Pages;

class StagingItems extends OperationalPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel = 'Pozycje robocze';
    protected static ?string $title = 'Pozycje robocze';

    public function getPlaceholderDescription(): string
    {
        return 'Wcześniejszy placeholder Staging Items został zmapowany do sekcji Części. Workflow stagingu nie jest wdrożony.';
    }
}
