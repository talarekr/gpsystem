<?php

namespace App\Filament\Pages\Settings;

class EbaySettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-europe-africa';
    protected static ?string $navigationLabel = 'Ustawienia eBay';
    protected static ?string $title = 'Ustawienia eBay';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłą globalną konfigurację eBay, w tym przygotowanie mapowań wysyłki dla kanałów. Publikowanie eBay nie jest wdrożone.';
    }
}
