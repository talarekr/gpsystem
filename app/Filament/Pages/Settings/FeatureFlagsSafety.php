<?php

namespace App\Filament\Pages\Settings;

class FeatureFlagsSafety extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Feature Flags / Safety';
    protected static ?string $title = 'Feature Flags / Safety';
    protected static ?int $navigationSort = 131;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for safety switches, feature rollout flags, and integration locks. Risky integrations remain disabled by default.';
    }
}
