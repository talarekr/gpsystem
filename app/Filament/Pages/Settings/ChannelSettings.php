<?php

namespace App\Filament\Pages\Settings;

class ChannelSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Ustawienia kanałów';
    protected static ?string $title = 'Ustawienia kanałów';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe ogólne ustawienia kanałów sprzedaży, bramki gotowości i kontrolę bezpieczeństwa integracji. Ryzykowne zapisy pozostają wyłączone.';
    }
}
