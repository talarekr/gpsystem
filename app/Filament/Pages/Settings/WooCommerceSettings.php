<?php

namespace App\Filament\Pages\Settings;

class WooCommerceSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Ustawienia WooCommerce';
    protected static ?string $title = 'Ustawienia WooCommerce';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe ustawienia połączenia WooCommerce, mapowania i kontrole bezpieczeństwa synchronizacji. Zapisy WooCommerce nie są wdrożone.';
    }
}
