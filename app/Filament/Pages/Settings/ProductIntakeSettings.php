<?php

namespace App\Filament\Pages\Settings;

class ProductIntakeSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';
    protected static ?string $navigationLabel = 'Ustawienia przyjęcia części';
    protected static ?string $title = 'Ustawienia przyjęcia części';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe domyślne ustawienia przyjęcia, wskazówki operatora, oczekiwania zdjęciowe i bezpieczne reguły stagingu. Workflow przyjęcia nie jest wdrożony.';
    }
}
