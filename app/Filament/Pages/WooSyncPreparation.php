<?php

namespace App\Filament\Pages;

class WooSyncPreparation extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationLabel = 'Woo Sync Preparation';
    protected static ?string $title = 'Woo Sync Preparation';
    protected static ?int $navigationSort = 80;

    public function getPlaceholderDescription(): string
    {
        return 'Placeholder only. Woo write operations are disabled by default and will require explicit approval in a later ticket.';
    }
}
