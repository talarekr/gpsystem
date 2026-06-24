<?php

namespace App\Filament\Pages\Settings;

class AuditLog extends SettingsPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'Logowania';
    protected static ?string $title = 'Logowania';
    protected static ?int $navigationSort = 120;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłą widoczność logowań, zmian konfiguracyjnych i śladów audytowych operatorów. Przechowywanie audytu nie jest jeszcze wdrożone.';
    }
}
