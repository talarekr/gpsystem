<?php

namespace App\Filament\Pages\Settings;

class ProductSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationLabel = 'Ustawienia części';
    protected static ?string $title = 'Ustawienia części';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe domyślne ustawienia części, oczekiwania jakości danych i konfigurację katalogu. Workflow katalogu nie jest wdrożony.';
    }
}
