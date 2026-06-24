<?php

namespace App\Filament\Pages;

class ProductCommandCenter extends OperationalPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationLabel = 'Części';
    protected static ?string $title = 'Części';
    protected static ?int $navigationSort = 40;

    public function getPlaceholderDescription(): string
    {
        return 'Przyszłe centrum codziennej pracy z częściami: katalog, kolejki robocze, braki gotowości i szybka obsługa magazynowa. Na tym etapie to wyłącznie placeholder nawigacyjny.';
    }

    public function getPlaceholderDetails(): array
    {
        return [
            'Mapuje wcześniejsze pozycje: Product Catalog, Product Command Center, Staging Items oraz Mobile Intake.',
            'Nie dodaje katalogu produktów, workflow stagingu ani workflow przyjęcia mobilnego.',
            'Nie wykonuje publikacji marketplace, synchronizacji ani zapisów do zewnętrznych API.',
        ];
    }
}
