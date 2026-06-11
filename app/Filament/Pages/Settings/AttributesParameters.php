<?php

namespace App\Filament\Pages\Settings;

class AttributesParameters extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationLabel = 'Atrybuty i parametry';
    protected static ?string $title = 'Atrybuty i parametry';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe definicje parametrów, wymagane atrybuty i przygotowanie mapowań kanałów.';
    }
}
