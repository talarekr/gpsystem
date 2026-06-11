<?php

namespace App\Filament\Pages;

class Orders extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Zamówienia';
    protected static ?string $title = 'Zamówienia';
    protected static ?int $navigationSort = 60;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłą obsługę zamówień. Realna logika zamówień nie jest jeszcze wdrożona.';
    }
}
