<?php

namespace App\Filament\Pages\Settings;

class AllegroSettings extends SettingsPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = true;
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Allegro';
    protected static ?string $navigationGroup = 'Kanały sprzedaży';
    protected static ?string $title = 'Allegro';
    protected static ?int $navigationSort = 10;

    public function getPlaceholderDescription(): string
    {
        return 'Placeholder kanału sprzedaży Allegro. Integracja, mapowanie i publikowanie ofert Allegro nie są wdrożone.';
    }
}
