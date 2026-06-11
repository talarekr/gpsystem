<?php

namespace App\Filament\Pages\Settings;

class ReadinessRules extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-check-circle';
    protected static ?string $navigationLabel = 'Readiness Rules';
    protected static ?string $title = 'Readiness Rules';
    protected static ?int $navigationSort = 129;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for readiness rules, blocked/ready/draft/published status definitions, and publication gates. No rule execution is implemented here.';
    }
}
