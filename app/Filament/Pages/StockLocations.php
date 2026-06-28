<?php

namespace App\Filament\Pages;

class StockLocations extends OperationalPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Magazyn';
    protected static ?string $title = 'Magazyn';
    protected static ?int $navigationSort = 50;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe widoki stanów, lokalizacji i pracy magazynowej. Logika stanów magazynowych nie jest jeszcze wdrożona.';
    }
}
