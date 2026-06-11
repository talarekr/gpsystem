<?php

namespace App\Filament\Pages\Settings;

class ProductIntakeSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';
    protected static ?string $navigationLabel = 'Product Intake Settings';
    protected static ?string $title = 'Product Intake Settings';
    protected static ?int $navigationSort = 115;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for intake defaults, operator guidance, photo expectations, and safe staging rules. No intake workflow is implemented here.';
    }
}
