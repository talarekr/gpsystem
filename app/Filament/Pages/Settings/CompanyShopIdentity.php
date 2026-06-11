<?php

namespace App\Filament\Pages\Settings;

class CompanyShopIdentity extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Firma i sklep';
    protected static ?string $title = 'Firma i sklep';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłą nazwę sklepu, dane firmowe, kontakt, odniesienia brandingowe i ustawienia administracyjne tożsamości.';
    }
}
