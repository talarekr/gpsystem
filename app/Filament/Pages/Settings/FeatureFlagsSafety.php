<?php

namespace App\Filament\Pages\Settings;

class FeatureFlagsSafety extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Flagi funkcji i bezpieczeństwo';
    protected static ?string $title = 'Flagi funkcji i bezpieczeństwo';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe przełączniki bezpieczeństwa, flagi wdrożeniowe i blokady integracji. Ryzykowne integracje pozostają domyślnie wyłączone.';
    }
}
