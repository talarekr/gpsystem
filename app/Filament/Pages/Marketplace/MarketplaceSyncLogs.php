<?php

namespace App\Filament\Pages\Marketplace;

use App\Models\MarketplaceSyncLog;
use Filament\Pages\Page;

class MarketplaceSyncLogs extends Page
{
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Logi synchronizacji';
    protected static ?string $title = 'Logi synchronizacji marketplace';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.marketplace.sync-logs';

    public function logs()
    {
        return MarketplaceSyncLog::query()->where('marketplace', 'ovoko')->latest('created_at')->limit(50)->get();
    }
}
