<?php

namespace App\Filament\Pages;

class AllegroIntegration extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-link';
    protected static ?string $navigationLabel = 'Integracja Allegro';
    protected static ?string $title = 'Integracja Allegro';
    protected static ?int $navigationSort = 90;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe operacyjne wejście do integracji Allegro. Na tym etapie nie ma połączenia z Allegro, synchronizacji ani publikacji ofert.';
    }
}
