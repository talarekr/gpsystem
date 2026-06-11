<?php

namespace App\Filament\Pages;

class Pricing extends OperationalPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel = 'Cenniki';
    protected static ?string $title = 'Cenniki';

    public function getPlaceholderDescription(): string
    {
        return 'Wcześniejszy placeholder Pricing pozostaje ukrytym ekranem przygotowanym pod przyszłe ustawienia i przeglądy cen. Logika cenowa nie jest wdrożona.';
    }
}
