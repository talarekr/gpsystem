<?php

namespace App\Filament\Pages\Settings;

class InternalLogisticsClasses extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Klasy logistyczne';
    protected static ?string $title = 'Klasy logistyczne';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe wewnętrzne klasy logistyczne, takie jak małe, średnie, duże, ponadgabarytowe i paletowe. To osobny obszar od grup wysyłki kanałów.';
    }
}
