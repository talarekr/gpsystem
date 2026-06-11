<?php

namespace App\Filament\Pages\Settings;

class WooCommerceSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'WooCommerce Settings';
    protected static ?string $title = 'WooCommerce Settings';
    protected static ?int $navigationSort = 122;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for WooCommerce connection settings, mappings, and sync safety checks. No WooCommerce writes are implemented here.';
    }
}
