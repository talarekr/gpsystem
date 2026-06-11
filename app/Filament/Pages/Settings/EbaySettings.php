<?php

namespace App\Filament\Pages\Settings;

class EbaySettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-europe-africa';
    protected static ?string $navigationLabel = 'eBay Settings';
    protected static ?string $title = 'eBay Settings';
    protected static ?int $navigationSort = 123;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for global eBay configuration, including channel-specific shipping mapping preparation. No eBay publishing is implemented here.';
    }
}
