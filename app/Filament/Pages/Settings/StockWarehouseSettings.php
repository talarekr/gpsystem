<?php

namespace App\Filament\Pages\Settings;

class StockWarehouseSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-home-modern';
    protected static ?string $navigationLabel = 'Ustawienia magazynu';
    protected static ?string $title = 'Ustawienia magazynu';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe lokalizacje, domyślne ustawienia magazynu, kontrole stanów i reguły operacyjne. Logika stanów nie jest wdrożona.';
    }
}
