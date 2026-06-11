<?php

namespace App\Filament\Pages\Settings;

class OvokoSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $navigationLabel = 'Ovoko Settings';
    protected static ?string $title = 'Ovoko Settings';
    protected static ?int $navigationSort = 127;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for Ovoko-specific mappings, readiness, and integration safety settings. No Ovoko integration is implemented here.';
    }
}
