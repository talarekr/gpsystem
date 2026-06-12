<?php

namespace App\Filament\Pages\Settings;

class OvokoSettings extends SettingsPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = true;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Ovoko';
    protected static ?string $navigationGroup = 'Kanały sprzedaży';
    protected static ?string $title = 'Ovoko';
    protected static ?int $navigationSort = 50;

    public function getPlaceholderDescription(): string
    {
        return 'Placeholder kanału sprzedaży Ovoko. Integracja Ovoko, synchronizacja i publikowanie nie są wdrożone.';
    }
}
