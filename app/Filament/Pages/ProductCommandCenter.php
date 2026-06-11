<?php

namespace App\Filament\Pages;

class ProductCommandCenter extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-view-columns';
    protected static ?string $navigationLabel = 'Product Command Center';
    protected static ?string $title = 'Product Command Center';
    protected static ?int $navigationSort = 40;

    public function getPlaceholderDescription(): string
    {
        return 'Future operational command center for product attention queues, readiness blockers, and fast daily warehouse work.';
    }
}
