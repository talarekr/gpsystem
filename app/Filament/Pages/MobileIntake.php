<?php

namespace App\Filament\Pages;

class MobileIntake extends OperationalPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel = 'Przyjęcie mobilne';
    protected static ?string $title = 'Przyjęcie mobilne';

    public function getPlaceholderDescription(): string
    {
        return 'Wcześniejszy placeholder Mobile Intake został zmapowany do sekcji Części. Workflow przyjęcia mobilnego nie jest wdrożony.';
    }
}
