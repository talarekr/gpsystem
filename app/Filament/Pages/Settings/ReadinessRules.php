<?php

namespace App\Filament\Pages\Settings;

class ReadinessRules extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-check-circle';
    protected static ?string $navigationLabel = 'Reguły gotowości';
    protected static ?string $title = 'Reguły gotowości';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe reguły gotowości, definicje statusów i bramki publikacji. Wykonywanie reguł nie jest wdrożone.';
    }
}
