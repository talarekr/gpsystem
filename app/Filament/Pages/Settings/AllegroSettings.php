<?php

namespace App\Filament\Pages\Settings;

class AllegroSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Allegro Settings';
    protected static ?string $title = 'Allegro Settings';
    protected static ?int $navigationSort = 126;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for Allegro mapping, readiness, and integration settings. No Allegro integration or publishing is implemented here.';
    }
}
