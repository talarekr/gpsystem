<?php

namespace App\Filament\Pages\Settings;

class Categories extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Kategorie';
    protected static ?string $title = 'Kategorie';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe struktury kategorii, zarządzanie kategoriami i przygotowanie mapowań. Logika kategorii nie jest wdrożona.';
    }
}
