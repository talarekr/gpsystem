<?php

namespace App\Filament\Pages\Settings;

class InternalLogisticsClasses extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Internal Logistics Classes';
    protected static ?string $title = 'Internal Logistics Classes';
    protected static ?int $navigationSort = 120;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for central internal logistics classes such as small, medium, large, oversize, and pallet. This is separate from channel-specific shipping groups.';
    }
}
