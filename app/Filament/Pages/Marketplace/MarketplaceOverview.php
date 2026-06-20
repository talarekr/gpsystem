<?php

namespace App\Filament\Pages\Marketplace;

use App\Models\MarketplaceListing;
use Filament\Pages\Page;

class MarketplaceOverview extends Page
{
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Przegląd';
    protected static ?string $title = 'Administracja marketplace';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.marketplace.overview';

    public function stats(): array
    {
        return ['ovoko' => MarketplaceListing::query()->where('marketplace', 'ovoko')->count(), 'mapped' => MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', 'mapped')->count(), 'unmatched' => MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', 'unmatched')->count(), 'conflict' => MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', 'conflict')->count()];
    }
}
