<?php

namespace App\Filament\Pages;

class Vehicles extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Samochody';
    protected static ?string $title = 'Samochody';
    protected static ?int $navigationSort = 30;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłą obsługę samochodów i danych pojazdów powiązanych z częściami. Funkcjonalność pojazdów nie jest jeszcze wdrożona.';
    }
}
