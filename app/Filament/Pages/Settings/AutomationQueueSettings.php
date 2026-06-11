<?php

namespace App\Filament\Pages\Settings;

class AutomationQueueSettings extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Automation / Queue Settings';
    protected static ?string $title = 'Automation / Queue Settings';
    protected static ?int $navigationSort = 130;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for automation limits, queue controls, retries, and operator safety settings. No automation execution is implemented here.';
    }
}
