<?php

namespace App\Filament\Pages\Settings;

class EbayFrSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-language';
    protected static ?string $navigationLabel = 'eBay FR Settings';
    protected static ?string $title = 'eBay FR Settings';
    protected static ?int $navigationSort = 125;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for eBay France marketplace rules, translations, mappings, and readiness requirements. No marketplace publishing is implemented here.';
    }
}
