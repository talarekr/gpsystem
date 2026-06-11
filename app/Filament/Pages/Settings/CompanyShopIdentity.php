<?php

namespace App\Filament\Pages\Settings;

class CompanyShopIdentity extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Company / Shop Identity';
    protected static ?string $title = 'Company / Shop Identity';
    protected static ?int $navigationSort = 112;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for shop name, legal identity, contact details, branding references, and administrative identity settings.';
    }
}
