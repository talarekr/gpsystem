<?php

namespace App\Filament\Pages;

class Shipments extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationLabel = 'Przesyłki';
    protected static ?string $title = 'Przesyłki';
    protected static ?int $navigationSort = 70;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłą obsługę przesyłek i pracy wysyłkowej. Logika przesyłek ani integracje kurierskie nie są jeszcze wdrożone.';
    }
}
