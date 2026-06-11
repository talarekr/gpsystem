<?php

namespace App\Filament\Pages\Settings;

class ChannelSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Channel Settings';
    protected static ?string $title = 'Channel Settings';
    protected static ?int $navigationSort = 121;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for high-level sales channel settings, readiness gates, and integration safety controls. All risky writes remain disabled.';
    }
}
