<?php

namespace App\Filament\Pages\Settings;

class Categories extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Categories';
    protected static ?string $title = 'Categories';
    protected static ?int $navigationSort = 116;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for category structures, category governance, and mapping preparation. No category logic is implemented here.';
    }
}
