<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Pages\OperationalPlaceholderPage;

abstract class SettingsPlaceholderPage extends OperationalPlaceholderPage
{
    protected static ?string $navigationGroup = 'Administration / Settings';

    public function getPlaceholderEyebrow(): string
    {
        return 'Settings structure';
    }

    public function getPlaceholderDetails(): array
    {
        return [
            'Purpose: reserve a clear configuration location for a later implementation ticket.',
            'Status: placeholder only — no fields are persisted and no business rules are executed.',
            'Safety: external writes, marketplace publishing, and risky integrations remain disabled.',
        ];
    }
}
