<?php

namespace App\Filament\Pages\Settings;

class AttributesParameters extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationLabel = 'Attributes / Parameters';
    protected static ?string $title = 'Attributes / Parameters';
    protected static ?int $navigationSort = 117;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for product parameters, attribute definitions, required attributes, and channel mapping preparation.';
    }
}
