<?php

namespace App\Filament\Pages\Settings;

class EbayDeSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-language';
    protected static ?string $navigationLabel = 'eBay DE Settings';
    protected static ?string $title = 'eBay DE Settings';
    protected static ?int $navigationSort = 124;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for eBay Germany marketplace rules, translations, mappings, and readiness requirements. No marketplace publishing is implemented here.';
    }
}
