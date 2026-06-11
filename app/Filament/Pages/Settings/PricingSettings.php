<?php

namespace App\Filament\Pages\Settings;

class PricingSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Ustawienia cen';
    protected static ?string $title = 'Ustawienia cen';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe reguły cenowe, marże, zaokrąglenia i polityki przeglądu cen. Wykonywanie cen nie jest wdrożone.';
    }
}
