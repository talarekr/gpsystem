<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Pages\OperationalPlaceholderPage;

abstract class SettingsPlaceholderPage extends OperationalPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;

    public function getPlaceholderEyebrow(): string
    {
        return 'Struktura ustawień';
    }

    public function getPlaceholderDetails(): array
    {
        return [
            'Cel: zarezerwować czytelne miejsce konfiguracji dla późniejszego etapu wdrożenia.',
            'Status: tylko placeholder — pola nie są zapisywane, a reguły biznesowe nie są wykonywane.',
            'Bezpieczeństwo: zapisy zewnętrzne, publikacja marketplace i ryzykowne integracje pozostają wyłączone.',
        ];
    }
}
