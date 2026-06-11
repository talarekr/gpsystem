<?php

namespace App\Filament\Pages\Settings;

class StockWarehouseSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-home-modern';
    protected static ?string $navigationLabel = 'Stock / Warehouse Settings';
    protected static ?string $title = 'Stock / Warehouse Settings';
    protected static ?int $navigationSort = 119;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for stock locations, warehouse defaults, inventory controls, and operational stock rules. No stock execution is implemented here.';
    }
}
