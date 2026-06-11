<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

abstract class OperationalPlaceholderPage extends Page
{
    protected static string $view = 'filament.pages.operational-placeholder';

    public function getPlaceholderEyebrow(): string
    {
        return 'GPS Product Hub';
    }

    public function getPlaceholderDescription(): string
    {
        return 'To miejsce jest przygotowane pod przyszły moduł operacyjny. Funkcjonalność zostanie wdrożona w osobnym etapie.';
    }

    /**
     * @return array<int, string>
     */
    public function getPlaceholderDetails(): array
    {
        return [
            'Ekran jest spokojnym, operacyjnym placeholderem dla zespołu magazynu i administracji.',
            'Nie uruchamia publikacji marketplace, zapisów do zewnętrznych API, ryzykownej automatyzacji ani danych produkcyjnych.',
        ];
    }
}
