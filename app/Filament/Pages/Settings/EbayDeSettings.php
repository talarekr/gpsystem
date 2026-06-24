<?php

namespace App\Filament\Pages\Settings;

class EbayDeSettings extends SettingsPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'eBay DE';
    protected static ?string $navigationGroup = 'Kanały sprzedaży';
    protected static ?string $title = 'eBay DE';
    protected static ?int $navigationSort = 30;

    public function getPlaceholderDescription(): string
    {
        return 'Placeholder kanału sprzedaży eBay DE. Tłumaczenia, mapowania i publikowanie marketplace nie są wdrożone.';
    }
}
