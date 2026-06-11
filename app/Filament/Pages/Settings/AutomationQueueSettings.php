<?php

namespace App\Filament\Pages\Settings;

class AutomationQueueSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Automatyzacja i kolejki';
    protected static ?string $title = 'Automatyzacja i kolejki';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe limity automatyzacji, kontrolę kolejek, ponowienia i ustawienia bezpieczeństwa operatora. Automatyzacja nie jest wykonywana.';
    }
}
