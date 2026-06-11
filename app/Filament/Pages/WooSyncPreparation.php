<?php

namespace App\Filament\Pages;

class WooSyncPreparation extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'WooCommerce';
    protected static ?string $navigationGroup = 'Kanały sprzedaży';
    protected static ?string $title = 'WooCommerce';
    protected static ?int $navigationSort = 20;

    public function getPlaceholderDescription(): string
    {
        return 'Placeholder kanału sprzedaży WooCommerce. Operacje zapisu i synchronizacji WooCommerce są wyłączone i nie zostały wdrożone w tym etapie.';
    }
}
