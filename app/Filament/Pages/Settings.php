<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Settings\SettingsPlaceholderPage;

class Settings extends SettingsPlaceholderPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Ustawienia';
    protected static ?string $title = 'Ustawienia';
    protected static ?int $navigationSort = 110;

    public function getPlaceholderDescription(): string
    {
        return 'Główne miejsce przyszłych ustawień systemu, preferencji administracyjnych, języka personelu i bezpiecznych punktów konfiguracji. Flagi integracji pozostają domyślnie wyłączone.';
    }
}
