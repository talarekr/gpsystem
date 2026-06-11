<?php

namespace App\Filament\Pages;

class ProductCatalog extends OperationalPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel = 'Katalog części';
    protected static ?string $title = 'Katalog części';

    public function getPlaceholderDescription(): string
    {
        return 'Wcześniejszy placeholder Product Catalog został zmapowany do sekcji Części. Funkcjonalność katalogu produktów nie jest wdrożona.';
    }
}
