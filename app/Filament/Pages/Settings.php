<?php

namespace App\Filament\Pages;

class Settings extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $navigationGroup = 'Administration';
    protected static ?string $title = 'Settings';
    protected static ?int $navigationSort = 110;

    public function getPlaceholderDescription(): string
    {
        return 'Placeholder for safe configuration. Future integration flags remain disabled by default.';
    }
}
