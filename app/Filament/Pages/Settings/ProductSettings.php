<?php

namespace App\Filament\Pages\Settings;

class ProductSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationLabel = 'Product Settings';
    protected static ?string $title = 'Product Settings';
    protected static ?int $navigationSort = 114;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for product defaults, data quality expectations, and catalog-level settings. No catalog workflow is implemented here.';
    }
}
