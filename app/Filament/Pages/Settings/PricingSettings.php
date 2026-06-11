<?php

namespace App\Filament\Pages\Settings;

class PricingSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Pricing Settings';
    protected static ?string $title = 'Pricing Settings';
    protected static ?int $navigationSort = 118;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for pricing rules, margins, rounding, and price review policies. No pricing execution is implemented here.';
    }
}
