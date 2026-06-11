<?php

namespace App\Filament\Pages;

class StockLocations extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Magazynowanie';
    protected static ?string $title = 'Magazynowanie';
    protected static ?int $navigationSort = 50;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe widoki stanów, lokalizacji i pracy magazynowej. Logika stanów magazynowych nie jest jeszcze wdrożona.';
    }
}
