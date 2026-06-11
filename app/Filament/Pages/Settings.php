<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Settings\SettingsPlaceholderPage;

class Settings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'General Settings';
    protected static ?string $title = 'General Settings';
    protected static ?int $navigationSort = 111;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for global system defaults, staff locale, admin preferences, and safe configuration entry points. Future integration flags remain disabled by default.';
    }
}
