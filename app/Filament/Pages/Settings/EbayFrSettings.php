<?php

namespace App\Filament\Pages\Settings;

class EbayFrSettings extends SettingsPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = true;
    protected static ?string $navigationIcon = 'heroicon-o-language';
    protected static ?string $navigationLabel = 'eBay FR';
    protected static ?string $navigationGroup = 'Kanały sprzedaży';
    protected static ?string $title = 'eBay FR';
    protected static ?int $navigationSort = 40;

    public function getPlaceholderDescription(): string
    {
        return 'Placeholder kanału sprzedaży eBay FR. Tłumaczenia, mapowania i publikowanie marketplace nie są wdrożone.';
    }
}
