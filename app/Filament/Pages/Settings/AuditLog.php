<?php

namespace App\Filament\Pages\Settings;

class AuditLog extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Audit Log';
    protected static ?string $title = 'Audit Log';
    protected static ?int $navigationSort = 132;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for configuration change history, operator audit visibility, and traceability. No audit storage is implemented here.';
    }
}
